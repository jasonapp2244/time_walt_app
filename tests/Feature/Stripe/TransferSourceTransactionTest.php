<?php

namespace Tests\Feature\Stripe;

use App\Models\Payment;
use App\Services\StripeService;
use Tests\TestCase;

/**
 * Withdrawals must be linked to the deposit's charge (source_transaction) so
 * Stripe accepts them while the deposit is still in the pending balance.
 * None of these cases reach the Stripe API.
 */
class TransferSourceTransactionTest extends TestCase
{
    private function params(): array
    {
        return [
            'amount' => 5000,
            'currency' => 'usd',
            'destination' => 'acct_test',
        ];
    }

    public function test_it_links_the_transfer_to_the_stored_charge_id(): void
    {
        $payment = new Payment([
            'payment_intent_id' => 'pi_test_123',
            'stripe_data' => ['id' => 'pi_test_123', 'latest_charge' => 'ch_test_abc'],
        ]);

        $params = app(StripeService::class)->withSourceTransaction($this->params(), $payment);

        $this->assertSame('ch_test_abc', $params['source_transaction']);
        $this->assertSame(5000, $params['amount']);
    }

    public function test_it_reads_the_id_from_an_expanded_charge_object(): void
    {
        $payment = new Payment([
            'payment_intent_id' => 'pi_test_123',
            'stripe_data' => ['latest_charge' => ['id' => 'ch_expanded', 'object' => 'charge']],
        ]);

        $params = app(StripeService::class)->withSourceTransaction($this->params(), $payment);

        $this->assertSame('ch_expanded', $params['source_transaction']);
    }

    public function test_it_accepts_non_card_payment_ids(): void
    {
        $payment = new Payment([
            'payment_intent_id' => 'pi_test_123',
            'stripe_data' => ['latest_charge' => 'py_test_bank'],
        ]);

        $this->assertSame('py_test_bank', app(StripeService::class)->resolveSourceChargeId($payment));
    }

    public function test_params_are_unchanged_when_no_charge_can_be_resolved(): void
    {
        // Not a PaymentIntent ID, so there is nothing to look up in Stripe either.
        $payment = new Payment([
            'payment_intent_id' => 'cs_test_session',
            'stripe_data' => ['latest_charge' => null],
        ]);

        $service = app(StripeService::class);

        $this->assertSame($this->params(), $service->withSourceTransaction($this->params(), $payment));
        $this->assertSame($this->params(), $service->withSourceTransaction($this->params(), null));
    }

    public function test_it_ignores_values_that_are_not_charge_ids(): void
    {
        $payment = new Payment([
            'payment_intent_id' => 'cs_test_session',
            'stripe_data' => ['latest_charge' => 'pi_not_a_charge'],
        ]);

        $this->assertNull(app(StripeService::class)->resolveSourceChargeId($payment));
    }
}
