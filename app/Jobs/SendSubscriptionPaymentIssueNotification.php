<?php

namespace App\Jobs;

use App\Mail\Concerns\SendsToUser;
use App\Mail\SubscriptionPaymentIssueMail;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the user, and the admin inbox, that a subscription renewal did not go
 * through: either the card was declined (failed) or the bank wants 3-D Secure
 * (action_required).
 */
class SendSubscriptionPaymentIssueNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsToUser, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public string $kind,
        public ?string $invoiceUrl = null
    ) {}

    public function handle(): void
    {
        $this->subscription->load(['user', 'plan']);

        if ($adminEmail = config('mail.admin_email')) {
            Mail::to($adminEmail)->send(new SubscriptionPaymentIssueMail($this->subscription, $this->kind, $this->invoiceUrl, isAdmin: true));
        }

        $email = $this->readableEmail($this->subscription->user);

        if (! $email) {
            Log::warning('Subscription payment issue email skipped: no readable user address', [
                'subscription_id' => $this->subscription->id,
                'user_id' => $this->subscription->user_id,
            ]);

            return;
        }

        try {
            Mail::to($email)->send(new SubscriptionPaymentIssueMail($this->subscription, $this->kind, $this->invoiceUrl));

            Log::info('Subscription payment issue email sent', [
                'subscription_id' => $this->subscription->id,
                'kind' => $this->kind,
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription payment issue email failed', [
                'subscription_id' => $this->subscription->id,
                'kind' => $this->kind,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
