<?php

namespace App\Services\Analytics;

/**
 * Reads Clarity Data Export payloads: a list of
 * `{"metricName": "...", "information": [ {row}, ... ]}` groups.
 *
 * Microsoft documents only the Traffic row fields (totalSessionCount,
 * totalBotSessionCount, distantUserCount, PagesPerSessionPercentage). The
 * other metrics' field names are matched against several likely spellings,
 * case-insensitively, and a missing field reads as null (shown as "–")
 * rather than an error. If a card stays empty, run
 * `php artisan analytics:sync-clarity --force --show` and add the real field
 * name to the candidate list below.
 */
class ClarityReport
{
    /** @return array<string, float|null> One day's headline numbers. */
    public static function summary(array $payload): array
    {
        $traffic = self::rows($payload, 'Traffic')[0] ?? [];
        $engagement = self::rows($payload, 'EngagementTime')[0] ?? [];
        $scroll = self::rows($payload, 'ScrollDepth')[0] ?? [];

        return [
            'sessions' => self::num($traffic, ['totalSessionCount', 'sessionsCount']),
            'bot_sessions' => self::num($traffic, ['totalBotSessionCount']),
            'users' => self::num($traffic, ['distantUserCount', 'distinctUserCount', 'userCount']),
            'screens_per_session' => self::num($traffic, ['PagesPerSessionPercentage', 'pagesPerSession']),
            'active_time' => self::num($engagement, ['activeTime', 'averageActiveTime']),
            'total_time' => self::num($engagement, ['totalTime', 'averageTotalTime']),
            'scroll_depth' => self::num($scroll, ['averageScrollDepth', 'scrollDepth']),
            'rage_taps_pct' => self::percent($payload, 'RageClickCount'),
            'dead_taps_pct' => self::percent($payload, 'DeadClickCount'),
            'quick_backs_pct' => self::percent($payload, 'QuickbackClick'),
            'error_taps_pct' => self::percent($payload, 'ErrorClickCount'),
            'app_errors_pct' => self::percent($payload, 'ScriptErrorCount'),
            'excessive_scroll_pct' => self::percent($payload, 'ExcessiveScroll'),
        ];
    }

    /** @return array<int, array{screen: string, visits: int}> Busiest screens first. */
    public static function popularScreens(array $payload): array
    {
        $out = [];
        foreach (self::rows($payload, 'PopularPages') as $row) {
            if (! is_array($row)) {
                continue;
            }
            $screen = self::str($row, ['url', 'name', 'pageTitle', 'screenName', 'page']);
            if ($screen === null) {
                continue;
            }
            $out[$screen] = ($out[$screen] ?? 0)
                + (int) (self::num($row, ['visitsCount', 'visits', 'sessionsCount', 'count']) ?? 0);
        }

        return self::ranked($out, 'screen', 'visits');
    }

    /**
     * Sessions per value of [$dimension], from a breakdown snapshot's Traffic
     * rows (each row carries the dimension value under the dimension's name).
     *
     * @return array<int, array{label: string, sessions: int}>
     */
    public static function breakdown(array $payload, string $dimension): array
    {
        $out = [];
        foreach (self::rows($payload, 'Traffic') as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = self::str($row, [$dimension, str_replace('/', '', $dimension), 'name']) ?? 'Unknown';
            $out[$label] = ($out[$label] ?? 0) + (int) (self::num($row, ['totalSessionCount']) ?? 0);
        }

        return self::ranked($out, 'label', 'sessions');
    }

    /** @return array<int, mixed> The rows of one metric group. */
    public static function rows(array $payload, string $metric): array
    {
        foreach ($payload as $group) {
            if (is_array($group) && self::key((string) ($group['metricName'] ?? '')) === self::key($metric)) {
                return is_array($group['information'] ?? null) ? array_values($group['information']) : [];
            }
        }

        return [];
    }

    /** Share of sessions (0–100) that had the frustration signal. */
    private static function percent(array $payload, string $metric): ?float
    {
        $row = self::rows($payload, $metric)[0] ?? [];

        return is_array($row) ? self::num($row, ['sessionsWithMetricPercentage', 'percentage', 'sessionsPercentage']) : null;
    }

    /** "Dead Click Count", "deadClickCount", "DeadClickCount" → "deadclickcount". */
    private static function key(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name));
    }

    /** First numeric field among [$candidates]; Clarity sends some numbers as strings. */
    private static function num(array $row, array $candidates): ?float
    {
        $byKey = self::byKey($row);
        foreach ($candidates as $candidate) {
            $value = $byKey[self::key($candidate)] ?? null;
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private static function str(array $row, array $candidates): ?string
    {
        $byKey = self::byKey($row);
        foreach ($candidates as $candidate) {
            $value = $byKey[self::key($candidate)] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function byKey(array $row): array
    {
        $byKey = [];
        foreach ($row as $k => $v) {
            $byKey[self::key((string) $k)] = $v;
        }

        return $byKey;
    }

    /** @param array<string, int> $counts */
    private static function ranked(array $counts, string $labelKey, string $countKey): array
    {
        arsort($counts);
        $out = [];
        foreach ($counts as $label => $count) {
            $out[] = [$labelKey => (string) $label, $countKey => (int) $count];
        }

        return $out;
    }
}
