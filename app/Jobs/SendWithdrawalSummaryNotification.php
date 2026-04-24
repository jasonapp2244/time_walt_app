<?php

namespace App\Jobs;

use App\Mail\Stripe\WithdrawalSummaryMail;
use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendWithdrawalSummaryNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public $transfers,
        public float $requestedAmount,
        public float $processedAmount
    ) {}

    public function handle(): void
    {
        $userSettings = UserNotificationSetting::where('user_id', $this->user->id)->first();
        $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

        // Send to user (if notifications enabled)
        if ($shouldSendEmail) {
            Mail::to($this->user->email)
                ->send(new WithdrawalSummaryMail($this->user, $this->transfers, $this->requestedAmount, $this->processedAmount));
        }

        // Send to admin (always)
        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)
                ->send(new WithdrawalSummaryMail($this->user, $this->transfers, $this->requestedAmount, $this->processedAmount, isAdmin: true));
        }
    }
}
