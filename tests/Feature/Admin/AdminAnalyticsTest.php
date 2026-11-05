<?php

namespace Tests\Feature\Admin;

use App\Models\ClaritySnapshot;
use App\Models\User;
use App\Services\Analytics\ClarityReport;
use App\Services\Analytics\Ga4Client;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin > App Analytics (Microsoft Clarity + Firebase/GA4). Every external
 * call is faked: a real Clarity call costs 1 of only 10 per day.
 */
class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const CLARITY = 'https://www.clarity.ms/export-data/api/v1/project-live-insights*';

    private const GA4_TOKEN = 'https://oauth2.googleapis.com/token';

    private const GA4_REPORT = 'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport';

    private ?string $keyFile = null;

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'timevault_analytics.clarity.token' => null,
            'timevault_analytics.ga4.property_id' => null,
            'timevault_analytics.ga4.credentials' => storage_path('framework/testing/no-such-ga4-key.json'),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->keyFile && is_file($this->keyFile)) {
            unlink($this->keyFile);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Overall payload: Traffic in the documented shape, the rest in the likely one. */
    private static function clarityOverall(int $sessions = 40): array
    {
        return [
            ['metricName' => 'Traffic', 'information' => [[
                'totalSessionCount' => (string) $sessions, 'totalBotSessionCount' => '0',
                'distantUserCount' => '12', 'PagesPerSessionPercentage' => 6.5,
            ]]],
            ['metricName' => 'EngagementTime', 'information' => [['totalTime' => '95', 'activeTime' => '61']]],
            ['metricName' => 'Rage Click Count', 'information' => [['sessionsWithMetricPercentage' => 5]]],
            ['metricName' => 'DeadClickCount', 'information' => [['sessionsWithMetricPercentage' => '10.0']]],
            ['metricName' => 'QuickbackClick', 'information' => [['sessionsWithMetricPercentage' => 2.5]]],
            ['metricName' => 'PopularPages', 'information' => [
                ['url' => 'home', 'visitsCount' => '30'],
                ['url' => 'withdraw', 'visitsCount' => '9'],
            ]],
        ];
    }

    private static function clarityBreakdown(string $dimension, array $values): array
    {
        $rows = [];
        foreach ($values as $label => $sessions) {
            $rows[] = ['totalSessionCount' => (string) $sessions, $dimension => $label];
        }

        return [['metricName' => 'Traffic', 'information' => $rows]];
    }

    private function fakeClarity(): void
    {
        Http::fake([self::CLARITY => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(match ($q['dimension1'] ?? '') {
                '' => self::clarityOverall(),
                'OS' => self::clarityBreakdown('OS', ['iOS' => 25, 'Android' => 15]),
                'Device' => self::clarityBreakdown('Device', ['iPhone 13' => 25, 'Pixel 8' => 15]),
                'Country/Region' => self::clarityBreakdown('Country/Region', ['United States' => 30, 'Canada' => 10]),
            });
        }]);
    }

    private function configureGa4(): void
    {
        // Windows PHP builds (XAMPP) have no default openssl.cnf, which key
        // generation needs; production only signs with an existing key.
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (! getenv('OPENSSL_CONF') && is_file($cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf')) {
            $options['config'] = $cnf;
        }
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $privatePem, null, $options);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->keyFile = tempnam(sys_get_temp_dir(), 'ga4');
        file_put_contents($this->keyFile, json_encode([
            'type' => 'service_account',
            'client_email' => 'dash@timevault.iam.gserviceaccount.com',
            'private_key' => $privatePem,
            'token_uri' => self::GA4_TOKEN,
        ]));
        config([
            'timevault_analytics.ga4.property_id' => 'properties/123456789',
            'timevault_analytics.ga4.credentials' => $this->keyFile,
        ]);
    }

    /** Answers each runReport by what it asks for, like GA4 would. */
    private function ga4Response(array $body): array
    {
        $dims = array_column($body['dimensions'] ?? [], 'name');
        $mets = array_column($body['metrics'] ?? [], 'name');
        $row = static fn (array $d, array $m): array => [
            'dimensionValues' => array_map(static fn ($v) => ['value' => (string) $v], $d),
            'metricValues' => array_map(static fn ($v) => ['value' => (string) $v], $m),
        ];
        $today = now()->format('Ymd');

        $rows = match ($dims) {
            [] => [$row([], [50, 20, 80, 125.5, 400])],
            ['eventName'] => [
                $row(['lock_started'], [10, 8, 5000]),
                $row(['lock_created'], [6, 5, 3150.75]),
                $row(['withdraw_started'], [4, 3, 900]),
                $row(['withdraw_completed'], [3, 3, 700]),
                $row(['paywall_shown'], [20, 15, 0]),
                $row(['plan_selected'], [8, 7, 0]),
                $row(['purchase'], [2, 2, 19.98]),
                $row(['login_failed'], [7, 5, 0]),
            ],
            ['date'] => [$row([$today], [12, 4])],
            ['date', 'eventName'] => [$row([$today, 'lock_created'], [2])],
            ['unifiedScreenName'] => [$row(['home'], [150, 40]), $row(['withdraw'], [30, 12])],
            ['platform'] => [$row(['iOS'], [30]), $row(['Android'], [20])],
            default => [],
        };

        return [
            'dimensionHeaders' => array_map(static fn ($n) => ['name' => $n], $dims),
            'metricHeaders' => array_map(static fn ($n) => ['name' => $n, 'type' => 'TYPE_INTEGER'], $mets),
            'rows' => $rows,
        ];
    }

    private function fakeGa4(): void
    {
        Http::fake([
            self::GA4_TOKEN => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::GA4_REPORT => fn (Request $request) => Http::response($this->ga4Response($request->data())),
        ]);
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_guests_are_sent_to_the_admin_login(): void
    {
        $this->get('/admin/analytics')->assertRedirect(route('admin.login'));
        $this->get('/admin/analytics.json')->assertRedirect(route('admin.login'));
    }

    public function test_app_users_who_are_not_admins_are_sent_to_the_admin_login(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get('/admin/analytics')->assertRedirect(route('admin.login'));
        $this->actingAs($user)->get('/admin/analytics.json')->assertRedirect(route('admin.login'));
    }

    public function test_the_page_uses_the_admin_layout_and_the_sidebar_links_to_it(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/analytics')
            ->assertOk()
            ->assertSee('App Analytics')
            ->assertSee('href="'.route('admin.analytics').'"', false)
            ->assertSee(route('admin.dashboard'), false);
    }

    public function test_the_sync_runs_daily_at_0015_utc(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'analytics:sync-clarity'));

        $this->assertNotNull($event, 'analytics:sync-clarity is not scheduled');
        $this->assertSame('15 0 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
    }

    // ── Clarity parsing ──────────────────────────────────────────────────

    public function test_clarity_summary_reads_documented_and_variant_field_names(): void
    {
        $s = ClarityReport::summary(self::clarityOverall());

        $this->assertSame(40.0, $s['sessions']);
        $this->assertSame(12.0, $s['users']);
        $this->assertSame(6.5, $s['screens_per_session']);
        $this->assertSame(61.0, $s['active_time']);
        $this->assertSame(5.0, $s['rage_taps_pct']); // "Rage Click Count" spelled with spaces
        $this->assertSame(10.0, $s['dead_taps_pct']); // number sent as a string
        $this->assertNull($s['app_errors_pct']); // metric absent → null, not an error
        $this->assertSame(
            [['screen' => 'home', 'visits' => 30], ['screen' => 'withdraw', 'visits' => 9]],
            ClarityReport::popularScreens(self::clarityOverall()),
        );
    }

    /** Metric and field names exactly as Clarity returned them for this mobile project (2026-11-05 sync). */
    public function test_clarity_summary_reads_mobile_project_names(): void
    {
        $info = fn (float $pct) => [['sessionsCount' => 4, 'sessionsWithMetricPercentage' => $pct, 'sessionsWithoutMetricPercentage' => 100 - $pct, 'screensViews' => 9, 'subTotal' => 4]];
        $payload = [
            ['metricName' => 'DeadTapCount', 'information' => $info(7.5)],
            ['metricName' => 'RageTapCount', 'information' => $info(2.5)],
            ['metricName' => 'ApplicationErrorCount', 'information' => $info(1.25)],
            ['metricName' => 'Traffic', 'information' => [['totalSessionCount' => 80, 'totalBotSessionCount' => 0, 'distinctUserCount' => 33, 'screensPerSessionPercentage' => 4.2]]],
            ['metricName' => 'EngagementTime', 'information' => [['totalTime' => 300, 'activeTime' => 120]]],
            ['metricName' => 'PopularScreens', 'information' => [['screenName' => 'home', 'visitsCount' => 50], ['screenName' => 'withdraw', 'visitsCount' => 12]]],
        ];

        $s = ClarityReport::summary($payload);

        $this->assertSame(80.0, $s['sessions']);
        $this->assertSame(33.0, $s['users']);
        $this->assertSame(4.2, $s['screens_per_session']);
        $this->assertSame(120.0, $s['active_time']);
        $this->assertSame(2.5, $s['rage_taps_pct']);
        $this->assertSame(7.5, $s['dead_taps_pct']);
        $this->assertSame(1.25, $s['app_errors_pct']);
        $this->assertSame(
            [['screen' => 'home', 'visits' => 50], ['screen' => 'withdraw', 'visits' => 12]],
            ClarityReport::popularScreens($payload),
        );
    }

    public function test_clarity_summary_survives_garbage(): void
    {
        $s = ClarityReport::summary([null, 'x', ['metricName' => 'Traffic', 'information' => 'nope']]);

        $this->assertNull($s['sessions']);
        $this->assertSame([], ClarityReport::popularScreens([]));
        $this->assertSame([], ClarityReport::popularScreens([['metricName' => 'PopularPages', 'information' => ['x', null]]]));
    }

    // ── Clarity sync command ─────────────────────────────────────────────

    public function test_sync_stores_one_snapshot_per_dimension_and_is_idempotent(): void
    {
        config(['timevault_analytics.clarity.token' => 'clarity-test-token']);
        $this->fakeClarity();

        $this->artisan('analytics:sync-clarity')->assertSuccessful();

        $day = now('UTC')->subDay()->toDateString();
        $this->assertSame(4, ClaritySnapshot::whereDate('captured_on', $day)->count());
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer clarity-test-token')
            && str_contains($r->url(), 'numOfDays=1'));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'dimension1=Country/Region'));

        // Second run the same day: nothing re-fetched, quota untouched.
        $this->artisan('analytics:sync-clarity')->assertSuccessful();
        Http::assertSentCount(4);

        $this->artisan('analytics:sync-clarity --force')->assertSuccessful();
        Http::assertSentCount(8);
        $this->assertSame(4, ClaritySnapshot::count());
    }

    public function test_sync_stops_at_the_daily_call_budget(): void
    {
        config(['timevault_analytics.clarity.token' => 't', 'timevault_analytics.clarity.daily_call_budget' => 2]);
        $this->fakeClarity();

        $this->artisan('analytics:sync-clarity')->assertFailed();
        $this->assertSame(2, ClaritySnapshot::count());
        Http::assertSentCount(2);
    }

    public function test_sync_stops_when_clarity_answers_429(): void
    {
        config(['timevault_analytics.clarity.token' => 't']);
        Http::fake([self::CLARITY => Http::response('Exceeded daily limit', 429)]);

        $this->artisan('analytics:sync-clarity')->assertFailed();

        Http::assertSentCount(1); // stops at the first 429 instead of trying every dimension
        $this->assertSame(0, ClaritySnapshot::count());
    }

    public function test_sync_without_token_fails_without_calling_clarity(): void
    {
        Http::fake();

        $this->artisan('analytics:sync-clarity')->assertFailed();

        Http::assertNothingSent();
    }

    // ── GA4 client ───────────────────────────────────────────────────────

    public function test_ga4_signs_a_valid_service_account_jwt(): void
    {
        $this->configureGa4();
        $this->fakeGa4();

        $rows = Ga4Client::rows(app(Ga4Client::class)->runReport([
            'dimensions' => [['name' => 'platform']],
            'metrics' => [['name' => 'activeUsers']],
        ]));
        $this->assertSame([['platform' => 'iOS', 'activeUsers' => 30.0], ['platform' => 'Android', 'activeUsers' => 20.0]], $rows);

        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::GA4_TOKEN) {
                return false;
            }
            [$head, $claims, $sig] = explode('.', $r->data()['assertion']);
            $decode = static fn (string $v) => base64_decode(strtr($v, '-_', '+/'));
            $c = json_decode($decode($claims), true);

            return $r->data()['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && openssl_verify("{$head}.{$claims}", $decode($sig), $this->publicKey, OPENSSL_ALGO_SHA256) === 1
                && $c['scope'] === 'https://www.googleapis.com/auth/analytics.readonly'
                && $c['iss'] === 'dash@timevault.iam.gserviceaccount.com';
        });
        Http::assertSent(fn (Request $r) => $r->url() === self::GA4_REPORT && $r->hasHeader('Authorization', 'Bearer ya29.test'));
    }

    // ── Dashboard page & JSON ────────────────────────────────────────────

    public function test_dashboard_explains_what_to_set_up_when_nothing_is_configured(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->get('/admin/analytics')
            ->assertOk()
            ->assertSee('CLARITY_API_TOKEN')
            ->assertSee('GA4_PROPERTY_ID')
            ->assertDontSee('chart.umd.min.js');

        Http::assertNothingSent();
    }

    public function test_dashboard_shows_clarity_and_firebase_numbers(): void
    {
        foreach ([2 => 30, 1 => 10] as $ago => $sessions) {
            $day = now('UTC')->subDays($ago)->toDateString();
            ClaritySnapshot::create(['captured_on' => $day, 'dimension' => '', 'payload' => self::clarityOverall($sessions)]);
            ClaritySnapshot::create(['captured_on' => $day, 'dimension' => 'Device', 'payload' => self::clarityBreakdown('Device', ['iPhone 13' => $sessions])]);
        }
        $this->configureGa4();
        $this->fakeGa4();
        $admin = $this->admin();

        $json = $this->actingAs($admin)->getJson('/admin/analytics.json?days=30')->assertOk()->json();

        $this->assertSame(30, $json['days']);
        $this->assertSame('ok', $json['clarity']['status']);
        $this->assertSame(40, $json['clarity']['totals']['sessions']);
        $this->assertEquals(5, $json['clarity']['totals']['rage_taps_pct']); // JSON drops the .0
        $this->assertSame([['label' => 'iPhone 13', 'sessions' => 40]], $json['clarity']['devices']);
        $this->assertSame(['screen' => 'home', 'visits' => 60], $json['clarity']['screens'][0]);
        $this->assertSame(now('UTC')->subDay()->toDateString(), $json['clarity']['last_synced_day']);

        $fb = $json['firebase'];
        $this->assertSame('ok', $fb['status']);
        $this->assertSame(50, $fb['overview']['active_users']);
        $this->assertSame(3150.75, $fb['money'][0]['usd']);
        $this->assertEquals(['event' => 'lock_created', 'count' => 6, 'pct' => 60], $fb['funnels'][0]['steps'][1]);
        $this->assertCount(30, $fb['daily']);
        $this->assertSame(2, end($fb['daily'])['lock_created']);
        $this->assertNull($fb['failure_reasons']);

        $this->actingAs($admin)
            ->get('/admin/analytics?days=30')
            ->assertOk()
            ->assertSee('$3,150.75')
            ->assertSee('Lock funds funnel')
            ->assertSee('iPhone 13')
            ->assertSee('integrity="sha384-', false);
    }

    public function test_ga4_failure_shows_an_error_but_keeps_clarity(): void
    {
        ClaritySnapshot::create(['captured_on' => now('UTC')->subDay()->toDateString(), 'dimension' => '', 'payload' => self::clarityOverall()]);
        $this->configureGa4();
        Http::fake([
            self::GA4_TOKEN => Http::response(['access_token' => 'ya29.test']),
            self::GA4_REPORT => Http::response(['error' => ['message' => 'User does not have sufficient permissions']], 403),
        ]);

        $json = $this->actingAs($this->admin())->getJson('/admin/analytics.json')->assertOk()->json();

        $this->assertSame('error', $json['firebase']['status']);
        $this->assertStringContainsString('403', $json['firebase']['message']);
        $this->assertSame('ok', $json['clarity']['status']);
    }

    public function test_unknown_range_falls_back_to_seven_days(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/admin/analytics.json?days=9999')
            ->assertOk()
            ->assertJsonPath('days', 7);
    }
}
