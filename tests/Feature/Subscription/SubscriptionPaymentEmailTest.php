<?php

namespace Tests\Feature\Subscription;

use App\Jobs\SendSubscriptionPaymentIssueNotification;
use App\Jobs\SendSubscriptionPaymentNotification;
use App\Mail\Stripe\SubscriptionPaymentMail;
use App\Mail\SubscriptionPaymentIssueMail;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * Payment success (first payment and every automatic renewal) emails both the
 * user and the admin, exactly once per Stripe invoice.
 */
class SubscriptionPaymentEmailTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        config(['mail.admin_email' => 'admin@example.test']);

        $this->user = User::factory()->create(['email' => 'tauseefchoohan0401@gmail.com', 'full_name' => 'Test User']);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Premium Monthly', 'price' => 4.99]);
        $this->subscription = Subscription::factory()->create([
            'user_id' => $this->user->id,
            'subscription_plan_id' => $plan->id,
            'stripe_subscription_id' => 'sub_mail',
        ]);
    }

    private function paidInvoice(string $id, string $reason, int $amount = 499): array
    {
        return [
            'id' => $id,
            'object' => 'invoice',
            'number' => 'TV-0001',
            'billing_reason' => $reason,
            'amount_paid' => $amount,
            'currency' => 'usd',
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/'.$id,
            'invoice_pdf' => 'https://pay.stripe.com/invoice/'.$id.'/pdf',
            'status_transitions' => ['paid_at' => now()->timestamp],
            'lines' => ['data' => [['period' => ['end' => now()->addMonth()->timestamp]]]],
            'parent' => ['subscription_details' => ['subscription' => 'sub_mail']],
        ];
    }

    private function stripeKnowsTheSubscription(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->stripe->respond('GET /v1/subscriptions/sub_mail', $this->stripeSubscription('sub_mail', 'active'));
        }
    }

    // ── Recording + dispatch ─────────────────────────────────────────────────

    public function test_the_first_payment_is_recorded_and_emailed(): void
    {
        Queue::fake();
        $this->stripeKnowsTheSubscription();

        $this->postStripeWebhook($this->stripeEvent('invoice.paid', $this->paidInvoice('in_first', 'subscription_create')))->assertOk();

        $invoice = SubscriptionInvoice::sole();
        $this->assertFalse($invoice->isRenewal());
        $this->assertEquals(4.99, $invoice->amount);
        $this->assertSame('TV-0001', $invoice->invoice_number);
        Queue::assertPushed(SendSubscriptionPaymentNotification::class, fn ($job) => $job->invoice->is($invoice));
    }

    public function test_an_automatic_renewal_is_recorded_and_emailed(): void
    {
        Queue::fake();
        $this->stripeKnowsTheSubscription();

        $this->postStripeWebhook($this->stripeEvent('invoice.paid', $this->paidInvoice('in_cycle', 'subscription_cycle')))->assertOk();

        $this->assertTrue(SubscriptionInvoice::sole()->isRenewal());
        Queue::assertPushed(SendSubscriptionPaymentNotification::class, 1);
    }

    public function test_the_same_invoice_is_never_emailed_twice(): void
    {
        // Stripe can deliver the same invoice in two different events (retries,
        // or invoice.paid resent). Each is a new event ID, so only the invoice
        // ID can stop the duplicate.
        Queue::fake();
        $this->stripeKnowsTheSubscription(2);

        $this->postStripeWebhook($this->stripeEvent('invoice.paid', $this->paidInvoice('in_dup', 'subscription_cycle')))->assertOk();
        $this->postStripeWebhook($this->stripeEvent('invoice.paid', $this->paidInvoice('in_dup', 'subscription_cycle')))->assertOk();

        $this->assertSame(1, SubscriptionInvoice::count());
        Queue::assertPushed(SendSubscriptionPaymentNotification::class, 1);
    }

    public function test_a_zero_amount_invoice_sends_nothing(): void
    {
        Queue::fake();
        $this->stripeKnowsTheSubscription();

        $this->postStripeWebhook($this->stripeEvent('invoice.paid', $this->paidInvoice('in_zero', 'subscription_cycle', 0)))->assertOk();

        $this->assertSame(0, SubscriptionInvoice::count());
        Queue::assertNothingPushed();
    }

    // ── The emails themselves ────────────────────────────────────────────────

    public function test_both_the_user_and_the_admin_get_a_payment_email(): void
    {
        Mail::fake();
        $invoice = SubscriptionInvoice::factory()->create(['subscription_id' => $this->subscription->id, 'user_id' => $this->user->id]);

        (new SendSubscriptionPaymentNotification($invoice))->handle();

        Mail::assertSent(SubscriptionPaymentMail::class, fn ($m) => $m->hasTo('tauseefchoohan0401@gmail.com') && ! $m->isAdmin);
        Mail::assertSent(SubscriptionPaymentMail::class, fn ($m) => $m->hasTo('admin@example.test') && $m->isAdmin);
        Mail::assertSentCount(2);
    }

    public function test_subjects_tell_a_new_subscription_from_a_renewal(): void
    {
        $first = SubscriptionInvoice::factory()->create(['subscription_id' => $this->subscription->id, 'user_id' => $this->user->id]);
        $renewal = SubscriptionInvoice::factory()->renewal()->create(['subscription_id' => $this->subscription->id, 'user_id' => $this->user->id]);

        $this->assertSame('Welcome to Premium Monthly - payment received', (new SubscriptionPaymentMail($first))->envelope()->subject);
        $this->assertSame('[Admin] New subscriber - Premium Monthly (USD 4.99)', (new SubscriptionPaymentMail($first, true))->envelope()->subject);
        $this->assertSame('Your Premium Monthly subscription has renewed', (new SubscriptionPaymentMail($renewal))->envelope()->subject);
        $this->assertSame('[Admin] Subscription renewed - Premium Monthly (USD 4.99)', (new SubscriptionPaymentMail($renewal, true))->envelope()->subject);
    }

    public function test_the_emails_render_the_payment_details(): void
    {
        $invoice = SubscriptionInvoice::factory()->renewal()->create([
            'subscription_id' => $this->subscription->id,
            'user_id' => $this->user->id,
            'invoice_number' => 'TV-4242',
        ]);

        $userHtml = (new SubscriptionPaymentMail($invoice))->render();
        $adminHtml = (new SubscriptionPaymentMail($invoice, true))->render();

        $this->assertStringContainsString('Subscription Renewed', $userHtml);
        $this->assertStringContainsString('4.99', $userHtml);
        $this->assertStringContainsString('TV-4242', $userHtml);
        $this->assertStringContainsString('View receipt', $userHtml);

        $this->assertStringContainsString('Admin Notification', $adminHtml);
        $this->assertStringContainsString('tauseefchoohan0401@gmail.com', $adminHtml);
        $this->assertStringNotContainsString('View receipt', $adminHtml);
    }

    public function test_a_user_who_turned_off_email_alerts_gets_none_but_the_admin_still_does(): void
    {
        Mail::fake();
        UserNotificationSetting::create(['user_id' => $this->user->id, 'transaction_alert' => true, 'email_alert' => false]);
        $invoice = SubscriptionInvoice::factory()->create(['subscription_id' => $this->subscription->id, 'user_id' => $this->user->id]);

        (new SendSubscriptionPaymentNotification($invoice))->handle();

        Mail::assertSentCount(1);
        Mail::assertSent(SubscriptionPaymentMail::class, fn ($m) => $m->hasTo('admin@example.test'));
    }

    public function test_a_failed_renewal_now_reaches_the_admin_too(): void
    {
        Mail::fake();

        (new SendSubscriptionPaymentIssueNotification($this->subscription, 'failed', 'https://invoice.stripe.com/i/x'))->handle();

        Mail::assertSent(SubscriptionPaymentIssueMail::class, fn ($m) => $m->hasTo('tauseefchoohan0401@gmail.com') && ! $m->isAdmin);
        Mail::assertSent(SubscriptionPaymentIssueMail::class, fn ($m) => $m->hasTo('admin@example.test') && $m->isAdmin);
        $this->assertStringContainsString('[Admin]', (new SubscriptionPaymentIssueMail($this->subscription, 'failed', null, true))->envelope()->subject);
        $this->assertStringContainsString('Hi Admin', (new SubscriptionPaymentIssueMail($this->subscription, 'failed', null, true))->render());
    }

    // ── Account deletion ─────────────────────────────────────────────────────

    public function test_deleting_the_account_cancels_the_subscription_in_stripe(): void
    {
        Mail::fake();
        $this->stripe->respond('GET /v1/subscriptions/sub_mail', $this->stripeSubscription('sub_mail', 'active'));
        $this->stripe->respond('DELETE /v1/subscriptions/sub_mail', $this->stripeSubscription('sub_mail', 'canceled', ['canceled_at' => now()->timestamp]));

        $this->actingAs($this->user, 'sanctum')->postJson('/api/auth/delete-account')->assertOk();

        $this->assertSame('canceled', $this->subscription->fresh()->status);
        $this->assertCount(1, $this->stripe->requestsTo('DELETE', '/v1/subscriptions/sub_mail'));
    }

    public function test_a_stripe_outage_does_not_block_account_deletion(): void
    {
        Mail::fake();
        $this->stripe->respond('GET /v1/subscriptions/sub_mail', ['error' => ['type' => 'api_error', 'message' => 'Stripe down']], 500);
        $this->stripe->respond('GET /v1/subscriptions/sub_mail', ['error' => ['type' => 'api_error', 'message' => 'Stripe down']], 500);
        $this->stripe->respond('GET /v1/subscriptions/sub_mail', ['error' => ['type' => 'api_error', 'message' => 'Stripe down']], 500);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/auth/delete-account')->assertOk();

        $this->assertSame('deleted', $this->user->fresh()->status);
    }
}
