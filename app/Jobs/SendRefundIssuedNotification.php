<?php

namespace App\Jobs;

use App\Mail\Concerns\SendsToUser;
use App\Mail\Stripe\AdminRefundIssuedMail;
use App\Mail\Stripe\RefundIssuedMail;
use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the user their deposit is on its way back to their card, and gives
 * the admin the full record of the refund.
 */
class SendRefundIssuedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsToUser, SerializesModels;

    public function __construct(
        public Refund $refund
    ) {}

    public function handle(): void
    {
        $this->refund->loadMissing(['user', 'admin', 'payment', 'hold']);

        if ($email = $this->readableEmail($this->refund->user)) {
            Mail::to($email)->send(new RefundIssuedMail($this->refund));
        }

        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)->send(new AdminRefundIssuedMail($this->refund));
        }
    }
}
