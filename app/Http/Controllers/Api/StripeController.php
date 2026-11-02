<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use App\Services\WebhookService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeController extends Controller
{
    /**
     * A 'pending' event older than this is treated as abandoned (the worker
     * died mid-event) and may be claimed again by Stripe's next retry.
     */
    private const STALE_PENDING_MINUTES = 10;

    public function __construct(
        protected WebhookService $webhookService
    ) {}

    /**
     * Handle Stripe webhook.
     *
     * 200 = handled (or already handled), 400 = bad signature/payload,
     * 409 = the same event is being handled by another request right now,
     * 500 = not handled; Stripe retries every non-2xx response.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $webhookSecret = config('services.stripe.webhook_secret');

        if (empty($webhookSecret)) {
            Log::critical('Stripe webhook secret not configured');

            return response()->json(['error' => 'Webhook secret not configured'], 500);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $webhookSecret
            );
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed: '.$e->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook payload is not valid JSON');

            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $eventArray = $event->toArray();

        $webhookEvent = $this->claim($eventArray);

        if ($webhookEvent === 'processed') {
            Log::info('Duplicate webhook event skipped', ['event_id' => $eventArray['id']]);

            return response()->json(['received' => true]);
        }

        if ($webhookEvent === 'busy') {
            Log::info('Webhook event is already being processed', ['event_id' => $eventArray['id']]);

            return response()->json(['error' => 'Event is being processed'], 409);
        }

        try {
            $this->dispatch($eventArray);

            $webhookEvent->update([
                'status' => 'processed',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            return response()->json(['received' => true]);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handling failed: '.$e->getMessage(), [
                'event_id' => $eventArray['id'],
                'event_type' => $eventArray['type'],
            ]);

            $webhookEvent->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'retry_count' => $webhookEvent->retry_count + 1,
                'last_retry_at' => now(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Take ownership of an event so only one request handles it.
     *
     * Returns the row (now 'pending', owned by this request), 'processed' if
     * it was handled before, or 'busy' if another request holds it. Ownership
     * is decided by the unique stripe_event_id insert or by a conditional
     * UPDATE, both atomic in the database, so two simultaneous deliveries of
     * the same event cannot both run the handler.
     */
    private function claim(array $event): StripeWebhookEvent|string
    {
        try {
            return StripeWebhookEvent::create([
                'stripe_event_id' => $event['id'],
                'event_type' => $event['type'],
                'status' => 'pending',
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Seen before: fall through and decide from its status.
        }

        $existing = StripeWebhookEvent::where('stripe_event_id', $event['id'])->first();

        if (! $existing || $existing->status === 'processed') {
            return 'processed';
        }

        $claimed = StripeWebhookEvent::whereKey($existing->id)
            ->where(function ($query) {
                $query->where('status', 'failed')
                    ->orWhere(function ($query) {
                        $query->where('status', 'pending')
                            ->where('updated_at', '<', now()->subMinutes(self::STALE_PENDING_MINUTES));
                    });
            })
            ->update(['status' => 'pending', 'updated_at' => now()]);

        return $claimed === 1 ? $existing->refresh() : 'busy';
    }

    private function dispatch(array $event): void
    {
        switch ($event['type']) {
            case 'checkout.session.completed':
                $this->webhookService->handleCheckoutSessionCompleted($event);
                break;

            case 'payment_intent.succeeded':
                $this->webhookService->handlePaymentIntentSucceeded($event);
                break;

            case 'payment_intent.payment_failed':
                $this->webhookService->handlePaymentIntentFailed($event);
                break;

            case 'payment_intent.canceled':
                $this->webhookService->handlePaymentIntentCanceled($event);
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $this->webhookService->handleSubscriptionChanged($event);
                break;

            case 'invoice.paid':
            case 'invoice.payment_failed':
            case 'invoice.payment_action_required':
                $this->webhookService->handleSubscriptionInvoice($event);
                break;

            default:
                Log::info('Unhandled webhook event type: '.$event['type']);
        }
    }
}
