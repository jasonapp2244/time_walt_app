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

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user,
        public $transfers,
        public float $requestedAmount,
        public float $processedAmount
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Send to user
        Mail::to($this->user->email)
            ->send(new WithdrawalSummaryMail(
                $this->user,
                $this->transfers,
                $this->requestedAmount,
                $this->processedAmount
            ));

        // Send to admin
        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)
                ->send(new WithdrawalSummaryMail(
                    $this->user,
                    $this->transfers,
                    $this->requestedAmount,
                    $this->processedAmount
                ));
        }
    }
}
