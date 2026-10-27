<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeService
{
    private const TRANSFER_ATTEMPT_CACHE = 'stripe-transfer-attempt:';

    /**
     * Set Stripe API key.
     */
    public function __construct()
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create Stripe Transfer to user's Connect account.
     *
     * The hold is re-read under a row lock and the transfer is built from that
     * copy, not from the model the caller loaded. Two callers working from the
     * same stale model (a double-tapped request-payout, the cron racing an
     * admin, or withdraw() draining the hold meanwhile) therefore cannot pay
     * the same money out twice: the second waits for the first to commit and
     * then finds nothing left.
     */
    public function createTransfer(PaymentHold $hold, string $type = 'manual'): Transfer
    {
        $transfer = null;

        try {
            $connectAccount = StripeConnectAccount::where('user_id', $hold->user_id)->first();

            if (! $connectAccount) {
                throw new \Exception("Stripe Connect account not found for user {$hold->user_id}. Please add bank details first.");
            }

            if (! $connectAccount->payouts_enabled) {
                throw new \Exception("Stripe Connect account for user {$hold->user_id} does not have payouts enabled. Status: {$connectAccount->status}");
            }

            $transferRecord = DB::transaction(function () use ($hold, $type, $connectAccount, &$transfer) {
                $locked = PaymentHold::whereKey($hold->id)->lockForUpdate()->firstOrFail();

                // Use remaining_amount if available, otherwise use amount
                $transferAmount = $locked->remaining_amount ?? $locked->amount;

                if ($locked->status === 'transferred' || $transferAmount <= 0) {
                    throw new \Exception("Hold #{$locked->id} has nothing left to transfer.");
                }

                // Get currency from payment
                $payment = $locked->payment;
                $currency = $payment ? strtolower($payment->currency) : 'usd';

                // Create transfer in Stripe (real API call - no simulation)
                try {
                    $transfer = \Stripe\Transfer::create($this->withSourceTransaction([
                        'amount' => (int) round($transferAmount * 100), // Convert to cents
                        'currency' => $currency,
                        'destination' => $connectAccount->connect_account_id,
                        'description' => "Transfer for hold #{$locked->id}",
                        'metadata' => [
                            'hold_id' => $locked->id,
                            'user_id' => $locked->user_id,
                            'payment_id' => $payment ? $payment->id : null,
                        ],
                    ], $payment), [
                        'idempotency_key' => $this->transferIdempotencyKey($locked->id, (float) $transferAmount, (float) $transferAmount),
                    ]);
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    $this->transferRejected($locked->id);

                    throw $e;
                }

                // Create transfer record
                $adminId = null;
                if (\Illuminate\Support\Facades\Auth::check()) {
                    $user = \Illuminate\Support\Facades\Auth::user();
                    $adminId = ($user && isset($user->role) && $user->role === 'admin') ? $user->id : null;
                }

                $primaryBank = UserBankAccount::where('user_id', $locked->user_id)
                    ->where('is_primary', true)
                    ->first();

                $transferRecord = Transfer::create([
                    'hold_id' => $locked->id,
                    'user_id' => $locked->user_id,
                    'bank_account_id' => $primaryBank?->id,
                    'stripe_transfer_id' => $transfer->id,
                    'stripe_connect_account_id' => $connectAccount->connect_account_id,
                    'amount' => $transferAmount,
                    'currency' => $currency,
                    'status' => 'pending',
                    'transfer_type' => $type,
                    'admin_id' => $adminId,
                    'stripe_data' => $transfer->toArray(),
                ]);

                // The whole remaining amount was sent, so the hold is now empty
                $locked->update([
                    'remaining_amount' => 0,
                    'status' => 'transferred',
                    'transferred_at' => now(),
                ]);

                return $transferRecord;
            });

            $hold->refresh();

            return $transferRecord;
        } catch (\Exception $e) {
            // Stripe has the money moving but the rollback erased our record of
            // it. A retry within 24 hours reuses the idempotency key and records
            // this same transfer; until then nothing shows it, so say so loudly.
            if ($transfer !== null) {
                Log::critical('Stripe transfer was sent but could not be recorded; reconcile it by hand.', [
                    'hold_id' => $hold->id,
                    'user_id' => $hold->user_id,
                    'stripe_transfer_id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::error('Stripe Transfer Creation Failed: '.$e->getMessage(), [
                'hold_id' => $hold->id,
                'user_id' => $hold->user_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Idempotency key for a payout from a hold.
     *
     * If a request dies after Stripe sent the transfer but before the database
     * commit (crash, timeout, DB error), the rollback puts the money back on
     * the hold. The retry then sends the same key, and Stripe returns the
     * transfer it already made instead of paying twice. The key covers the
     * hold's balance and the amount, so the next real payout gets a new key.
     * Stripe also replays a rejection for a key, so a rejected attempt moves
     * the key on (transferRejected) and a later retry is a fresh request.
     */
    public function transferIdempotencyKey(int $holdId, float $available, float $amount): string
    {
        $attempt = (int) Cache::get(self::TRANSFER_ATTEMPT_CACHE.$holdId, 0);

        return sprintf('tv-hold-%d-%d-%d-%d', $holdId, round($available * 100), round($amount * 100), $attempt);
    }

    public function transferRejected(int $holdId): void
    {
        $key = self::TRANSFER_ATTEMPT_CACHE.$holdId;

        Cache::put($key, (int) Cache::get($key, 0) + 1, now()->addDays(2));
    }

    /**
     * Link a transfer to the deposit's charge via source_transaction.
     *
     * Without it Stripe draws the transfer from the platform's *available*
     * balance, so a withdrawal made before the deposit settles (~2 days for
     * cards) fails with "insufficient available funds". With it Stripe accepts
     * the transfer immediately and releases the funds once the charge clears.
     * When no charge can be resolved the params are returned unchanged, which
     * keeps the previous behaviour.
     */
    public function withSourceTransaction(array $params, ?Payment $payment): array
    {
        $chargeId = $this->resolveSourceChargeId($payment);

        if ($chargeId !== null) {
            $params['source_transaction'] = $chargeId;
        }

        return $params;
    }

    /**
     * Resolve the charge ID (ch_… / py_…) behind a payment: first from the
     * PaymentIntent snapshot stored in stripe_data, then from Stripe.
     */
    public function resolveSourceChargeId(?Payment $payment): ?string
    {
        if (! $payment) {
            return null;
        }

        $chargeId = $this->extractChargeId(data_get($payment->stripe_data, 'latest_charge'));

        if ($chargeId !== null) {
            return $chargeId;
        }

        $paymentIntentId = $payment->payment_intent_id;

        if (! is_string($paymentIntentId) || ! str_starts_with($paymentIntentId, 'pi_')) {
            return null;
        }

        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

            return $this->extractChargeId($paymentIntent->latest_charge ?? null);
        } catch (\Exception $e) {
            Log::warning('Could not resolve source charge for transfer: '.$e->getMessage(), [
                'payment_id' => $payment->id,
            ]);

            return null;
        }
    }

    /**
     * latest_charge is either the charge ID or, when expanded, the charge object.
     */
    private function extractChargeId(mixed $latestCharge): ?string
    {
        if (is_array($latestCharge) || is_object($latestCharge)) {
            $latestCharge = data_get($latestCharge, 'id');
        }

        if (is_string($latestCharge) && (str_starts_with($latestCharge, 'ch_') || str_starts_with($latestCharge, 'py_'))) {
            return $latestCharge;
        }

        return null;
    }

    /**
     * Get hold period data from cache or metadata.
     */
    public function getHoldPeriodData(string $paymentIntentId): ?array
    {
        // Try cache first
        $cached = Cache::get("payment_intent_hold_{$paymentIntentId}");

        if ($cached) {
            return $cached;
        }

        // Try to get from Stripe PaymentIntent metadata
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
            if (isset($paymentIntent->metadata->hold_period_type)) {
                return [
                    'hold_period_type' => $paymentIntent->metadata->hold_period_type,
                    'hold_start_at' => $paymentIntent->metadata->hold_start_at,
                    'hold_end_at' => $paymentIntent->metadata->hold_end_at,
                    'hold_days' => $paymentIntent->metadata->hold_days,
                    'hold_hours' => $paymentIntent->metadata->hold_hours ?? 0,
                    'hold_minutes' => $paymentIntent->metadata->hold_minutes ?? 0,
                    'title' => $paymentIntent->metadata->title ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::error('Failed to retrieve hold period data: '.$e->getMessage());
        }

        return null;
    }
}
