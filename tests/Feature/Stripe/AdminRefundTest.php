<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendRefundFailedNotification;
use App\Jobs\SendRefundIssuedNotification;
use App\Mail\Stripe\AdminRefundIssuedMail;
use App\Mail\Stripe\RefundIssuedMail;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\FakesStripe;
use Tests\Fakes\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Admin refund of a deposit, in full or in part, back to the card that paid
 * it. Stripe is faked at the SDK HTTP layer, so the real \Stripe\Refund /
 * Transfer calls run.
 */
class AdminRefundTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Queue::fake();
        config(['mail.admin_email' => 'admin@example.com']);

        $this->admin = User::factory()->create(['role' => 'admin', 'full_name' => 'Megan Carter', 'email' => 'megan.carter@example.com']);
        $this->user = User::factory()->create(['full_name' => 'John Miller', 'email' => 'john.miller@example.com']);
    }

    private function deposit(float $amount, string $brand = 'visa', string $last4 = '4242', array $hold = []): PaymentHold
    {
        $payment = Payment::factory()->create([
            'user_id' => $this->user->id,
            'amount' => $amount,
            'card_brand' => $brand,
            'card_last4' => $last4,
            'card_funding' => 'debit',
        ]);

        return PaymentHold::factory()->create(array_merge([
            'payment_id' => $payment->id,
            'user_id' => $this->user->id,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'status' => 'ready_for_transfer',
            'hold_start_at' => now()->subDays(3),
            'hold_end_at' => now()->subDay(),
        ], $hold));
    }

    private function transferOut(PaymentHold $hold, float $amount, string $stripeId = 'tr_out_1'): Transfer
    {
        return Transfer::factory()->create([
            'hold_id' => $hold->id,
            'user_id' => $this->user->id,
            'stripe_transfer_id' => $stripeId,
            'stripe_connect_account_id' => 'acct_user_1',
            'amount' => $amount,
            'status' => 'completed',
        ]);
    }

    private function refund(PaymentHold $hold, array $form = [])
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.payment-holds.index'))
            ->post(route('admin.payment-holds.refund', $hold->id), array_merge([
                'amount' => number_format((float) $hold->amount, 2, '.', ''),
                'request_token' => (string) Str::uuid(),
                'reason_code' => 'requested_by_customer',
                'reason_note' => 'Customer asked to close the vault.',
                'password' => 'password',
                'confirm' => '1',
            ], $form));
    }

    private function stripeRefundOk(string $id = 're_ok_1', string $status = 'succeeded'): void
    {
        $this->stripe->respond('POST /v1/refunds', ['id' => $id, 'object' => 'refund', 'status' => $status]);
    }

    private function connectBalance(int $availableCents): void
    {
        $this->stripe->respond('GET /v1/balance', [
            'object' => 'balance',
            'available' => [['amount' => $availableCents, 'currency' => 'usd']],
            'pending' => [['amount' => 0, 'currency' => 'usd']],
        ]);
    }

    private function refundRequests(): array
    {
        return $this->stripe->requestsTo('POST', '/v1/refunds');
    }

    // ─── Popup ───────────────────────────────────────────────────────────────

    public function test_preview_shows_the_user_the_card_and_the_maximum_refundable(): void
    {
        $hold = $this->deposit(200, 'mastercard', '4444');

        $this->actingAs($this->admin)
            ->getJson(route('admin.payment-holds.refund-preview', $hold->id))
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('user.name', 'John Miller')
            ->assertJsonPath('user.email', 'john.miller@example.com')
            ->assertJsonPath('card.label', 'Mastercard •••• 4444')
            ->assertJsonPath('card.funding', 'debit')
            ->assertJsonPath('card_label', 'Mastercard •••• 4444')
            ->assertJsonPath('money.deposit', 200)
            ->assertJsonPath('money.already_refunded', 0)
            ->assertJsonPath('money.in_vault', 200)
            ->assertJsonPath('money.max_refundable', 200);
    }

    public function test_non_admin_cannot_preview_or_refund(): void
    {
        $hold = $this->deposit(50);

        $this->actingAs($this->user)->get(route('admin.payment-holds.refund-preview', $hold->id))->assertRedirect(route('admin.login'));
        $this->actingAs($this->user)->post(route('admin.payment-holds.refund', $hold->id), ['amount' => '50'])->assertRedirect(route('admin.login'));

        $this->assertSame([], $this->stripe->requests);
        $this->assertSame(0, Refund::count());
    }

    // ─── Full and partial refunds from the vault ─────────────────────────────

    public function test_full_refund_sends_the_whole_deposit_to_its_own_card(): void
    {
        $hold = $this->deposit(150);
        $this->stripeRefundOk();

        $this->refund($hold)->assertRedirect(route('admin.payment-holds.index'))->assertSessionHas('success');

        $requests = $this->refundRequests();
        $this->assertCount(1, $requests);
        $this->assertSame(15000, (int) $requests[0]['params']['amount']);
        $this->assertSame($hold->payment->payment_intent_id, $requests[0]['params']['payment_intent']);

        $refund = Refund::sole();
        $this->assertSame('tv-refund-'.$refund->id, FakeStripeHttpClient::idempotencyKey($requests[0]));
        $this->assertSame('succeeded', $refund->status);
        $this->assertFalse($refund->isPartial());
        $this->assertSame($this->admin->id, $refund->admin_id);

        $hold->refresh();
        $this->assertSame('refunded', $hold->status);
        $this->assertSame('0.00', $hold->remaining_amount);
        $this->assertSame('150.00', $hold->payment->refresh()->refunded_amount);

        Queue::assertPushed(SendRefundIssuedNotification::class, fn ($job) => $job->refund->is($refund));
    }

    public function test_partial_refunds_of_a_custom_amount_can_follow_each_other_until_nothing_is_left(): void
    {
        $hold = $this->deposit(150);
        $this->stripeRefundOk('re_part_1');
        $this->stripeRefundOk('re_part_2');

        $this->refund($hold, ['amount' => '40.25'])->assertSessionHas('success');

        $this->assertSame(4025, (int) $this->refundRequests()[0]['params']['amount']);
        $hold->refresh();
        $this->assertSame('109.75', $hold->remaining_amount);
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertSame('40.25', $hold->payment->refresh()->refunded_amount);
        $this->assertTrue(Refund::sole()->isPartial());

        $this->actingAs($this->admin)->getJson(route('admin.payment-holds.refund-preview', $hold->id))
            ->assertJsonPath('money.already_refunded', 40.25)
            ->assertJsonPath('money.max_refundable', 109.75);

        $this->refund($hold, ['amount' => '109.75'])->assertSessionHas('success');

        $this->assertSame(10975, (int) $this->refundRequests()[1]['params']['amount']);
        $this->assertSame('refunded', $hold->refresh()->status);
        $this->assertSame('0.00', $hold->remaining_amount);
        $this->assertSame('150.00', $hold->payment->refresh()->refunded_amount);

        $this->refund($hold, ['amount' => '1'])->assertSessionHasErrors(['refund' => 'This deposit has already been fully refunded.']);
        $this->assertCount(2, $this->refundRequests());
        $this->assertSame(0, Artisan::call('refunds:audit'));
    }

    public function test_a_partial_refund_keeps_a_locked_deposit_locked(): void
    {
        $hold = $this->deposit(100, hold: ['status' => 'holding', 'hold_end_at' => now()->addDays(2)]);
        $this->stripeRefundOk();

        $this->refund($hold, ['amount' => '30'])->assertSessionHas('success');

        $this->assertSame('holding', $hold->refresh()->status);
        $this->assertSame('70.00', $hold->remaining_amount);
    }

    public function test_an_amount_above_the_maximum_is_refused_without_calling_stripe(): void
    {
        $hold = $this->deposit(80);

        $this->refund($hold, ['amount' => '80.01'])->assertSessionHasErrors(['refund' => 'You can refund at most $80.00 of this deposit right now.']);

        $this->assertSame([], $this->stripe->requests);
        $this->assertSame(0, Refund::count());
        $this->assertSame('80.00', $hold->refresh()->remaining_amount);
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        $hold = $this->deposit(80);

        foreach (['0', '0.00', '-5', '12.345', 'abc', '', '1e3'] as $amount) {
            $this->refund($hold, ['amount' => $amount])->assertSessionHasErrors('amount');
        }

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_the_same_form_submitted_twice_refunds_once(): void
    {
        $hold = $this->deposit(100);
        $this->stripeRefundOk();
        $token = (string) Str::uuid();

        $this->refund($hold, ['amount' => '20', 'request_token' => $token])->assertSessionHas('success');
        $this->refund($hold, ['amount' => '20', 'request_token' => $token])
            ->assertSessionHasErrors(['refund' => 'This refund was already submitted. Reload the page to see its result.']);

        $this->assertCount(1, $this->refundRequests());
        $this->assertSame('80.00', $hold->refresh()->remaining_amount);
    }

    public function test_only_the_chosen_deposit_and_its_own_card_are_refunded(): void
    {
        $first = $this->deposit(100, 'visa', '4242');
        $second = $this->deposit(200, 'mastercard', '5555');
        $third = $this->deposit(300, 'visa', '1111');
        $this->stripeRefundOk();

        $this->refund($second, ['amount' => '75'])->assertSessionHas('success');

        $requests = $this->refundRequests();
        $this->assertCount(1, $requests);
        $this->assertSame($second->payment->payment_intent_id, $requests[0]['params']['payment_intent']);
        $this->assertSame(7500, (int) $requests[0]['params']['amount']);

        $this->assertSame('Mastercard •••• 5555', Refund::sole()->cardLabel());
        $this->assertSame('125.00', $second->refresh()->remaining_amount);
        $this->assertSame('100.00', $first->refresh()->remaining_amount);
        $this->assertSame('300.00', $third->refresh()->remaining_amount);
        $this->assertSame('0.00', $first->payment->refresh()->refunded_amount);
    }

    public function test_wrong_admin_password_refunds_nothing(): void
    {
        $hold = $this->deposit(60);

        $this->refund($hold, ['password' => 'wrong-password'])->assertSessionHasErrors('password');

        $this->assertSame([], $this->stripe->requests);
        $this->assertSame(0, Refund::count());
        $this->assertSame('60.00', $hold->refresh()->remaining_amount);
    }

    public function test_the_confirmation_box_a_note_and_a_form_token_are_required(): void
    {
        $hold = $this->deposit(60);

        $this->refund($hold, ['confirm' => null, 'reason_note' => 'no', 'request_token' => 'not-a-uuid'])
            ->assertSessionHasErrors(['confirm', 'reason_note', 'request_token']);

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_refund_is_blocked_while_a_withdrawal_is_in_progress(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 0, 'status' => 'transferred']);
        Transfer::factory()->create(['hold_id' => $hold->id, 'user_id' => $this->user->id, 'amount' => 100, 'status' => 'pending']);

        $this->refund($hold)->assertSessionHasErrors(['refund' => 'A withdrawal is in progress for this deposit. Try again after it completes.']);

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_stripe_rejecting_the_refund_puts_the_money_back_in_the_vault(): void
    {
        $hold = $this->deposit(90);
        $this->stripe->respond('POST /v1/refunds', ['error' => ['type' => 'invalid_request_error', 'message' => 'Insufficient funds in Stripe account.']], 400);

        $this->refund($hold, ['amount' => '35'])->assertSessionHasErrors('refund');

        $hold->refresh();
        $this->assertSame('90.00', $hold->remaining_amount);
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertSame('failed', Refund::sole()->status);
        $this->assertSame('0.00', $hold->payment->refresh()->refunded_amount);
        Queue::assertPushed(SendRefundFailedNotification::class);
        Queue::assertNotPushed(SendRefundIssuedNotification::class);

        // The admin may retry after a failure.
        $this->stripeRefundOk('re_retry');
        $this->refund($hold, ['amount' => '35'])->assertSessionHas('success');
        $this->assertSame('55.00', $hold->refresh()->remaining_amount);
    }

    // ─── Money already in the user's Connect account ────────────────────────

    public function test_money_in_connect_is_pulled_back_first_then_refunded_to_the_card(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 40, 'status' => 'partial_transferred']);
        $transfer = $this->transferOut($hold, 60);

        $this->connectBalance(10000);
        $this->stripe->respond('POST /v1/transfers/tr_out_1/reversals', ['id' => 'trr_1', 'object' => 'transfer_reversal', 'amount' => 6000]);
        $this->stripeRefundOk();

        $this->refund($hold)->assertSessionHas('success');

        $balance = $this->stripe->requestsTo('GET', '/v1/balance');
        $this->assertCount(1, $balance);
        $this->assertNotEmpty(array_filter($balance[0]['headers'], fn ($h) => strcasecmp(trim($h), 'Stripe-Account: acct_user_1') === 0));

        $reversals = $this->stripe->requestsTo('POST', '/v1/transfers/tr_out_1/reversals');
        $this->assertCount(1, $reversals);
        $this->assertSame(6000, (int) $reversals[0]['params']['amount']);

        $refund = Refund::sole();
        $this->assertSame('tv-refund-'.$refund->id.'-rev-'.$transfer->id, FakeStripeHttpClient::idempotencyKey($reversals[0]));
        $this->assertSame(10000, (int) $this->refundRequests()[0]['params']['amount']);
        $this->assertSame('40.00', $refund->vault_amount);
        $this->assertSame('60.00', $refund->reversed_amount);
        $this->assertSame('60.00', $transfer->refresh()->reversed_amount);
        $this->assertSame('refunded', $hold->refresh()->status);
        $this->assertSame('0.00', $hold->remaining_amount);
    }

    public function test_a_partial_amount_takes_the_vault_first_and_only_the_rest_from_connect(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 40, 'status' => 'partial_transferred']);
        $transfer = $this->transferOut($hold, 60);

        $this->connectBalance(10000);
        $this->stripe->respond('POST /v1/transfers/tr_out_1/reversals', ['id' => 'trr_2', 'object' => 'transfer_reversal', 'amount' => 3000]);
        $this->stripeRefundOk();

        $this->refund($hold, ['amount' => '70'])->assertSessionHas('success');

        $this->assertSame(3000, (int) $this->stripe->requestsTo('POST', '/v1/transfers/tr_out_1/reversals')[0]['params']['amount']);
        $this->assertSame(7000, (int) $this->refundRequests()[0]['params']['amount']);
        $this->assertSame('30.00', $transfer->refresh()->reversed_amount);
        $this->assertSame('0.00', $hold->refresh()->remaining_amount);
        $this->assertSame('transferred', $hold->status);
        $this->assertSame(0, Artisan::call('refunds:audit'));
    }

    public function test_the_ledger_adds_up_through_partial_refunds_and_a_late_bank_failure(): void
    {
        $hold = $this->deposit(200, hold: ['remaining_amount' => 80, 'status' => 'partial_transferred']);
        $transfer = $this->transferOut($hold, 120);
        $paymentIntent = $hold->payment->payment_intent_id;

        // 1) $50 from the vault only: no balance check, no reversal.
        $this->stripeRefundOk('re_l_1');
        $this->refund($hold, ['amount' => '50'])->assertSessionHas('success');
        $this->assertSame([], $this->stripe->requestsTo('GET', '/v1/balance'));
        $this->assertSame('30.00', $hold->refresh()->remaining_amount);
        $this->assertSame(0, Artisan::call('refunds:audit'));

        // 2) $100: the last $30 of the vault plus $70 pulled back from Connect.
        $this->connectBalance(12000);
        $this->stripe->respond('POST /v1/transfers/tr_out_1/reversals', ['id' => 'trr_l', 'object' => 'transfer_reversal', 'amount' => 7000]);
        $this->stripeRefundOk('re_l_2', 'pending');
        $this->refund($hold, ['amount' => '100'])->assertSessionHas('success');
        $this->assertSame('0.00', $hold->refresh()->remaining_amount);
        $this->assertSame('70.00', $transfer->refresh()->reversed_amount);
        $this->assertSame('150.00', $hold->payment->refresh()->refunded_amount);
        $this->assertSame(0, Artisan::call('refunds:audit'));

        // Every card refund used this deposit's own PaymentIntent and nothing that could redirect it.
        foreach ($this->refundRequests() as $request) {
            $this->assertSame($paymentIntent, $request['params']['payment_intent']);
            $this->assertArrayNotHasKey('charge', $request['params']);
            $this->assertArrayNotHasKey('destination', $request['params']);
        }

        // 3) The bank rejects refund 2 later: its $100 is the user's again, in the vault.
        $this->postStripeWebhook($this->stripeEvent('refund.failed', [
            'id' => 're_l_2', 'object' => 'refund', 'amount' => 10000, 'currency' => 'usd',
            'payment_intent' => $paymentIntent, 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card',
        ]))->assertOk();

        $this->assertSame('100.00', $hold->refresh()->remaining_amount);
        $this->assertSame('partial_transferred', $hold->status);
        $this->assertSame('50.00', $hold->payment->refresh()->refunded_amount);
        $this->assertSame(0, Artisan::call('refunds:audit'));

        // The max is now vault $100 + Connect $50 = charge $200 − $50 refunded.
        $this->connectBalance(12000);
        $this->actingAs($this->admin)->getJson(route('admin.payment-holds.refund-preview', $hold->id))
            ->assertJsonPath('money.max_refundable', 150);
    }

    public function test_money_already_paid_out_to_the_bank_lowers_the_maximum(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 0, 'status' => 'transferred']);
        $this->transferOut($hold, 100);

        $this->connectBalance(2500);
        $this->actingAs($this->admin)
            ->getJson(route('admin.payment-holds.refund-preview', $hold->id))
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('money.max_refundable', 25)
            ->assertJsonPath('note', 'The user already moved $75.00 of this deposit to their bank. That part cannot be refunded here.');

        $this->connectBalance(2500);
        $this->refund($hold, ['amount' => '30'])->assertSessionHasErrors(['refund' => 'You can refund at most $25.00 of this deposit right now.']);
        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/transfers'));

        $this->connectBalance(2500);
        $this->stripe->respond('POST /v1/transfers/tr_out_1/reversals', ['id' => 'trr_3', 'object' => 'transfer_reversal', 'amount' => 2500]);
        $this->stripeRefundOk();
        $this->refund($hold, ['amount' => '25'])->assertSessionHas('success');

        $this->assertSame(2500, (int) $this->refundRequests()[0]['params']['amount']);
        $this->assertSame('transferred', $hold->refresh()->status);
    }

    public function test_nothing_refundable_when_all_the_money_was_paid_out(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 0, 'status' => 'transferred']);
        $this->transferOut($hold, 100);
        $this->connectBalance(0);

        $this->actingAs($this->admin)
            ->getJson(route('admin.payment-holds.refund-preview', $hold->id))
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('money.max_refundable', 0);
    }

    public function test_a_failed_reversal_stops_before_the_card_and_restores_the_vault(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 40, 'status' => 'partial_transferred']);
        $transfer = $this->transferOut($hold, 60);
        $this->connectBalance(10000);
        $this->stripe->respond('POST /v1/transfers/tr_out_1/reversals', ['error' => ['type' => 'invalid_request_error', 'message' => 'Insufficient funds.']], 400);

        $this->refund($hold)->assertSessionHasErrors('refund');

        $this->assertSame([], $this->refundRequests());
        $this->assertSame('40.00', $hold->refresh()->remaining_amount);
        $this->assertSame('partial_transferred', $hold->status);
        $this->assertSame('0.00', $transfer->refresh()->reversed_amount);
        $this->assertSame('failed', Refund::sole()->status);
    }

    public function test_reversal_ok_but_card_refund_fails_puts_all_of_it_back_in_the_vault(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 40, 'status' => 'partial_transferred']);
        $transfer = $this->transferOut($hold, 60);
        $this->connectBalance(10000);
        $this->stripe->respond('POST /v1/transfers/tr_out_1/reversals', ['id' => 'trr_1', 'object' => 'transfer_reversal', 'amount' => 6000]);
        $this->stripe->respond('POST /v1/refunds', ['error' => ['type' => 'card_error', 'message' => 'The card was closed.']], 402);

        $this->refund($hold)->assertSessionHasErrors('refund');

        $hold->refresh();
        $this->assertSame('100.00', $hold->remaining_amount);
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertSame('60.00', $transfer->refresh()->reversed_amount);
        $this->assertSame('failed', Refund::sole()->status);
        $this->assertSame(0, Artisan::call('refunds:audit'));
    }

    // ─── Other code that reads balances ──────────────────────────────────────

    public function test_app_balances_and_the_fix_command_do_not_bring_refunded_money_back(): void
    {
        $refunded = $this->deposit(100);
        $partial = $this->deposit(50);
        $kept = $this->deposit(30);
        $this->stripeRefundOk('re_a');
        $this->stripeRefundOk('re_b');
        $this->refund($refunded)->assertSessionHas('success');
        $this->refund($partial, ['amount' => '20'])->assertSessionHas('success');

        Artisan::call('fix:hold-remaining-amount');
        $this->assertSame('0.00', $refunded->refresh()->remaining_amount);
        $this->assertSame('refunded', $refunded->status);
        $this->assertSame('30.00', $partial->refresh()->remaining_amount);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/payment-holds')
            ->assertOk()
            ->assertJsonPath('summary.total_refunded_amount', 120)
            ->assertJsonPath('summary.pending_balance', 60);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/transactions/checkouts')
            ->assertOk()
            ->assertJsonPath('summary.total_refunded_amount', 120);

        $this->assertSame('30.00', $kept->refresh()->remaining_amount);
    }

    // ─── Emails and pages ────────────────────────────────────────────────────

    public function test_user_and_admin_emails_name_the_amount_and_the_card(): void
    {
        Mail::fake();
        $hold = $this->deposit(120, 'visa', '4242');
        $hold->payment->update(['refunded_amount' => 120]);
        $refund = Refund::factory()->create([
            'payment_id' => $hold->payment_id,
            'payment_hold_id' => $hold->id,
            'user_id' => $this->user->id,
            'admin_id' => $this->admin->id,
            'amount' => 120,
            'vault_amount' => 120,
        ]);

        (new SendRefundIssuedNotification($refund))->handle();

        Mail::assertSent(RefundIssuedMail::class, function (RefundIssuedMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('john.miller@example.com')
                && $mail->envelope()->subject === 'Your refund of $120.00 is on its way'
                && str_contains($html, 'Visa •••• 4242')
                && str_contains($html, '5–10 business days')
                && ! str_contains($html, 'partial refund')
                && ! str_contains($html, 'Customer asked');
        });

        Mail::assertSent(AdminRefundIssuedMail::class, function (AdminRefundIssuedMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('admin@example.com')
                && str_contains($mail->envelope()->subject, 'Refund issued: $120.00 to John Miller (Visa •••• 4242)')
                && str_contains($html, 'Megan Carter')
                && str_contains($html, 'Full refund');
        });
    }

    public function test_partial_refund_emails_say_it_is_partial(): void
    {
        Mail::fake();
        $hold = $this->deposit(120);
        $hold->payment->update(['refunded_amount' => 50]);
        $refund = Refund::factory()->create([
            'payment_id' => $hold->payment_id,
            'payment_hold_id' => $hold->id,
            'user_id' => $this->user->id,
            'amount' => 50,
            'vault_amount' => 50,
        ]);

        (new SendRefundIssuedNotification($refund))->handle();

        Mail::assertSent(RefundIssuedMail::class, fn (RefundIssuedMail $mail) => str_contains($mail->render(), 'partial refund')
            && str_contains($mail->render(), 'total refunded so far $50.00 of $120.00'));
        Mail::assertSent(AdminRefundIssuedMail::class, fn (AdminRefundIssuedMail $mail) => str_contains($mail->render(), 'Partial refund'));
    }

    // ─── Unclear Stripe answers and stuck rows ───────────────────────────────

    private function stripe500(string $route, int $times = 2): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->stripe->respond($route, ['error' => ['type' => 'api_error', 'message' => 'Something went wrong on Stripe\'s end.']], 500);
        }
    }

    public function test_a_stripe_500_on_the_card_refund_keeps_the_money_out_of_the_vault_until_stripe_settles_it(): void
    {
        $hold = $this->deposit(80);
        $this->stripe500('POST /v1/refunds');

        $this->refund($hold, ['amount' => '30'])->assertSessionHasErrors('refund');

        $refund = Refund::sole();
        $this->assertSame('pending', $refund->status);
        $this->assertTrue($refund->needs_attention);
        $this->assertStringContainsString("refunds:reconcile {$refund->id}", $refund->failure_reason);
        $this->assertSame('50.00', $hold->refresh()->remaining_amount, 'an unclear answer must not refill the vault');
        $this->assertCount(2, $this->refundRequests(), 'retried once with the same key');
        $this->assertSame(FakeStripeHttpClient::idempotencyKey($this->refundRequests()[0]), FakeStripeHttpClient::idempotencyKey($this->refundRequests()[1]));
        $this->refund($hold, ['amount' => '1'])->assertSessionHasErrors(['refund' => 'A refund for this deposit is already in progress. Wait for it to finish.']);

        // Stripe did make it: the webhook settles the row.
        $this->postStripeWebhook($this->stripeEvent('refund.created', [
            'id' => 're_late', 'object' => 'refund', 'amount' => 3000, 'currency' => 'usd', 'status' => 'succeeded',
            'payment_intent' => $hold->payment->payment_intent_id, 'metadata' => ['refund_id' => (string) $refund->id],
        ]))->assertOk();

        $refund->refresh();
        $this->assertSame('succeeded', $refund->status);
        $this->assertFalse($refund->needs_attention);
        $this->assertSame('30.00', $hold->payment->refresh()->refunded_amount);
        $this->assertSame(0, Artisan::call('refunds:audit'));
    }

    public function test_reconcile_puts_the_money_back_when_stripe_never_made_the_card_refund(): void
    {
        $hold = $this->deposit(80);
        $this->stripe500('POST /v1/refunds');
        $this->refund($hold, ['amount' => '30']);
        $refund = Refund::sole();

        $this->stripe->respond('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->assertSame(1, Artisan::call('refunds:reconcile', ['refund' => $refund->id]), 'too early: the webhook may still come');
        $this->assertSame('pending', $refund->refresh()->status);

        $this->travel(20)->minutes();
        $this->assertSame(0, Artisan::call('refunds:reconcile', ['refund' => $refund->id]));

        $this->assertSame('failed', $refund->refresh()->status);
        $this->assertSame('80.00', $hold->refresh()->remaining_amount);
        $this->assertSame('0.00', $hold->payment->refresh()->refunded_amount);
        $this->assertSame(0, Artisan::call('refunds:audit'));
        Queue::assertPushed(SendRefundFailedNotification::class);
    }

    public function test_reconcile_records_a_reversal_stripe_made_before_going_silent(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 0, 'status' => 'transferred']);
        $transfer = $this->transferOut($hold, 100);
        $this->connectBalance(10000);
        $this->stripe500('POST /v1/transfers/tr_out_1/reversals');

        $this->refund($hold, ['amount' => '40'])->assertSessionHasErrors('refund');
        $refund = Refund::sole();
        $this->assertSame([], $this->refundRequests(), 'the card is never touched after an unclear reversal');
        $this->assertSame('0.00', $transfer->refresh()->reversed_amount);

        $this->travel(20)->minutes();
        $this->stripe->respond('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false]);
        $this->stripe->respond('GET /v1/transfers/tr_out_1/reversals', ['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'trr_silent', 'object' => 'transfer_reversal', 'amount' => 4000, 'metadata' => ['refund_id' => (string) $refund->id]],
            ['id' => 'trr_other', 'object' => 'transfer_reversal', 'amount' => 999, 'metadata' => ['refund_id' => '999999']],
        ]]);

        $this->assertSame(0, Artisan::call('refunds:reconcile', ['refund' => $refund->id]));

        // Stripe pulled $40 back to the platform; it is the user's money in the vault now.
        $this->assertSame('40.00', $transfer->refresh()->reversed_amount);
        $this->assertSame('40.00', $hold->refresh()->remaining_amount);
        $this->assertSame('failed', $refund->refresh()->status);
        $this->assertSame(0, Artisan::call('refunds:audit'));
    }

    public function test_a_late_pending_webhook_does_not_undo_a_succeeded_refund(): void
    {
        $hold = $this->deposit(60);
        $this->stripeRefundOk('re_order');
        $this->refund($hold, ['amount' => '20'])->assertSessionHas('success');

        $this->postStripeWebhook($this->stripeEvent('refund.created', [
            'id' => 're_order', 'object' => 'refund', 'amount' => 2000, 'currency' => 'usd', 'status' => 'pending',
            'payment_intent' => $hold->payment->payment_intent_id,
        ]))->assertOk();

        $this->assertSame('succeeded', Refund::sole()->status);
        $this->stripeRefundOk('re_order_2');
        $this->refund($hold, ['amount' => '10'])->assertSessionHas('success');
        $this->assertSame('30.00', $hold->payment->refresh()->refunded_amount);
    }

    public function test_a_failed_refund_moves_the_withdrawal_key_on(): void
    {
        $hold = $this->deposit(100, hold: ['remaining_amount' => 0, 'status' => 'transferred']);
        $this->transferOut($hold, 100);
        $stripe = app(\App\Services\StripeService::class);
        $keyBefore = $stripe->transferIdempotencyKey($hold->id, 100, 100);

        $this->connectBalance(10000);
        $this->stripe->respond('POST /v1/transfers/tr_out_1/reversals', ['id' => 'trr_k', 'object' => 'transfer_reversal', 'amount' => 10000]);
        $this->stripe->respond('POST /v1/refunds', ['error' => ['type' => 'card_error', 'message' => 'The card was closed.']], 402);
        $this->refund($hold)->assertSessionHasErrors('refund');

        // The vault is back to $100, but a new withdrawal must not replay the reversed transfer's key.
        $this->assertSame('100.00', $hold->refresh()->remaining_amount);
        $this->assertNotSame($keyBefore, $stripe->transferIdempotencyKey($hold->id, 100, 100));
    }

    public function test_refunds_page_lists_refunds_for_admins(): void
    {
        $hold = $this->deposit(75);
        Refund::factory()->create(['payment_id' => $hold->payment_id, 'payment_hold_id' => $hold->id, 'user_id' => $this->user->id, 'amount' => 25, 'arn' => '74567890123456789012345']);
        $hold->payment->update(['refunded_amount' => 25]);

        $this->actingAs($this->admin)->get(route('admin.refunds.index'))
            ->assertOk()
            ->assertSee('John Miller')
            ->assertSee('$25.00')
            ->assertSee('Partial')
            ->assertSee('74567890123456789012345');

        $this->actingAs($this->admin)->get(route('admin.payment-holds.index'))
            ->assertOk()
            ->assertSee('Refund deposit')
            ->assertSee('Refunded $25.00');
        $this->actingAs($this->admin)->get(route('admin.refunds.export', ['format' => 'xlsx']))->assertOk();
    }
}
