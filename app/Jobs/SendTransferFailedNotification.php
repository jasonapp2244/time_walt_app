<?php

namespace App\Jobs;

use App\Mail\Stripe\TransferFailedMail;
use App\Models\Transfer;
use App\Models\UserNotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendTransferFailedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Transfer $transfer,
        public string $reason
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $userSettings = UserNotificationSetting::where('user_id', $this->transfer->user_id)->first();
        $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

        // Send to user (if notifications enabled)
        if ($shouldSendEmail) {
            Mail::to($this->transfer->user->email)
                ->send(new TransferFailedMail($this->transfer, $this->reason));
        }

        // Send to admin (always — critical)
        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)
                ->send(new TransferFailedMail($this->transfer, $this->reason));
        }
    }
}
