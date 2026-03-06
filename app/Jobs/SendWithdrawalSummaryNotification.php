<?php

namespace App\Jobs;

use App\Mail\Stripe\WithdrawalSummaryMail;
use App\Models\User;
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
        Mail::to($this->user->email)
            ->send(new WithdrawalSummaryMail($this->user, $this->transfers, $this->requestedAmount, $this->processedAmount));

        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)
                ->send(new WithdrawalSummaryMail($this->user, $this->transfers, $this->requestedAmount, $this->processedAmount, isAdmin: true));
        }
    }
}
