<?php

namespace App\Services;

use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\Log;

/**
 * Refunds made in the Stripe Dashboard on a subscription payment. They are
 * recorded on the subscription invoice for the admin (no vault money is
 * involved, so balances never change). Chargebacks on subscription payments go
 * through DisputeService, which uses findInvoice() from here.
 *
 * Idempotent: the refunded total is taken from the charge's cumulative
 * amount_refunded, so repeated events always write the same value.
 */
class SubscriptionChargeService
{
    /** From refund.created / refund.updated (a refund object). */
    public function recordFromRefund(array $stripeRefund): bool
    {
        $invoice = $this->findInvoice($stripeRefund['payment_intent'] ?? null);

        if ($invoice === null || ! is_string($stripeRefund['charge'] ?? null)) {
            return false;
        }

        $this->setStripe();

        try {
            $refundedCents = (int) \Stripe\Charge::retrieve($stripeRefund['charge'])->amount_refunded;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::warning('Could not read a refunded subscription charge from Stripe', ['error' => $e->getMessage()]);

            return true; // It is a subscription payment; charge.refunded will set the total.
        }

        $this->setRefunded($invoice, $refundedCents);

        return true;
    }

    /** From charge.refunded (a charge object, with the cumulative total). */
    public function recordFromCharge(array $charge): bool
    {
        $invoice = $this->findInvoice($charge['payment_intent'] ?? null);

        if ($invoice === null) {
            return false;
        }

        $this->setRefunded($invoice, (int) ($charge['amount_refunded'] ?? 0));

        return true;
    }

    /** The subscription invoice a PaymentIntent paid, if any. */
    public function findInvoice(mixed $paymentIntent): ?SubscriptionInvoice
    {
        if (! is_string($paymentIntent) || $paymentIntent === '') {
            return null;
        }

        $this->setStripe();

        try {
            $payments = \Stripe\InvoicePayment::all([
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntent],
                'limit' => 1,
            ])->toArray()['data'] ?? [];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::warning('Could not look up the invoice of a payment in Stripe', ['error' => $e->getMessage()]);

            return null;
        }

        $invoiceId = $payments[0]['invoice'] ?? null;
        $invoiceId = is_array($invoiceId) ? ($invoiceId['id'] ?? null) : $invoiceId;

        return is_string($invoiceId)
            ? SubscriptionInvoice::where('stripe_invoice_id_index', SubscriptionInvoice::blindIndex($invoiceId))->first()
            : null;
    }

    private function setRefunded(SubscriptionInvoice $invoice, int $refundedCents): void
    {
        if ($refundedCents <= 0 || (int) round((float) $invoice->refunded_amount * 100) === $refundedCents) {
            return;
        }

        $invoice->update([
            'refunded_amount' => min($refundedCents, (int) round((float) $invoice->amount * 100)) / 100,
            'refunded_at' => $invoice->refunded_at ?? now(),
        ]);

        Log::info('Subscription payment refunded in Stripe', ['invoice_id' => $invoice->id, 'refunded' => $refundedCents / 100]);
    }

    private function setStripe(): void
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }
}
