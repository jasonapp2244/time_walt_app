<?php

namespace App\Console\Commands;

use App\Models\ClaritySnapshot;
use App\Services\Analytics\ClarityClient;
use App\Services\Analytics\ClarityQuotaExceeded;
use Illuminate\Console\Command;
use Throwable;

/**
 * Saves yesterday's Clarity metrics. Scheduled daily at 00:15 UTC
 * (routes/console.php): the API's numOfDays=1 means "the last 24 hours", so
 * that run covers the previous UTC day. Uses one API call per configured
 * dimension (4 by default, of Clarity's 10 per day).
 */
class SyncClarityInsights extends Command
{
    protected $signature = 'analytics:sync-clarity
        {--force : Fetch again even if yesterday is already stored}
        {--show : Print the raw JSON (to check Clarity field names)}';

    protected $description = 'Store yesterday\'s Microsoft Clarity metrics (the API keeps only 3 days)';

    public function handle(ClarityClient $clarity): int
    {
        if (! $clarity->isConfigured()) {
            $this->error('CLARITY_API_TOKEN is not set (Clarity → Settings → Data Export).');

            return self::FAILURE;
        }

        $day = now('UTC')->subDay()->toDateString();
        $ok = true;

        foreach ((array) config('timevault_analytics.clarity.dimensions') as $dimension) {
            $dimension = (string) $dimension;
            $label = $dimension === '' ? 'overall' : $dimension;

            $stored = ClaritySnapshot::query()
                ->whereDate('captured_on', $day)
                ->where('dimension', $dimension)
                ->exists();
            if ($stored && ! $this->option('force')) {
                $this->line("{$day} {$label}: already stored, skipped");

                continue;
            }

            try {
                $payload = $clarity->liveInsights(1, $dimension === '' ? null : $dimension);
            } catch (ClarityQuotaExceeded $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (Throwable $e) {
                report($e);
                $this->error("{$day} {$label}: ".class_basename($e).' '.$e->getMessage());
                $ok = false;

                continue;
            }

            ClaritySnapshot::query()->updateOrCreate(
                ['captured_on' => $day, 'dimension' => $dimension],
                ['payload' => $payload, 'fetched_at' => now()],
            );
            $this->info("{$day} {$label}: stored ".count($payload).' metric groups');

            if ($this->option('show')) {
                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
