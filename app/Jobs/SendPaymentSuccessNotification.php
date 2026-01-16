<?php

namespace App\Jobs;

use App\Mail\Stripe\PaymentSuccessMail;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPaymentSuccessNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Payment $payment
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Send to user
        Mail::to($this->payment->user->email)
            ->send(new PaymentSuccessMail($this->payment));

        // Send to admin
        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)
                ->send(new PaymentSuccessMail($this->payment));
        }
    }
}
