<?php

namespace App\Jobs;

use App\Mail\Concerns\SendsToUser;
use App\Mail\Stripe\SubscriptionPaymentMail;
use App\Models\SubscriptionInvoice;
use App\Models\UserNotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A subscription payment succeeded (first payment or automatic renewal):
 * a receipt to the user and a notice to the admin inbox.
 *
 * The user copy follows the same switches as deposit emails (transaction
 * alerts + email alerts). The admin copy is always sent.
 */
class SendSubscriptionPaymentNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsToUser, SerializesModels;

    public function __construct(
        public SubscriptionInvoice $invoice
    ) {}

    public function handle(): void
    {
        $this->invoice->load(['user', 'subscription.plan']);

        $settings = UserNotificationSetting::where('user_id', $this->invoice->user_id)->first();
        $userWantsEmail = ! $settings || ($settings->transaction_alert && $settings->email_alert);

        if ($userWantsEmail && ($email = $this->readableEmail($this->invoice->user))) {
            Mail::to($email)->send(new SubscriptionPaymentMail($this->invoice));
        } elseif ($userWantsEmail) {
            Log::warning('Subscription payment receipt skipped: no readable user address', [
                'subscription_invoice_id' => $this->invoice->id,
                'user_id' => $this->invoice->user_id,
            ]);
        }

        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)->send(new SubscriptionPaymentMail($this->invoice, isAdmin: true));
        }

        Log::info('Subscription payment emails sent', [
            'subscription_invoice_id' => $this->invoice->id,
            'renewal' => $this->invoice->isRenewal(),
            'user_copy' => $userWantsEmail,
        ]);
    }
}
