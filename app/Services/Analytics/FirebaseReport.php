<?php

namespace App\Services\Analytics;

use Carbon\Carbon;
use Throwable;

/**
 * Firebase Analytics numbers for the admin dashboard, read from the linked
 * GA4 property. GA4 processes events with a delay of a few hours, so today's
 * figures fill in during the day.
 */
class FirebaseReport
{
    public function __construct(private Ga4Client $ga4) {}

    /** @return array<string, mixed> */
    public function build(int $days): array
    {
        $range = [['startDate' => ($days - 1).'daysAgo', 'endDate' => 'today']];
        $groups = (array) config('timevault_analytics.event_groups');
        $allEvents = array_merge(...array_values($groups));

        $overview = Ga4Client::rows($this->ga4->runReport([
            'dateRanges' => $range,
            'metrics' => self::names(['activeUsers', 'newUsers', 'sessions', 'averageSessionDuration', 'screenPageViews']),
        ]))[0] ?? [];

        $eventRows = Ga4Client::rows($this->ga4->runReport([
            'dateRanges' => $range,
            'dimensions' => self::names(['eventName']),
            'metrics' => self::names(['eventCount', 'totalUsers', 'eventValue']),
            'dimensionFilter' => self::inList('eventName', $allEvents),
            'limit' => 100,
        ]));
        $byEvent = [];
        foreach ($eventRows as $row) {
            $byEvent[$row['eventName']] = $row;
        }

        $dailyUsers = Ga4Client::rows($this->ga4->runReport([
            'dateRanges' => $range,
            'dimensions' => self::names(['date']),
            'metrics' => self::names(['activeUsers', 'newUsers']),
            'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
        ]));

        $trendEvents = ['sign_up', 'lock_created', 'withdraw_completed', 'purchase'];
        $dailyEvents = Ga4Client::rows($this->ga4->runReport([
            'dateRanges' => $range,
            'dimensions' => self::names(['date', 'eventName']),
            'metrics' => self::names(['eventCount']),
            'dimensionFilter' => self::inList('eventName', $trendEvents),
            'limit' => 1000,
        ]));

        $screens = Ga4Client::rows($this->ga4->runReport([
            'dateRanges' => $range,
            'dimensions' => self::names(['unifiedScreenName']),
            'metrics' => self::names(['screenPageViews', 'activeUsers']),
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit' => 20,
        ]));

        $platforms = Ga4Client::rows($this->ga4->runReport([
            'dateRanges' => $range,
            'dimensions' => self::names(['platform']),
            'metrics' => self::names(['activeUsers']),
        ]));

        return [
            'overview' => [
                'active_users' => (int) ($overview['activeUsers'] ?? 0),
                'new_users' => (int) ($overview['newUsers'] ?? 0),
                'sessions' => (int) ($overview['sessions'] ?? 0),
                'avg_session_seconds' => (float) ($overview['averageSessionDuration'] ?? 0),
                'screen_views' => (int) ($overview['screenPageViews'] ?? 0),
            ],
            'money' => $this->money($byEvent),
            'funnels' => $this->funnels($byEvent),
            'event_groups' => $this->eventGroups($groups, $byEvent),
            'daily' => $this->daily($days, $dailyUsers, $dailyEvents, $trendEvents),
            'screens' => array_map(static fn (array $r): array => [
                'screen' => $r['unifiedScreenName'] !== '' ? $r['unifiedScreenName'] : '(not set)',
                'views' => (int) $r['screenPageViews'],
                'users' => (int) $r['activeUsers'],
            ], $screens),
            'platforms' => array_map(static fn (array $r): array => [
                'label' => $r['platform'],
                'users' => (int) $r['activeUsers'],
            ], $platforms),
            'failure_reasons' => $this->failureReasons($range),
        ];
    }

    private function money(array $byEvent): array
    {
        $out = [];
        foreach ((array) config('timevault_analytics.money_events') as $label => $event) {
            $out[] = [
                'label' => $label,
                'event' => $event,
                'usd' => round((float) ($byEvent[$event]['eventValue'] ?? 0), 2),
                'count' => (int) ($byEvent[$event]['eventCount'] ?? 0),
            ];
        }

        return $out;
    }

    /** Step counts plus each step's share of the first step. */
    private function funnels(array $byEvent): array
    {
        $out = [];
        foreach ((array) config('timevault_analytics.funnels') as $name => $steps) {
            $first = (int) ($byEvent[$steps[0]]['eventCount'] ?? 0);
            $rows = [];
            foreach ($steps as $event) {
                $count = (int) ($byEvent[$event]['eventCount'] ?? 0);
                $rows[] = [
                    'event' => $event,
                    'count' => $count,
                    'pct' => $first > 0 ? round(100 * $count / $first, 1) : null,
                ];
            }
            $out[] = ['name' => $name, 'steps' => $rows];
        }

        return $out;
    }

    private function eventGroups(array $groups, array $byEvent): array
    {
        $out = [];
        foreach ($groups as $group => $events) {
            $out[] = [
                'group' => $group,
                'events' => array_map(static fn (string $e): array => [
                    'event' => $e,
                    'count' => (int) ($byEvent[$e]['eventCount'] ?? 0),
                    'users' => (int) ($byEvent[$e]['totalUsers'] ?? 0),
                ], $events),
            ];
        }

        return $out;
    }

    /** One entry per day of the range, zero-filled, oldest first. */
    private function daily(int $days, array $dailyUsers, array $dailyEvents, array $trendEvents): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->format('Ymd');
            $out[$date] = ['date' => Carbon::createFromFormat('Ymd', $date)->toDateString(), 'active_users' => 0, 'new_users' => 0]
                + array_fill_keys($trendEvents, 0);
        }
        foreach ($dailyUsers as $row) {
            if (isset($out[$row['date']])) {
                $out[$row['date']]['active_users'] = (int) $row['activeUsers'];
                $out[$row['date']]['new_users'] = (int) $row['newUsers'];
            }
        }
        foreach ($dailyEvents as $row) {
            if (isset($out[$row['date']])) {
                $out[$row['date']][$row['eventName']] = (int) $row['eventCount'];
            }
        }

        return array_values($out);
    }

    /**
     * Needs `reason` registered as an event-scoped custom dimension in GA4.
     * Returns null (section hidden) until then, or if GA4 rejects the query.
     */
    private function failureReasons(array $range): ?array
    {
        if (! config('timevault_analytics.ga4.reason_dimension')) {
            return null;
        }

        try {
            $rows = Ga4Client::rows($this->ga4->runReport([
                'dateRanges' => $range,
                'dimensions' => self::names(['eventName', 'customEvent:reason']),
                'metrics' => self::names(['eventCount']),
                'dimensionFilter' => self::inList('eventName', [
                    'sign_up_failed', 'login_failed', 'lock_failed', 'checkout_failed',
                    'payment_failed', 'withdraw_blocked', 'withdraw_failed', 'kyc_returned', 'otp_verification',
                ]),
                'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]],
                'limit' => 50,
            ]));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return array_values(array_filter(array_map(static fn (array $r): array => [
            'event' => $r['eventName'],
            'reason' => $r['customEvent:reason'],
            'count' => (int) $r['eventCount'],
        ], $rows), static fn (array $r): bool => $r['reason'] !== '' && $r['reason'] !== '(not set)'));
    }

    private static function names(array $names): array
    {
        return array_map(static fn (string $n): array => ['name' => $n], $names);
    }

    private static function inList(string $field, array $values): array
    {
        return ['filter' => ['fieldName' => $field, 'inListFilter' => ['values' => array_values($values)]]];
    }
}
