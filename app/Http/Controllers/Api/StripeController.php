<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeController extends Controller
{
    public function __construct(
        protected WebhookService $webhookService
    ) {}

    /**
     * Handle Stripe webhook.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->getContent();
            $sigHeader = $request->header('Stripe-Signature');
            $webhookSecret = config('services.stripe.webhook_secret');

            if (empty($webhookSecret)) {
                Log::warning('Stripe webhook secret not configured');

                return response()->json(['error' => 'Webhook secret not configured'], 400);
            }

            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );

            // Deduplication: check if this event was already processed
            $existingEvent = StripeWebhookEvent::where('stripe_event_id', $event['id'])->first();
            if ($existingEvent && $existingEvent->status === 'processed') {
                Log::info('Duplicate webhook event skipped', ['event_id' => $event['id']]);

                return response()->json(['received' => true]);
            }

            // Record the event
            $webhookEvent = StripeWebhookEvent::updateOrCreate(
                ['stripe_event_id' => $event['id']],
                [
                    'event_type' => $event['type'],
                    'status' => 'pending',
                    'payload' => $event->toArray(),
                ]
            );

            // Handle different event types
            $eventArray = $event->toArray();

            switch ($event['type']) {
                case 'checkout.session.completed':
                    $this->webhookService->handleCheckoutSessionCompleted($eventArray);
                    break;

                case 'payment_intent.succeeded':
                    $this->webhookService->handlePaymentIntentSucceeded($eventArray);
                    break;

                case 'payment_intent.payment_failed':
                    $this->webhookService->handlePaymentIntentFailed($eventArray);
                    break;

                case 'payment_intent.canceled':
                    $this->webhookService->handlePaymentIntentCanceled($eventArray);
                    break;

                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    $this->webhookService->handleSubscriptionChanged($eventArray);
                    break;

                case 'invoice.paid':
                case 'invoice.payment_failed':
                case 'invoice.payment_action_required':
                    $this->webhookService->handleSubscriptionInvoice($eventArray);
                    break;

                default:
                    Log::info('Unhandled webhook event type: '.$event['type']);
            }

            // Mark event as processed
            $webhookEvent->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            return response()->json(['received' => true]);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: '.$e->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook handling failed: '.$e->getMessage());

            // Mark event as failed if we have a record
            if (isset($webhookEvent)) {
                $webhookEvent->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'retry_count' => $webhookEvent->retry_count + 1,
                    'last_retry_at' => now(),
                ]);
            }

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}
