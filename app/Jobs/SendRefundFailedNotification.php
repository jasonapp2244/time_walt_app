<?php

namespace App\Jobs;

use App\Mail\Stripe\AdminRefundFailedMail;
use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Alerts the admin that a refund failed or needs review. The user is not
 * emailed: their money is back in the vault, or not yet moved.
 */
class SendRefundFailedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Refund $refund
    ) {}

    public function handle(): void
    {
        if ($adminEmail = config('mail.admin_email')) {
            $this->refund->loadMissing(['user', 'admin', 'payment', 'hold']);

            Mail::to($adminEmail)->send(new AdminRefundFailedMail($this->refund));
        }
    }
}
