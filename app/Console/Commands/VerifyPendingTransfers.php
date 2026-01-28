<?php

namespace App\Console\Commands;

use App\Jobs\SendTransferCompletedNotification;
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

        foreach ($transfers as $transfer) {
            try {
                // Verify transfer status with Stripe
                $stripeTransfer = \Stripe\Transfer::retrieve($transfer->stripe_transfer_id);

                Log::info('Stripe transfer status checked', [
                    'transfer_id' => $transfer->id,
                    'stripe_transfer_id' => $transfer->stripe_transfer_id,
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

                    // Update hold status
                    if ($transfer->hold) {
                        $transfer->hold->update([
                            'status' => 'transferred',
                            'transferred_at' => now(),
                        ]);
                    }

                    // Send success email to user and admin (check both transaction_alert and email_alert)
                    $userSettings = UserNotificationSetting::where('user_id', $transfer->user_id)->first();
                    $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

                    if ($shouldSendEmail) {
                        SendTransferCompletedNotification::dispatch($transfer);
                        $this->info("✅ Success email sent for transfer ID: {$transfer->id}");
                    } else {
                        $this->info("📧 Email skipped (user settings) for transfer ID: {$transfer->id}");
                    }

                    $this->info("✅ Transfer ID: {$transfer->id} verified and completed");
                    $successCount++;
                } elseif (isset($stripeTransfer->failure_message)) {
                    // Transfer failed
                    $transfer->update([
                        'status' => 'failed',
                        'failure_reason' => $stripeTransfer->failure_message,
                        'stripe_data' => $stripeTransfer->toArray(),
                    ]);

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

        $this->info("✅ Verified {$successCount} successful transfers");
        if ($failedCount > 0) {
            $this->warn("⚠️  {$failedCount} transfers failed or had errors");
        }

        return Command::SUCCESS;
    }
}
