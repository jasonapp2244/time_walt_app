<?php

namespace App\Console\Commands;

use App\Jobs\SendHoldPeriodEndedNotification;
use App\Models\PaymentHold;
use App\Services\PaymentHoldService;
use App\Services\StripeService;
use Illuminate\Console\Command;

class CheckPaymentHolds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:payment-holds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check payment holds where hold period has ended and mark as ready for transfer';

    /**
     * Execute the console command.
     */
    public function handle(PaymentHoldService $paymentHoldService, StripeService $stripeService): int
    {
        $this->info('Checking payment holds...');

        // Find holds ready for transfer
        $holds = PaymentHold::where('status', 'holding')
            ->where('hold_end_at', '<=', now())
            ->get();

        if ($holds->isEmpty()) {
            $this->info('No holds ready for transfer.');

            return Command::SUCCESS;
        }

        $count = 0;

        foreach ($holds as $hold) {
            // Mark as ready
            $hold->update([
                'status' => 'ready_for_transfer',
                'ready_at' => now(),
            ]);

            // Send email notification (check both transaction_alert and email_alert)
            $userSettings = \App\Models\UserNotificationSetting::where('user_id', $hold->user_id)->first();
            $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

            if ($shouldSendEmail) {
                SendHoldPeriodEndedNotification::dispatch($hold);
            }

            // Optionally: Auto create transfer if enabled
            if (config('services.stripe.auto_transfer_enabled')) {
                try {
                    $stripeService->createTransfer($hold, 'cron');
                    $this->info("Transfer created for hold ID: {$hold->id}");
                } catch (\Exception $e) {
                    $this->error("Failed to create transfer for hold ID: {$hold->id} - {$e->getMessage()}");
                }
            }

            $count++;
        }

        $this->info("Processed {$count} holds.");

        return Command::SUCCESS;
    }
}
