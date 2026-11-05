@php
    $c = $data['clarity'];
    $f = $data['firebase'];
    $cOk = $c['status'] === 'ok';
    $fOk = $f['status'] === 'ok';

    $n = static fn ($v, int $dec = 0) => $v === null ? '–' : number_format((float) $v, $dec);
    $pct = static fn ($v) => $v === null ? '–' : number_format((float) $v, 1).'%';
    $usd = static fn ($v) => '$'.number_format((float) $v, 2);
    $dur = static function ($s) {
        if ($s === null) return '–';
        $s = (int) round((float) $s);
        return $s >= 60 ? intdiv($s, 60).'m '.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).'s' : $s.'s';
    };
    // "enter_amount" → "Enter amount", "lock_created" → "Lock created"
    $label = static fn (string $key) => ucfirst(str_replace(['_', '-'], ' ', $key));

    // Series colours, shared by the charts (JS) and the legends (HTML).
    $palette = ['#bd7e2e', '#2c7a7b', '#7b5ea7', '#5a6b82', '#c0392b', '#2f855a', '#d4963e', '#9aa3ae'];

    // Rough guides for a mobile finance app; the meter turns amber/red above them.
    $frictionGuides = [
        'rage_taps_pct' => ['Rage taps', 'Several fast taps on one spot: something looked tappable but did nothing', 2, 5],
        'dead_taps_pct' => ['Dead taps', 'A tap that produced no response', 5, 10],
        'quick_backs_pct' => ['Quick backs', 'Left a screen within seconds of opening it', 5, 10],
        'app_errors_pct' => ['App errors', 'Sessions with at least one app error', 1, 3],
    ];

    $trend = static function (array $daily, string $key): ?float {
        $values = array_values(array_filter(array_column($daily, $key), static fn ($v) => $v !== null));
        $count = count($values);
        if ($count < 2 || (float) $values[$count - 2] == 0.0) return null;
        return round(100 * ((float) $values[$count - 1] - (float) $values[$count - 2]) / (float) $values[$count - 2], 1);
    };

    $chartData = ['palette' => $palette, 'clarity' => null, 'firebase' => null];

    if ($cOk) {
        $chartData['clarity'] = [
            'labels' => array_column($c['daily'], 'date'),
            'sessions' => array_column($c['daily'], 'sessions'),
            'users' => array_column($c['daily'], 'users'),
            'screens_per_session' => array_column($c['daily'], 'screens_per_session'),
            'active_time' => array_column($c['daily'], 'active_time'),
            'friction' => collect($frictionGuides)->map(fn ($g, $key) => ['label' => $g[0], 'data' => array_column($c['daily'], $key)])->values(),
            'screens' => ['labels' => array_map($label, array_column(array_slice($c['screens'], 0, 8), 'screen')), 'data' => array_column(array_slice($c['screens'], 0, 8), 'visits')],
            'devices' => ['labels' => array_column(array_slice($c['devices'], 0, 6), 'label'), 'data' => array_column(array_slice($c['devices'], 0, 6), 'sessions')],
            'os' => ['labels' => array_column(array_slice($c['os'], 0, 6), 'label'), 'data' => array_column(array_slice($c['os'], 0, 6), 'sessions')],
            'countries' => ['labels' => array_column(array_slice($c['countries'], 0, 8), 'label'), 'data' => array_column(array_slice($c['countries'], 0, 8), 'sessions')],
        ];
    }

    if ($fOk) {
        $chartData['firebase'] = [
            'labels' => array_column($f['daily'], 'date'),
            'active_users' => array_column($f['daily'], 'active_users'),
            'new_users' => array_column($f['daily'], 'new_users'),
            'events' => [
                ['label' => 'Sign-ups', 'data' => array_column($f['daily'], 'sign_up')],
                ['label' => 'Locks', 'data' => array_column($f['daily'], 'lock_created')],
                ['label' => 'Withdrawals', 'data' => array_column($f['daily'], 'withdraw_completed')],
                ['label' => 'Purchases', 'data' => array_column($f['daily'], 'purchase')],
            ],
            'money' => collect($f['money'])->mapWithKeys(fn ($m) => [$m['event'] => array_column($f['daily'], $m['event'])]),
            'screens' => ['labels' => array_map($label, array_column(array_slice($f['screens'], 0, 8), 'screen')), 'data' => array_column(array_slice($f['screens'], 0, 8), 'views')],
            'platforms' => ['labels' => array_column($f['platforms'], 'label'), 'data' => array_column($f['platforms'], 'users')],
            'reasons' => is_array($f['failure_reasons'])
                ? ['labels' => array_column(array_slice($f['failure_reasons'], 0, 10), 'reason'), 'events' => array_map($label, array_column(array_slice($f['failure_reasons'], 0, 10), 'event')), 'data' => array_column(array_slice($f['failure_reasons'], 0, 10), 'count')]
                : null,
        ];
    }
@endphp

<style>
    .tva { --ink:#1f1d1a; --ink-2:#2b2824; --text:#1f2430; --muted:#6b7280; --line:rgba(189,126,46,.16); --soft:#f7f3ec;
        --gold:#bd7e2e; --gold-2:#d4963e; --gold-pale:#f5ead8; --good:#2f855a; --warn:#c98a12; --bad:#c0392b;
        color: var(--text); }
    .tva *, .tva *::before, .tva *::after { box-sizing: border-box; }
    .tva a { color: inherit; }
    .tva a:focus-visible, .tva button:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; border-radius: 6px; }

    /* page header */
    .tva-top { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; justify-content: space-between; margin-bottom: 8px; }
    .tva-range { display: inline-flex; background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 3px; }
    .tva-range a { padding: 6px 14px; border-radius: 7px; font-size: 13px; font-weight: 500; text-decoration: none; color: var(--muted); }
    .tva-range a.on { background: var(--gold); color: #fff; }
    .tva-range a:not(.on):hover { color: var(--text); background: var(--soft); }

    /* source header */
    .tva-source { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 16px; margin: 32px 0 12px; }
    .tva-source h2 { font-size: 19px; font-weight: 700; margin: 0; color: #111827; }
    .tva-source h2 small { font-size: 13px; font-weight: 500; color: var(--muted); margin-left: 6px; }
    .tva-source p { margin: 2px 0 0; font-size: 13px; color: var(--muted); }
    .tva-ext { font-size: 13px; font-weight: 500; color: var(--gold) !important; text-decoration: none; white-space: nowrap; }
    .tva-ext:hover { text-decoration: underline; }

    /* the one bold element: a dark vault hero, chart first */
    .tva-hero { display: grid; grid-template-columns: minmax(220px, 280px) 1fr; gap: 8px 28px; background: var(--ink);
        background-image: radial-gradient(120% 140% at 100% 0%, #3a2f20 0%, var(--ink) 55%);
        color: #f3ede3; border-radius: 16px; padding: 22px 24px; }
    .tva-hero .lead { font-size: 13px; color: #c9bba4; }
    .tva-hero .big { font-size: 46px; line-height: 1.05; font-weight: 700; letter-spacing: -.01em; color: #fff; margin: 4px 0 2px; font-variant-numeric: tabular-nums; }
    .tva-hero .chip { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 99px; background: rgba(255,255,255,.08); color: #e9dcc6; }
    .tva-hero .chip.up { color: #9ee2b8; } .tva-hero .chip.down { color: #f5a99f; }
    .tva-hero dl { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; margin: 20px 0 0; }
    .tva-hero dt { font-size: 12px; font-weight: 400; color: #b5a88f; }
    .tva-hero dd { margin: 2px 0 0; font-size: 18px; font-weight: 600; color: #fff; font-variant-numeric: tabular-nums; }
    .tva-hero .plot { position: relative; height: 250px; min-width: 0; }

    /* cards */
    .tva-grid { display: grid; gap: 14px; margin-top: 14px; }
    .tva-grid.k4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .tva-grid.k3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .tva-grid.c2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .tva-grid.c3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .tva-grid.wide { grid-template-columns: 1.4fr 1fr; }
    .tva-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 18px 20px; min-width: 0; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
    .tva-card h3 { font-size: 15px; font-weight: 600; margin: 0; color: #111827; }
    .tva-card .hint { font-size: 12px; color: var(--muted); margin: 2px 0 14px; }
    .tva-kpi .k { font-size: 13px; color: var(--muted); }
    .tva-kpi .v { font-size: 26px; font-weight: 700; margin-top: 2px; font-variant-numeric: tabular-nums; color: #111827; }
    .tva-kpi .s { font-size: 12px; color: var(--muted); }
    .tva-kpi .spark { position: relative; height: 44px; margin-top: 8px; }
    .tva-money { border-top: 3px solid var(--gold); }
    .tva-plot { position: relative; height: 240px; }
    .tva-plot.short { height: 200px; }
    .tva-fill { display: flex; flex-direction: column; }
    .tva-fill .tva-plot { flex: 1; min-height: 240px; }

    /* friction meters */
    .tva-meter { padding: 10px 0; border-bottom: 1px solid #f1ece3; }
    .tva-meter:last-child { border-bottom: 0; }
    .tva-meter .row { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
    .tva-meter .name { font-size: 14px; font-weight: 600; }
    .tva-meter .val { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .tva-meter .why { font-size: 12px; color: var(--muted); margin: 1px 0 8px; }
    .tva-meter .bar { position: relative; height: 8px; border-radius: 99px; background: #f1ece3; overflow: hidden; }
    .tva-meter .bar i { position: absolute; inset: 0 auto 0 0; border-radius: 99px; }
    .tva-meter .ok i { background: var(--good); } .tva-meter .warn i { background: var(--warn); } .tva-meter .bad i { background: var(--bad); }
    .tva-meter .ok .val { color: var(--good); } .tva-meter .warn .val { color: var(--warn); } .tva-meter .bad .val { color: var(--bad); }

    /* legends next to doughnuts */
    .tva-split { display: grid; grid-template-columns: 150px 1fr; gap: 16px; align-items: center; }
    .tva-split .donut { position: relative; height: 150px; }
    .tva-legend { list-style: none; margin: 0; padding: 0; font-size: 13px; }
    .tva-legend li { display: flex; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px solid #f4f0e9; }
    .tva-legend li:last-child { border-bottom: 0; }
    .tva-legend .sw { width: 10px; height: 10px; border-radius: 3px; flex: none; }
    .tva-legend .lb { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .tva-legend .nm { font-variant-numeric: tabular-nums; font-weight: 600; }
    .tva-legend .pc { font-variant-numeric: tabular-nums; color: var(--muted); width: 46px; text-align: right; }

    /* funnels */
    .tva-funnel .step { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; align-items: center; margin-top: 10px; }
    .tva-funnel .step .nm { font-size: 13px; font-weight: 500; }
    .tva-funnel .step .ct { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .tva-funnel .shape { grid-column: 1 / -1; height: 26px; background: #f4efe6; border-radius: 6px; overflow: hidden; }
    .tva-funnel .shape i { display: block; height: 100%; background: linear-gradient(90deg, var(--gold), var(--gold-2)); border-radius: 6px; min-width: 4px; }
    .tva-funnel .drop { grid-column: 1 / -1; font-size: 12px; color: var(--muted); padding-left: 2px; }
    .tva-funnel .drop b { color: var(--bad); font-weight: 600; }
    .tva-funnel .conv { margin-top: 14px; padding-top: 10px; border-top: 1px dashed var(--line); font-size: 13px; color: var(--muted); }
    .tva-funnel .conv b { color: #111827; font-size: 16px; }

    /* event tables */
    .tva table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .tva th { text-align: left; color: var(--muted); font-weight: 500; padding: 6px 4px; border-bottom: 1px solid var(--line); }
    .tva td { padding: 7px 4px; border-bottom: 1px solid #f4f0e9; font-variant-numeric: tabular-nums; vertical-align: middle; }
    .tva tr:last-child td { border-bottom: 0; }
    .tva td.r, .tva th.r { text-align: right; }
    .tva td .ev { display: block; overflow-wrap: anywhere; }
    .tva td .mini { display: block; height: 4px; margin-top: 4px; border-radius: 99px; background: var(--gold); opacity: .8; }
    .tva td.bad .ev { color: var(--bad); font-weight: 600; } .tva td.bad .mini { background: var(--bad); }

    /* setup and empty states */
    .tva-setup { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 20px; align-items: start;
        background: #fff; border: 1px dashed rgba(189,126,46,.45); border-radius: 14px; padding: 20px 22px; }
    .tva-setup h3 { font-size: 16px; font-weight: 600; margin: 0 0 4px; }
    .tva-setup p { font-size: 13px; color: var(--muted); margin: 0; }
    .tva-setup ol { margin: 0; padding-left: 20px; font-size: 13px; line-height: 1.6; }
    .tva-setup li + li { margin-top: 6px; }
    .tva-setup.err { border: 1px solid rgba(192,57,43,.35); background: #fdf3f1; }
    .tva code { background: var(--gold-pale); color: #6b4a1c; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
    .tva .empty { font-size: 13px; color: var(--muted); padding: 24px 0; text-align: center; }

    @media (max-width: 1199.98px) {
        .tva-grid.k4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .tva-grid.c3, .tva-grid.k3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .tva-grid.wide { grid-template-columns: 1fr; }
    }
    @media (max-width: 767.98px) {
        .tva-hero { grid-template-columns: 1fr; padding: 18px; }
        .tva-hero .big { font-size: 38px; }
        .tva-hero .plot { height: 210px; }
        .tva-grid.c2, .tva-grid.c3, .tva-grid.k3, .tva-setup { grid-template-columns: 1fr; }
        .tva-split { grid-template-columns: 120px 1fr; }
        .tva-split .donut { height: 120px; }
    }
    @media (max-width: 420px) {
        .tva-grid.k4 { grid-template-columns: 1fr 1fr; gap: 10px; }
        .tva-card { padding: 14px; }
        .tva-kpi .v { font-size: 22px; }
    }
</style>

<div class="tva">
    <div class="tva-top">
        <div>
            <h4 class="section-heading">App Analytics</h4>
            <p class="section-sub mb-0">How the TimeVault app is used over the last {{ $data['days'] }} days. Updated {{ \Illuminate\Support\Carbon::parse($data['generated_at'])->diffForHumans() }}.</p>
        </div>
        <nav class="tva-range" aria-label="Date range">
            @foreach ($ranges as $r)
                <a href="{{ route('admin.analytics', ['days' => $r]) }}" class="{{ $r === $data['days'] ? 'on' : '' }}" @if ($r === $data['days']) aria-current="page" @endif>{{ $r }} days</a>
            @endforeach
        </nav>
    </div>

    {{-- ═══ Microsoft Clarity: user behaviour ═══════════════════════════ --}}
    <div class="tva-source">
        <div>
            <h2>User behaviour <small>from Microsoft Clarity</small></h2>
            <p>Sessions, where people tap, and where they get stuck.</p>
        </div>
        <a class="tva-ext" href="{{ $data['links']['clarity'] }}" target="_blank" rel="noopener">Watch recordings and heatmaps in Clarity ↗</a>
    </div>

    @if ($c['status'] === 'not_configured')
        <div class="tva-setup">
            <div>
                <h3>Connect Clarity to see behaviour data</h3>
                <p>The app already records sessions in Clarity project <code>{{ config('timevault_analytics.clarity.project_id') }}</code>. This page needs a read token to bring the numbers in.</p>
            </div>
            <ol>
                <li>In Clarity, open TimeVault Wallet → Settings → Data Export → <b>Generate new API token</b>.</li>
                <li>Add it to <code>.env</code> as <code>CLARITY_API_TOKEN</code> and run <code>php artisan config:cache</code>.</li>
                <li>Run <code>php artisan analytics:sync-clarity</code> once. After that it runs every day at 00:15 UTC.</li>
            </ol>
        </div>
    @elseif ($c['status'] === 'no_data')
        <div class="tva-setup">
            <div>
                <h3>Clarity is connected. No days are stored yet.</h3>
                <p>Clarity's API only returns the last 3 days, so history builds up from the first sync.</p>
            </div>
            <ol>
                <li>Run <code>php artisan analytics:sync-clarity</code> to fetch yesterday now, or wait for the 00:15 UTC run.</li>
                <li>Reload this page.</li>
            </ol>
        </div>
    @else
        @php
            $t = $c['totals'];
            $sessionTrend = $trend($c['daily'], 'sessions');
        @endphp

        <section class="tva-hero" aria-label="Clarity sessions">
            <div>
                <div class="lead">Sessions in {{ $c['days_with_data'] }} {{ \Illuminate\Support\Str::plural('day', $c['days_with_data']) }} of data</div>
                <div class="big">{{ $n($t['sessions']) }}</div>
                @if ($sessionTrend !== null)
                    <span class="chip {{ $sessionTrend >= 0 ? 'up' : 'down' }}">{{ $sessionTrend >= 0 ? '▲' : '▼' }} {{ abs($sessionTrend) }}% on the day before</span>
                @endif
                <dl>
                    <div><dt>Avg daily users</dt><dd>{{ $n($t['avg_daily_users'], 1) }}</dd></div>
                    <div><dt>Latest day (UTC)</dt><dd>{{ \Illuminate\Support\Carbon::parse($c['last_synced_day'])->format('M j') }}</dd></div>
                    <div><dt>Screens per session</dt><dd>{{ $n($t['screens_per_session'], 1) }}</dd></div>
                    <div><dt>Active time</dt><dd>{{ $dur($t['active_time']) }}</dd></div>
                </dl>
            </div>
            <div class="plot"><canvas id="tva-c-hero" role="img" aria-label="Sessions and users per day"></canvas></div>
        </section>

        <div class="tva-grid k4">
            @foreach ([
                ['Avg daily users', $n($t['avg_daily_users'], 1), 'Distinct users per day', 'tva-c-sp-users'],
                ['Screens per session', $n($t['screens_per_session'], 1), 'Screens opened per visit', 'tva-c-sp-screens'],
                ['Active time', $dur($t['active_time']), 'of '.$dur($t['total_time']).' in the app', 'tva-c-sp-active'],
                ['Sessions', $n($t['sessions']), 'All sessions in the range', 'tva-c-sp-sessions'],
            ] as [$k, $v, $s, $id])
                <div class="tva-card tva-kpi">
                    <div class="k">{{ $k }}</div>
                    <div class="v">{{ $v }}</div>
                    <div class="s">{{ $s }}</div>
                    <div class="spark"><canvas id="{{ $id }}" role="img" aria-label="{{ $k }} per day"></canvas></div>
                </div>
            @endforeach
        </div>

        <div class="tva-grid wide">
            <div class="tva-card tva-fill">
                <h3>Where people get stuck</h3>
                <p class="hint">Share of sessions with each frustration signal, per day.</p>
                <div class="tva-plot"><canvas id="tva-c-friction" role="img" aria-label="Frustration signals per day"></canvas></div>
            </div>
            <div class="tva-card">
                <h3>Frustration signals</h3>
                <p class="hint">Green is healthy, amber needs a look, red needs fixing.</p>
                @foreach ($frictionGuides as $key => [$name, $why, $okBelow, $warnBelow])
                    @php
                        $v = $t[$key];
                        $state = $v === null ? '' : ($v < $okBelow ? 'ok' : ($v < $warnBelow ? 'warn' : 'bad'));
                        $width = $v === null ? 0 : min(100, 100 * $v / max($warnBelow * 2, $v));
                    @endphp
                    <div class="tva-meter">
                        <div class="{{ $state }}">
                            <div class="row"><span class="name">{{ $name }}</span><span class="val">{{ $pct($v) }}</span></div>
                            <div class="why">{{ $why }}</div>
                            <div class="bar" role="img" aria-label="{{ $name }} {{ $pct($v) }} of sessions"><i style="width: {{ $width }}%"></i></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="tva-grid c2">
            <div class="tva-card">
                <h3>Popular screens</h3>
                <p class="hint">Visits per screen in the range.</p>
                @if ($c['screens'])
                    <div class="tva-plot"><canvas id="tva-c-screens" role="img" aria-label="Visits per screen"></canvas></div>
                @else
                    <div class="empty">Clarity hasn't reported screen names yet.</div>
                @endif
            </div>
            <div class="tva-card">
                <h3>Countries</h3>
                <p class="hint">Sessions by country.</p>
                @if ($c['countries'])
                    <div class="tva-plot"><canvas id="tva-c-countries" role="img" aria-label="Sessions per country"></canvas></div>
                @else
                    <div class="empty">No country breakdown stored yet.</div>
                @endif
            </div>
        </div>

        <div class="tva-grid c2">
            @foreach (['devices' => ['Devices', 'Phone models people use.'], 'os' => ['Operating systems', 'iOS and Android versions.']] as $key => [$title, $hint])
                @php $total = array_sum(array_column($c[$key], 'sessions')); @endphp
                <div class="tva-card">
                    <h3>{{ $title }}</h3>
                    <p class="hint">{{ $hint }}</p>
                    @if ($c[$key])
                        <div class="tva-split">
                            <div class="donut"><canvas id="tva-c-{{ $key }}" role="img" aria-label="Sessions by {{ strtolower($title) }}"></canvas></div>
                            <ul class="tva-legend">
                                @foreach (array_slice($c[$key], 0, 6) as $i => $row)
                                    <li>
                                        <span class="sw" style="background: {{ $palette[$i % count($palette)] }}"></span>
                                        <span class="lb">{{ $row['label'] }}</span>
                                        <span class="nm">{{ $n($row['sessions']) }}</span>
                                        <span class="pc">{{ $total > 0 ? round(100 * $row['sessions'] / $total) : 0 }}%</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <div class="empty">No {{ strtolower($title) }} breakdown stored yet.</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- ═══ Firebase Analytics: business events ═════════════════════════ --}}
    <div class="tva-source">
        <div>
            <h2>Business events <small>from Firebase Analytics</small></h2>
            <p>Sign-ups, locks, withdrawals and purchases. Today's numbers fill in over a few hours.</p>
        </div>
        <a class="tva-ext" href="{{ $data['links']['firebase'] }}" target="_blank" rel="noopener">Open the Firebase console ↗</a>
    </div>

    @if ($f['status'] === 'not_configured')
        <div class="tva-setup">
            <div>
                <h3>Connect Firebase Analytics to see business events</h3>
                <p>The app sends 23 events to Firebase project <code>time-vault-cbd41</code>. They are only collected once Google Analytics is linked to that project.</p>
            </div>
            <ol>
                <li>Firebase → Project settings → Integrations → Google Analytics → <b>Enable</b>, and copy the GA4 property ID into <code>GA4_PROPERTY_ID</code>.</li>
                <li>In Google Cloud, enable the Google Analytics Data API and create a service account. In GA4, give its email <b>Viewer</b> access.</li>
                <li>Save its JSON key as <code>storage/app/private/ga4-service-account.json</code> (or set <code>GA4_CREDENTIALS_PATH</code>), then run <code>php artisan config:cache</code>.</li>
            </ol>
        </div>
    @elseif ($f['status'] === 'error')
        <div class="tva-setup err">
            <div>
                <h3>Firebase Analytics didn't load</h3>
                <p>{{ $f['message'] }}</p>
            </div>
            <ol>
                <li>A 403 means the service account isn't a Viewer on the GA4 property yet.</li>
                <li>A 404 or "not found" means <code>GA4_PROPERTY_ID</code> is wrong.</li>
                <li>Results are cached for {{ (int) config('timevault_analytics.ga4.cache_minutes', 60) }} minutes, so the page retries after that.</li>
            </ol>
        </div>
    @else
        @php
            $o = $f['overview'];
            $userTrend = $trend($f['daily'], 'active_users');
        @endphp

        <section class="tva-hero" aria-label="Firebase active users">
            <div>
                <div class="lead">Active users in the last {{ $data['days'] }} days</div>
                <div class="big">{{ $n($o['active_users']) }}</div>
                @if ($userTrend !== null)
                    <span class="chip {{ $userTrend >= 0 ? 'up' : 'down' }}">{{ $userTrend >= 0 ? '▲' : '▼' }} {{ abs($userTrend) }}% on the day before</span>
                @endif
                <dl>
                    <div><dt>New users</dt><dd>{{ $n($o['new_users']) }}</dd></div>
                    <div><dt>Sessions</dt><dd>{{ $n($o['sessions']) }}</dd></div>
                    <div><dt>Avg session</dt><dd>{{ $dur($o['avg_session_seconds']) }}</dd></div>
                    <div><dt>Screen views</dt><dd>{{ $n($o['screen_views']) }}</dd></div>
                </dl>
            </div>
            <div class="plot"><canvas id="tva-f-hero" role="img" aria-label="Active and new users per day"></canvas></div>
        </section>

        <div class="tva-grid k3">
            @foreach ($f['money'] as $m)
                <div class="tva-card tva-kpi tva-money">
                    <div class="k">{{ $m['label'] }}</div>
                    <div class="v">{{ $usd($m['usd']) }}</div>
                    <div class="s">{{ $n($m['count']) }} × {{ $label($m['event']) }}. For trends, not accounting.</div>
                    <div class="spark"><canvas id="tva-f-money-{{ $m['event'] }}" data-event="{{ $m['event'] }}" class="tva-money-spark" role="img" aria-label="{{ $label($m['event']) }} per day"></canvas></div>
                </div>
            @endforeach
        </div>

        <div class="tva-card" style="margin-top: 14px">
            <h3>Key actions per day</h3>
            <p class="hint">Sign-ups, completed locks, withdrawals and purchases.</p>
            <div class="tva-plot"><canvas id="tva-f-events" role="img" aria-label="Key actions per day"></canvas></div>
        </div>

        <div class="tva-grid k4">
            @foreach ($f['funnels'] as $funnel)
                @php
                    $first = $funnel['steps'][0]['count'];
                    $last = end($funnel['steps'])['count'];
                @endphp
                <div class="tva-card tva-funnel">
                    <h3>{{ $funnel['name'] }} funnel</h3>
                    @foreach ($funnel['steps'] as $i => $step)
                        @if ($i > 0)
                            @php $prev = $funnel['steps'][$i - 1]['count']; @endphp
                            <div class="step"><div class="drop">
                                @if ($prev > 0)
                                    <b>−{{ max(0, round(100 * ($prev - $step['count']) / $prev)) }}%</b> dropped off
                                @else
                                    No one reached the previous step
                                @endif
                            </div></div>
                        @endif
                        <div class="step">
                            <span class="nm">{{ $label($step['event']) }}</span>
                            <span class="ct">{{ $n($step['count']) }}</span>
                            <div class="shape"><i style="width: {{ $first > 0 ? min(100, max(2, 100 * $step['count'] / $first)) : 0 }}%"></i></div>
                        </div>
                    @endforeach
                    <div class="conv">Completed <b>{{ $first > 0 ? round(100 * $last / $first, 1).'%' : '–' }}</b> of starts</div>
                </div>
            @endforeach
        </div>

        <div class="tva-grid c3">
            @foreach ($f['event_groups'] as $group)
                @php $max = max(1, ...array_column($group['events'], 'count')); @endphp
                <div class="tva-card">
                    <h3>{{ $group['group'] }}</h3>
                    <p class="hint">Event count and the users who triggered it.</p>
                    <table>
                        <tr><th>Event</th><th class="r">Count</th><th class="r">Users</th></tr>
                        @foreach ($group['events'] as $e)
                            @php $isProblem = \Illuminate\Support\Str::contains($e['event'], ['failed', 'blocked', 'cancelled']) && $e['count'] > 0; @endphp
                            <tr>
                                <td class="{{ $isProblem ? 'bad' : '' }}">
                                    <span class="ev">{{ $e['event'] }}</span>
                                    <span class="mini" style="width: {{ 100 * $e['count'] / $max }}%"></span>
                                </td>
                                <td class="r">{{ $n($e['count']) }}</td>
                                <td class="r">{{ $n($e['users']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endforeach
        </div>

        <div class="tva-grid {{ is_array($f['failure_reasons']) ? 'c3' : 'c2' }}">
            <div class="tva-card">
                <h3>Top screens</h3>
                <p class="hint">Screen views logged by the app.</p>
                @if ($f['screens'])
                    <div class="tva-plot"><canvas id="tva-f-screens" role="img" aria-label="Views per screen"></canvas></div>
                @else
                    <div class="empty">No screen views yet.</div>
                @endif
            </div>
            <div class="tva-card">
                <h3>Platforms</h3>
                <p class="hint">Active users on iOS and Android.</p>
                @if ($f['platforms'])
                    @php $total = array_sum(array_column($f['platforms'], 'users')); @endphp
                    <div class="tva-split">
                        <div class="donut"><canvas id="tva-f-platforms" role="img" aria-label="Active users by platform"></canvas></div>
                        <ul class="tva-legend">
                            @foreach ($f['platforms'] as $i => $row)
                                <li>
                                    <span class="sw" style="background: {{ $palette[$i % count($palette)] }}"></span>
                                    <span class="lb">{{ $row['label'] }}</span>
                                    <span class="nm">{{ $n($row['users']) }}</span>
                                    <span class="pc">{{ $total > 0 ? round(100 * $row['users'] / $total) : 0 }}%</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="empty">No platform data yet.</div>
                @endif
            </div>
            @if (is_array($f['failure_reasons']))
                <div class="tva-card">
                    <h3>Why things fail</h3>
                    <p class="hint">The reason sent with each failure event.</p>
                    @if ($f['failure_reasons'])
                        <div class="tva-plot"><canvas id="tva-f-reasons" role="img" aria-label="Failures by reason"></canvas></div>
                    @else
                        <div class="empty">No failures in this range.</div>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>

@if ($cOk || $fOk)
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
            integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4"
            crossorigin="anonymous"></script>
    <script>
        (function () {
            if (!window.Chart) return;
            var D = @json($chartData);
            var P = D.palette;
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
            Chart.defaults.font.size = 12;
            Chart.defaults.color = '#6b7280';
            if (reduce) { Chart.defaults.animation = false; } else { Chart.defaults.animation.duration = 600; }
            Chart.defaults.plugins.legend.labels.boxWidth = 10;
            Chart.defaults.plugins.legend.labels.boxHeight = 10;
            Chart.defaults.plugins.tooltip.backgroundColor = '#1f1d1a';
            Chart.defaults.plugins.tooltip.padding = 10;

            var short = function (d) { var p = String(d).split('-'); return p.length === 3 ? ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][+p[1] - 1] + ' ' + (+p[2]) : d; };
            var rgba = function (hex, a) { var n = parseInt(hex.slice(1), 16); return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')'; };
            var fade = function (hex, top) {
                return function (ctx) {
                    var area = ctx.chart.chartArea;
                    if (!area) return rgba(hex, top);
                    var g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                    g.addColorStop(0, rgba(hex, top)); g.addColorStop(1, rgba(hex, 0));
                    return g;
                };
            };
            var el = function (id) { return document.getElementById(id); };
            var make = function (id, cfg) { var c = el(id); if (c) new Chart(c, cfg); };

            // Dark hero: area for the main series, a line for the second.
            var hero = function (id, labels, main, mainLabel, second, secondLabel) {
                make(id, { type: 'line', data: { labels: labels.map(short), datasets: [
                    { label: mainLabel, data: main, borderColor: '#d4963e', backgroundColor: fade('#d4963e', .45), fill: true, tension: .35, borderWidth: 2.5, pointRadius: labels.length > 31 ? 0 : 3, pointBackgroundColor: '#d4963e' },
                    { label: secondLabel, data: second, borderColor: '#7fc8c2', backgroundColor: '#7fc8c2', fill: false, tension: .35, borderWidth: 2, borderDash: [5, 4], pointRadius: 0 }
                ] }, options: {
                    responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top', align: 'end', labels: { color: '#e9dcc6' } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#b5a88f', maxRotation: 0, autoSkipPadding: 16 } },
                        y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.07)' }, border: { display: false }, ticks: { color: '#b5a88f', precision: 0 } }
                    }
                } });
            };

            var spark = function (id, labels, values, color, type) {
                make(id, { type: type || 'line', data: { labels: labels.map(short), datasets: [{
                    data: values, borderColor: color, backgroundColor: type === 'bar' ? rgba(color, .75) : fade(color, .3),
                    fill: true, tension: .4, borderWidth: 2, pointRadius: 0, borderRadius: 3, maxBarThickness: 14
                }] }, options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    plugins: { legend: { display: false }, tooltip: { displayColors: false, intersect: false, mode: 'index' } },
                    scales: { x: { display: false }, y: { display: false, beginAtZero: true } }
                } });
            };

            var hbar = function (id, set, color) {
                if (!set || !set.data.length) return;
                make(id, { type: 'bar', data: { labels: set.labels, datasets: [{ data: set.data, backgroundColor: rgba(color, .85), hoverBackgroundColor: color, borderRadius: 6, borderSkipped: false, maxBarThickness: 22 }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip: { displayColors: false, callbacks: { title: function (items) { var i = items[0].dataIndex; return set.events ? set.events[i] + ': ' + set.labels[i] : set.labels[i]; } } } },
                        scales: { x: { beginAtZero: true, grid: { color: '#f1ece3' }, border: { display: false }, ticks: { precision: 0, maxRotation: 0, autoSkipPadding: 12 } }, y: { grid: { display: false }, ticks: { color: '#1f2430' } } } } });
            };

            var donut = function (id, set) {
                if (!set || !set.data.length) return;
                make(id, { type: 'doughnut', data: { labels: set.labels, datasets: [{ data: set.data, backgroundColor: set.data.map(function (_, i) { return P[i % P.length]; }), borderColor: '#fff', borderWidth: 2, hoverOffset: 6 }] },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false } } } });
            };

            var C = D.clarity;
            if (C) {
                hero('tva-c-hero', C.labels, C.sessions, 'Sessions', C.users, 'Users');
                spark('tva-c-sp-users', C.labels, C.users, P[1]);
                spark('tva-c-sp-screens', C.labels, C.screens_per_session, P[2]);
                spark('tva-c-sp-active', C.labels, C.active_time, P[3]);
                spark('tva-c-sp-sessions', C.labels, C.sessions, P[0], 'bar');
                var fc = ['#c0392b', '#c98a12', '#7b5ea7', '#5a6b82'];
                make('tva-c-friction', { type: 'line', data: { labels: C.labels.map(short), datasets: C.friction.map(function (s, i) {
                    return { label: s.label, data: s.data, borderColor: fc[i], backgroundColor: fc[i], tension: .35, borderWidth: 2, pointRadius: C.labels.length > 31 ? 0 : 3, spanGaps: true };
                }) }, options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + (c.parsed.y === null ? '–' : c.parsed.y.toFixed(1) + '%'); } } } },
                    scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 16 } }, y: { beginAtZero: true, grid: { color: '#f1ece3' }, border: { display: false }, ticks: { callback: function (v) { return v + '%'; } } } } } });
                hbar('tva-c-screens', C.screens, P[0]);
                hbar('tva-c-countries', C.countries, P[1]);
                donut('tva-c-devices', C.devices);
                donut('tva-c-os', C.os);
            }

            var F = D.firebase;
            if (F) {
                hero('tva-f-hero', F.labels, F.active_users, 'Active users', F.new_users, 'New users');
                document.querySelectorAll('.tva-money-spark').forEach(function (c) {
                    spark(c.id, F.labels, F.money[c.dataset.event] || [], P[0], 'bar');
                });
                make('tva-f-events', { type: 'bar', data: { labels: F.labels.map(short), datasets: F.events.map(function (s, i) {
                    return { label: s.label, data: s.data, backgroundColor: [P[2], P[0], P[5], P[1]][i], borderRadius: 4, maxBarThickness: 18 };
                }) }, options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom' } },
                    scales: { x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 16 } }, y: { stacked: true, beginAtZero: true, grid: { color: '#f1ece3' }, border: { display: false }, ticks: { precision: 0 } } } } });
                hbar('tva-f-screens', F.screens, P[0]);
                donut('tva-f-platforms', F.platforms);
                hbar('tva-f-reasons', F.reasons, '#c0392b');
            }
        })();
    </script>
@endif
