<?php

namespace App\Console\Commands;

use App\Jobs\SendTransferCompletedNotification;
use App\Jobs\SendTransferCompletedSummaryNotification;
use App\Models\Transfer;
use App\Models\UserNotificationSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyPendingTransfers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verify:pending-transfers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify pending transfers with Stripe and send success emails';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Verifying pending transfers with Stripe...');

        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        // Find all pending transfers
        $transfers = Transfer::where('status', 'pending')
            ->whereNotNull('stripe_transfer_id')
            ->get();

        if ($transfers->isEmpty()) {
            $this->info('No pending transfers to verify.');

            return Command::SUCCESS;
        }

        $successCount = 0;
        $failedCount = 0;
        $completedTransfersByUser = []; // Group completed transfers by user

        foreach ($transfers as $transfer) {
            try {
                // Verify transfer status with Stripe
                $stripeTransfer = \Stripe\Transfer::retrieve($transfer->stripe_transfer_id);

                Log::info('Stripe transfer status checked', [
                    'transfer_id' => $transfer->id,
                    'stripe_id_suffix' => substr($transfer->stripe_transfer_id, -6),
                    'stripe_status' => $stripeTransfer->status ?? 'unknown',
                ]);

                // Check if transfer succeeded
                if (isset($stripeTransfer->id) && ! isset($stripeTransfer->failure_message)) {
                    // Transfer succeeded
                    $transfer->update([
                        'status' => 'completed',
                        'transferred_at' => now(),
                        'stripe_data' => $stripeTransfer->toArray(),
                    ]);

                    // Update hold status (remaining_amount was already deducted in withdraw())
                    if ($transfer->hold) {
                        $hold = $transfer->hold;
                        $currentRemaining = $hold->remaining_amount ?? $hold->amount;

                        // Only update status, do NOT deduct remaining_amount again
                        $hold->update([
                            'status' => $currentRemaining <= 0 ? 'transferred' : 'partial_transferred',
                            'transferred_at' => $currentRemaining <= 0 ? now() : $hold->transferred_at,
                        ]);
                    }

                    // Collect completed transfers by user for batching
                    if (! isset($completedTransfersByUser[$transfer->user_id])) {
                        $completedTransfersByUser[$transfer->user_id] = [];
                    }
                    $completedTransfersByUser[$transfer->user_id][] = $transfer;

                    $this->info("✅ Transfer ID: {$transfer->id} verified and completed");
                    $successCount++;
                } elseif (isset($stripeTransfer->failure_message)) {
                    // Transfer failed
                    $transfer->update([
                        'status' => 'failed',
                        'failure_reason' => $stripeTransfer->failure_message,
                        'stripe_data' => $stripeTransfer->toArray(),
                    ]);

                    // Restore remaining_amount since money was never moved
                    if ($transfer->hold) {
                        $hold = $transfer->hold;
                        $restoredAmount = ($hold->remaining_amount ?? 0) + $transfer->amount;
                        $hold->update([
                            'remaining_amount' => $restoredAmount,
                            'status' => $restoredAmount >= $hold->amount ? 'ready_for_transfer' : 'partial_transferred',
                        ]);
                        Log::info('Restored remaining_amount after failed transfer', [
                            'hold_id' => $hold->id,
                            'restored_amount' => $transfer->amount,
                            'new_remaining' => $restoredAmount,
                        ]);
                    }

                    $this->error("❌ Transfer ID: {$transfer->id} failed: {$stripeTransfer->failure_message}");
                    $failedCount++;
                }
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $this->error("❌ Stripe API error for transfer ID: {$transfer->id} - {$e->getMessage()}");
                Log::error('Stripe transfer verification failed', [
                    'transfer_id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
                $failedCount++;
            } catch (\Exception $e) {
                $this->error("❌ Error verifying transfer ID: {$transfer->id} - {$e->getMessage()}");
                Log::error('Transfer verification failed', [
                    'transfer_id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
                $failedCount++;
            }
        }

        // Send batched email notifications for each user
        foreach ($completedTransfersByUser as $userId => $userTransfers) {
            $userSettings = UserNotificationSetting::where('user_id', $userId)->first();
            $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

            if ($shouldSendEmail && ! empty($userTransfers)) {
                // Load relationships
                $transfers = Transfer::whereIn('id', collect($userTransfers)->pluck('id')->toArray())
                    ->with(['hold.payment', 'user'])
                    ->get();

                if ($transfers->count() > 1) {
                    // Multiple transfers - send summary email
                    $totalAmount = $transfers->sum('amount');
                    SendTransferCompletedSummaryNotification::dispatch(
                        $transfers->first()->user,
                        $transfers,
                        $totalAmount
                    );
                    $this->info("✅ Summary email sent for user ID: {$userId} ({$transfers->count()} transfers)");
                } else {
                    // Single transfer - send individual email
                    SendTransferCompletedNotification::dispatch($transfers->first());
                    $this->info("✅ Success email sent for transfer ID: {$transfers->first()->id}");
                }
            }
        }

        $this->info("✅ Verified {$successCount} successful transfers");
        if ($failedCount > 0) {
            $this->warn("⚠️  {$failedCount} transfers failed or had errors");
        }

        return Command::SUCCESS;
    }
}
