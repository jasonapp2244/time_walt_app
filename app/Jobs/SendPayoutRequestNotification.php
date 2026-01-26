<?php

namespace App\Jobs;

use App\Mail\Stripe\PayoutRequestMail;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPayoutRequestNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Transfer $transfer
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Send to user
        Mail::to($this->transfer->user->email)
            ->send(new PayoutRequestMail($this->transfer));

        // Send to admin
        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)
                ->send(new PayoutRequestMail($this->transfer));
        }
    }
}
