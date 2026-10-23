<?php

namespace Tests\Fakes;

use Stripe\ApiRequestor;

/**
 * Routes every Stripe SDK call in the test through FakeStripeHttpClient and
 * removes it again afterwards, so no test can reach the real API.
 */
trait FakesStripe
{
    protected FakeStripeHttpClient $stripe;

    protected function fakeStripe(): FakeStripeHttpClient
    {
        config([
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.publishable_key' => 'pk_test_fake',
            'services.stripe.webhook_secret' => 'whsec_test_fake',
        ]);

        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);

        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient(null));

        return $this->stripe;
    }

    /**
     * POST a Stripe-signed webhook event to the real endpoint.
     */
    protected function postStripeWebhook(array $event)
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", config('services.stripe.webhook_secret'));

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $payload);
    }

    protected function stripeEvent(string $type, array $object): array
    {
        return [
            'id' => 'evt_'.bin2hex(random_bytes(8)),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $object],
        ];
    }

    /**
     * A Stripe subscription in the 2025-12-15.clover shape: the billing
     * period sits on the item, not the subscription.
     */
    protected function stripeSubscription(string $id, string $status, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'object' => 'subscription',
            'status' => $status,
            'customer' => 'cus_fake',
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'metadata' => [],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_fake',
                'object' => 'subscription_item',
                'current_period_end' => now()->addMonth()->timestamp,
                'price' => ['id' => 'price_fake', 'object' => 'price'],
            ]]],
        ], $overrides);
    }
}
