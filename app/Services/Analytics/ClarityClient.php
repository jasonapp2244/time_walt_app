<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft Clarity Data Export API.
 * https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-data-export-api
 *
 * Limits that shape everything here: 10 calls per project per UTC day, data
 * for the last 1–3 days only, max 1,000 rows, no pagination.
 */
class ClarityClient
{
    public function isConfigured(): bool
    {
        return filled(config('timevault_analytics.clarity.token'));
    }

    /**
     * Aggregated metrics for the last [$numOfDays] × 24 hours, optionally
     * broken down by one dimension (OS, Device, Country/Region, ...).
     *
     * @return array<int, array{metricName?: string, information?: array<int, array<string, mixed>>}>
     */
    public function liveInsights(int $numOfDays = 1, ?string $dimension = null): array
    {
        $this->spendBudget();

        $query = ['numOfDays' => max(1, min(3, $numOfDays))];
        if ($dimension !== null && $dimension !== '') {
            $query['dimension1'] = $dimension;
        }

        $response = Http::withToken((string) config('timevault_analytics.clarity.token'))
            ->acceptJson()
            ->timeout(30)
            ->get(config('timevault_analytics.clarity.endpoint'), $query);

        if ($response->status() === 429) {
            throw new ClarityQuotaExceeded('Clarity daily API limit reached (HTTP 429). Try again after 00:00 UTC.');
        }
        $response->throw();

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Clarity returned a non-JSON response.');
        }

        return $json;
    }

    /** Counts calls per UTC day so a loop or retry storm can't burn the quota. */
    private function spendBudget(): void
    {
        $budget = (int) config('timevault_analytics.clarity.daily_call_budget', 8);
        $key = 'tv-analytics:clarity-calls:'.now('UTC')->toDateString();
        $used = (int) Cache::get($key, 0);

        if ($used >= $budget) {
            throw new ClarityQuotaExceeded("Daily Clarity call budget ({$budget}) already used today (UTC).");
        }

        Cache::put($key, $used + 1, now('UTC')->endOfDay()->addHour());
    }
}
