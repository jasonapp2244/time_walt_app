<?php

namespace App\Jobs;

use App\Mail\Stripe\AdminManualReversalMail;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the admin that a withdrawal was reversed by hand in Stripe: what went
 * back into the user's vault, and what settled money the user owed.
 */
class SendManualReversalNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Transfer $transfer,
        public float $amount,
        public float $covered = 0.0
    ) {}

    public function handle(): void
    {
        if ($adminEmail = config('mail.admin_email')) {
            $this->transfer->loadMissing(['user', 'hold']);

            Mail::to($adminEmail)->send(new AdminManualReversalMail($this->transfer, $this->amount, $this->covered));
        }
    }
}
