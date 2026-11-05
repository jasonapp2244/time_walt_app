<?php

namespace App\Services\Analytics;

use App\Models\ClaritySnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything the admin analytics page shows, as one array (also served as
 * JSON). Each source reports its own status, so one being down or not yet
 * configured never blanks the other:
 *   ok | no_data | not_configured | error
 */
class AnalyticsDashboard
{
    public function __construct(
        private ClarityClient $clarity,
        private Ga4Client $ga4,
        private FirebaseReport $firebase,
    ) {}

    public function build(int $days): array
    {
        return [
            'days' => $days,
            'generated_at' => now()->toIso8601String(),
            'clarity' => $this->clarity($days),
            'firebase' => $this->firebase($days),
            'links' => [
                'clarity' => 'https://clarity.microsoft.com/projects/view/'
                    .config('timevault_analytics.clarity.project_id').'/dashboard',
                'firebase' => 'https://console.firebase.google.com/',
            ],
        ];
    }

    private function clarity(int $days): array
    {
        $since = now('UTC')->subDays($days)->toDateString();
        $snapshots = ClaritySnapshot::query()
            ->where('captured_on', '>=', $since)
            ->orderBy('captured_on')
            ->get();

        if ($snapshots->isEmpty()) {
            return ['status' => $this->clarity->isConfigured() ? 'no_data' : 'not_configured'];
        }

        $overall = $snapshots->where('dimension', '');
        $daily = [];
        $screens = [];
        foreach ($overall as $snap) {
            $summary = ClarityReport::summary($snap->payload ?? []);
            $daily[] = ['date' => $snap->captured_on->toDateString()] + $summary;
            foreach (ClarityReport::popularScreens($snap->payload ?? []) as $row) {
                $screens[$row['screen']] = ($screens[$row['screen']] ?? 0) + $row['visits'];
            }
        }
        arsort($screens);

        $breakdowns = [];
        foreach (['OS' => 'os', 'Device' => 'devices', 'Country/Region' => 'countries'] as $dimension => $key) {
            $totals = [];
            foreach ($snapshots->where('dimension', $dimension) as $snap) {
                foreach (ClarityReport::breakdown($snap->payload ?? [], $dimension) as $row) {
                    $totals[$row['label']] = ($totals[$row['label']] ?? 0) + $row['sessions'];
                }
            }
            arsort($totals);
            $breakdowns[$key] = self::pairs($totals, 'label', 'sessions');
        }

        return [
            'status' => 'ok',
            'last_synced_day' => $snapshots->max('captured_on')->toDateString(),
            'days_with_data' => count($daily),
            'totals' => self::totals($daily),
            'daily' => $daily,
            'screens' => array_slice(self::pairs($screens, 'screen', 'visits'), 0, 20),
        ] + $breakdowns;
    }

    private function firebase(int $days): array
    {
        if (! $this->ga4->isConfigured()) {
            return ['status' => 'not_configured'];
        }

        try {
            return Cache::remember(
                "tv-analytics:ga4:{$days}",
                now()->addMinutes((int) config('timevault_analytics.ga4.cache_minutes', 60)),
                fn (): array => ['status' => 'ok'] + $this->firebase->build($days),
            );
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => Str::limit(class_basename($e).': '.$e->getMessage(), 300)];
        }
    }

    /**
     * Range totals from daily summaries: sessions summed; rates and times
     * averaged weighted by sessions, so a busy day counts more than a quiet one.
     */
    private static function totals(array $daily): array
    {
        $sessions = array_sum(array_map(static fn (array $d): float => (float) ($d['sessions'] ?? 0), $daily));
        $weighted = static function (string $key) use ($daily): ?float {
            $sum = 0.0;
            $weight = 0.0;
            foreach ($daily as $d) {
                if ($d[$key] === null) {
                    continue;
                }
                $w = (float) ($d['sessions'] ?? 0);
                $sum += $d[$key] * $w;
                $weight += $w;
            }
            if ($weight > 0) {
                return round($sum / $weight, 2);
            }
            $values = array_filter(array_column($daily, $key), static fn ($v): bool => $v !== null);

            return $values ? round(array_sum($values) / count($values), 2) : null;
        };
        $users = array_filter(array_column($daily, 'users'), static fn ($v): bool => $v !== null);

        return [
            'sessions' => (int) $sessions,
            'avg_daily_users' => $users ? round(array_sum($users) / count($users), 1) : null,
            'screens_per_session' => $weighted('screens_per_session'),
            'active_time' => $weighted('active_time'),
            'total_time' => $weighted('total_time'),
            'rage_taps_pct' => $weighted('rage_taps_pct'),
            'dead_taps_pct' => $weighted('dead_taps_pct'),
            'quick_backs_pct' => $weighted('quick_backs_pct'),
            'error_taps_pct' => $weighted('error_taps_pct'),
            'app_errors_pct' => $weighted('app_errors_pct'),
        ];
    }

    private static function pairs(array $counts, string $labelKey, string $countKey): array
    {
        $out = [];
        foreach ($counts as $label => $count) {
            $out[] = [$labelKey => (string) $label, $countKey => (int) $count];
        }

        return $out;
    }
}
