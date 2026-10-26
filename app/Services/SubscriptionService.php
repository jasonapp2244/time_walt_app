<?php

namespace App\Services;

use App\Jobs\SendSubscriptionPaymentIssueNotification;
use App\Jobs\SendSubscriptionPaymentNotification;
use App\Models\StripeCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Stripe side of subscriptions: plan sync, subscribe, activation, cancel and
 * the webhook updates. Field paths follow Stripe API 2025-12-15.clover, where
 * the billing period lives on the subscription item and an invoice's client
 * secret is its confirmation_secret.
 *
 * Only Stripe's own data ever activates a subscription; nothing the app sends
 * is trusted for that.
 */
class SubscriptionService
{
    /** Stripe keeps an unpaid first invoice open this long before expiring it. */
    private const INCOMPLETE_WINDOW_HOURS = 23;

    public function __construct(
        protected PaymentSheetService $paymentSheetService
    ) {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create or update the plan's Stripe Product and Price.
     *
     * Stripe Prices cannot be edited, so a change of amount, currency or
     * interval creates a new Price and archives the old one. Existing
     * subscribers stay on the price they signed up at.
     */
    public function syncPlanToStripe(SubscriptionPlan $plan): void
    {
        $productData = [
            'name' => $plan->name,
            'description' => $plan->description ?: '',
            'active' => (bool) $plan->is_active,
        ];

        if ($plan->stripe_product_id) {
            \Stripe\Product::update($plan->stripe_product_id, $productData);
        } else {
            if ($productData['description'] === '') {
                unset($productData['description']);
            }

            $product = \Stripe\Product::create($productData + [
                'metadata' => ['subscription_plan_id' => (string) $plan->id],
            ]);
            $plan->stripe_product_id = $product->id;
        }

        $priceChanged = ! $plan->stripe_price_id
            || $plan->isDirty(['price', 'currency', 'interval']);

        if ($priceChanged) {
            $oldPriceId = $plan->stripe_price_id;

            $price = \Stripe\Price::create([
                'product' => $plan->stripe_product_id,
                'unit_amount' => $plan->priceInCents(),
                'currency' => strtolower($plan->currency),
                'recurring' => ['interval' => $plan->interval],
                'metadata' => ['subscription_plan_id' => (string) $plan->id],
            ]);
            $plan->stripe_price_id = $price->id;

            if ($oldPriceId) {
                \Stripe\Price::update($oldPriceId, ['active' => false]);
            }
        }

        $plan->save();
    }

    /**
     * Start a subscription and return what the Payment Sheet needs: the same
     * keys as create-payment-intent, so the app reuses its payment code.
     */
    public function subscribe(User $user, SubscriptionPlan $plan): array
    {
        $customer = $this->paymentSheetService->getOrCreateCustomer($user);

        $stripeSubscription = $this->reusableIncomplete($user, $plan);

        // Only one unpaid attempt may stay open, so a user cannot end up
        // paying two subscriptions after switching plans on the paywall.
        $this->cancelOpenAttempts($user, $stripeSubscription?->id);

        if (! $stripeSubscription) {
            $stripeSubscription = \Stripe\Subscription::create([
                'customer' => $customer->stripe_customer_id,
                'items' => [['price' => $plan->stripe_price_id]],
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                'expand' => ['latest_invoice.confirmation_secret'],
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'subscription_plan_id' => (string) $plan->id,
                ],
            ]);
        }

        $subscription = $this->syncFromStripe($stripeSubscription);

        Log::info('Subscription started', [
            'subscription_id' => $subscription?->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => $stripeSubscription->status,
        ]);

        return [
            'subscription_id' => $subscription?->id,
            'status' => $stripeSubscription->status,
            'client_secret' => $stripeSubscription->latest_invoice->confirmation_secret->client_secret ?? null,
            'customer_id' => $customer->stripe_customer_id,
            'ephemeral_key' => $this->paymentSheetService->createEphemeralKey($customer->stripe_customer_id),
            'publishable_key' => config('services.stripe.publishable_key'),
            'amount' => (float) $plan->price,
            'currency' => strtolower($plan->currency),
            'plan' => $plan->toApiArray(),
        ];
    }

    /**
     * A recent unpaid attempt at the same plan, so tapping Subscribe twice
     * does not leave two open subscriptions in Stripe.
     */
    private function reusableIncomplete(User $user, SubscriptionPlan $plan): ?\Stripe\Subscription
    {
        $pending = Subscription::where('user_id', $user->id)
            ->where('subscription_plan_id', $plan->id)
            ->where('status', 'incomplete')
            ->where('created_at', '>', now()->subHours(self::INCOMPLETE_WINDOW_HOURS))
            ->latest('id')
            ->first();

        if (! $pending) {
            return null;
        }

        try {
            $stripeSubscription = \Stripe\Subscription::retrieve([
                'id' => $pending->stripe_subscription_id,
                'expand' => ['latest_invoice.confirmation_secret'],
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::warning('Could not reuse incomplete subscription', [
                'subscription_id' => $pending->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $secret = $stripeSubscription->latest_invoice->confirmation_secret->client_secret ?? null;
        // A price changed since this attempt would bill something the app no longer shows.
        $samePrice = ($stripeSubscription->items->data[0]->price->id ?? null) === $plan->stripe_price_id;

        return $stripeSubscription->status === 'incomplete' && $secret && $samePrice ? $stripeSubscription : null;
    }

    /**
     * Called by the app right after the Payment Sheet succeeds: read the
     * user's latest subscription back from Stripe so access starts now rather
     * than when the webhook arrives.
     */
    public function confirm(User $user): ?Subscription
    {
        $latest = $user->subscriptions()->first();

        if (! $latest) {
            return null;
        }

        // The sheet may have paid an older attempt that subscribe() reused.
        if ($active = $this->refreshPending($user)) {
            return $active;
        }

        return $this->pendingAttempts($user)->whereKey($latest->id)->exists()
            ? $latest->fresh() // Just read back above.
            : $this->syncFromStripe($latest->stripe_subscription_id);
    }

    /**
     * Re-read the user's recent unpaid attempts from Stripe, so one that was
     * paid but whose webhook has not arrived yet is seen as active. Returns
     * the subscription that now grants access, if any.
     */
    public function refreshPending(User $user): ?Subscription
    {
        foreach ($this->pendingAttempts($user)->get() as $subscription) {
            $this->syncFromStripe($subscription->stripe_subscription_id);
        }

        return $user->activeSubscription()->first();
    }

    /**
     * The user's unpaid attempts that Stripe may still let them pay.
     */
    private function pendingAttempts(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return Subscription::where('user_id', $user->id)
            ->where('status', 'incomplete')
            ->where('created_at', '>', now()->subHours(self::INCOMPLETE_WINDOW_HOURS));
    }

    /**
     * Cancel the user's other unpaid attempts in Stripe. Each is read back
     * first and only cancelled while still unpaid.
     */
    private function cancelOpenAttempts(User $user, ?string $keepStripeId): void
    {
        $open = Subscription::where('user_id', $user->id)->where('status', 'incomplete')->get();

        foreach ($open as $subscription) {
            if ($subscription->stripe_subscription_id === $keepStripeId) {
                continue;
            }

            $stripeSubscription = \Stripe\Subscription::retrieve($subscription->stripe_subscription_id);

            if ($stripeSubscription->status === 'incomplete') {
                $stripeSubscription = $stripeSubscription->cancel();
            }

            $this->syncFromStripe($stripeSubscription);
        }
    }

    /**
     * Stop renewing at the end of the paid period; access continues until then.
     */
    public function cancelAtPeriodEnd(Subscription $subscription): Subscription
    {
        $stripeSubscription = \Stripe\Subscription::update($subscription->stripe_subscription_id, [
            'cancel_at_period_end' => true,
        ]);

        return $this->syncFromStripe($stripeSubscription);
    }

    /**
     * End the subscription immediately (admin action).
     */
    public function cancelNow(Subscription $subscription): Subscription
    {
        $stripeSubscription = \Stripe\Subscription::retrieve($subscription->stripe_subscription_id);
        $stripeSubscription = $stripeSubscription->cancel();

        return $this->syncFromStripe($stripeSubscription);
    }

    /**
     * A Stripe Customer Portal link where the user can change their card or
     * see invoices.
     */
    public function portalUrl(User $user): string
    {
        $customer = $this->paymentSheetService->getOrCreateCustomer($user);

        $params = ['customer' => $customer->stripe_customer_id];

        if ($returnUrl = config('services.stripe.portal_return_url')) {
            $params['return_url'] = $returnUrl;
        }

        return \Stripe\BillingPortal\Session::create($params)->url;
    }

    /**
     * Mirror a Stripe subscription into the local table. Accepts the Stripe
     * object, its webhook array, or just its ID (then it is fetched).
     *
     * Upserts by blind index, so webhooks and API responses may arrive in any
     * order. Returns null if the subscription cannot be tied to a user and plan.
     */
    public function syncFromStripe(\Stripe\Subscription|array|string $source): ?Subscription
    {
        $data = match (true) {
            is_string($source) => \Stripe\Subscription::retrieve($source)->toArray(),
            is_array($source) => $source,
            default => $source->toArray(),
        };

        $subscription = Subscription::findByStripeId($data['id']);

        $userId = $subscription?->user_id ?? $this->resolveUserId($data);
        // The price Stripe bills now decides the plan, so a switch between
        // monthly and yearly is reflected; the stored plan covers prices
        // that are no longer in the plans table.
        $planId = $this->planIdForPrice($data)
            ?? $subscription?->subscription_plan_id
            ?? $this->resolvePlanId($data);

        if (! $userId || ! $planId) {
            Log::warning('Stripe subscription could not be matched to a user and plan', [
                'stripe_subscription_suffix' => substr($data['id'], -6),
                'user_id' => $userId,
                'plan_id' => $planId,
            ]);

            return null;
        }

        $periodEnd = $data['items']['data'][0]['current_period_end'] ?? null;

        $attributes = [
            'user_id' => $userId,
            'subscription_plan_id' => $planId,
            'status' => $data['status'],
            'current_period_end' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : null,
            'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
            'canceled_at' => ! empty($data['canceled_at']) ? Carbon::createFromTimestamp($data['canceled_at']) : null,
        ];

        if ($subscription) {
            $subscription->update($attributes);

            return $subscription;
        }

        return Subscription::create($attributes + [
            'provider' => 'stripe',
            'stripe_subscription_id' => $data['id'],
        ]);
    }

    /**
     * invoice.paid / invoice.payment_failed / invoice.payment_action_required.
     */
    public function handleInvoiceEvent(array $invoice, string $eventType): void
    {
        $stripeSubscriptionId = $invoice['parent']['subscription_details']['subscription']
            ?? $invoice['subscription']
            ?? null;

        if (! $stripeSubscriptionId) {
            return; // Not a subscription invoice.
        }

        $subscription = $this->syncFromStripe($stripeSubscriptionId);

        if (! $subscription) {
            return;
        }

        if ($eventType === 'invoice.paid') {
            $this->recordPaidInvoice($subscription, $invoice);

            return;
        }

        // The first invoice is paid in the app's Payment Sheet, which shows its
        // own error; only renewals need an email.
        if (($invoice['billing_reason'] ?? null) === 'subscription_create') {
            return;
        }

        SendSubscriptionPaymentIssueNotification::dispatch(
            $subscription,
            $eventType === 'invoice.payment_action_required' ? 'action_required' : 'failed',
            $invoice['hosted_invoice_url'] ?? null
        );
    }

    /**
     * Store a paid invoice and send the payment emails (user + admin).
     *
     * Keyed on the Stripe invoice ID, so a webhook Stripe delivers twice, or a
     * retried event, records and emails the payment exactly once. Zero-amount
     * invoices (nothing was charged) are not recorded.
     */
    private function recordPaidInvoice(Subscription $subscription, array $invoice): void
    {
        $amountPaid = (int) ($invoice['amount_paid'] ?? 0);

        if ($amountPaid <= 0 || empty($invoice['id'])) {
            return;
        }

        if (SubscriptionInvoice::where('stripe_invoice_id_index', SubscriptionInvoice::blindIndex($invoice['id']))->exists()) {
            return;
        }

        $periodEnd = $invoice['lines']['data'][0]['period']['end'] ?? null;
        $paidAt = $invoice['status_transitions']['paid_at'] ?? null;

        try {
            $record = SubscriptionInvoice::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'stripe_invoice_id' => $invoice['id'],
                'invoice_number' => $invoice['number'] ?? null,
                'billing_reason' => $invoice['billing_reason'] ?? 'subscription_cycle',
                'amount' => $amountPaid / 100,
                'currency' => strtolower($invoice['currency'] ?? 'usd'),
                'period_end' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : $subscription->current_period_end,
                'hosted_invoice_url' => $invoice['hosted_invoice_url'] ?? null,
                'invoice_pdf' => $invoice['invoice_pdf'] ?? null,
                'paid_at' => $paidAt ? Carbon::createFromTimestamp($paidAt) : now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return; // A concurrent delivery of the same invoice got there first.
        }

        SendSubscriptionPaymentNotification::dispatch($record);
    }

    private function resolveUserId(array $data): ?int
    {
        if (! empty($data['metadata']['user_id']) && User::whereKey($data['metadata']['user_id'])->exists()) {
            return (int) $data['metadata']['user_id'];
        }

        $customerId = is_array($data['customer'] ?? null) ? $data['customer']['id'] : ($data['customer'] ?? null);

        return $customerId
            ? StripeCustomer::where('stripe_customer_id_index', StripeCustomer::blindIndex($customerId))->value('user_id')
            : null;
    }

    private function resolvePlanId(array $data): ?int
    {
        if (! empty($data['metadata']['subscription_plan_id'])
            && SubscriptionPlan::withTrashed()->whereKey($data['metadata']['subscription_plan_id'])->exists()) {
            return (int) $data['metadata']['subscription_plan_id'];
        }

        return $this->planIdForPrice($data);
    }

    private function planIdForPrice(array $data): ?int
    {
        $priceId = $data['items']['data'][0]['price']['id'] ?? null;

        return $priceId
            ? SubscriptionPlan::withTrashed()->where('stripe_price_id', $priceId)->value('id')
            : null;
    }
}
