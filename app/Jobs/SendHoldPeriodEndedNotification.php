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

    public function __construct(
        public PaymentHold $hold
    ) {}

    public function handle(): void
    {
        Mail::to($this->hold->user->email)
            ->send(new HoldPeriodEndedMail($this->hold, $this->hold->user));

        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)
                ->send(new HoldPeriodEndedMail($this->hold, $this->hold->user, isAdmin: true));
        }
    }
}
