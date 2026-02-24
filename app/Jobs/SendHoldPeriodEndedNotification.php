<?php

namespace App\Jobs;

use App\Mail\Stripe\HoldPeriodEndedMail;
use App\Models\PaymentHold;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendHoldPeriodEndedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PaymentHold $hold
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Send to user
        Mail::to($this->hold->user->email)
            ->send(new HoldPeriodEndedMail($this->hold, $this->hold->user));

        // Send to admin
        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)
                ->send(new HoldPeriodEndedMail($this->hold, $this->hold->user));
        }
    }
}
