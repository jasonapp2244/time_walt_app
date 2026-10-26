<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionPlanRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plans are created here and pushed to Stripe on every save, so nobody has to
 * create Products or Prices in the Stripe Dashboard.
 */
class SubscriptionPlanController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    public function index(): View
    {
        $plans = $this->listQuery()->paginate(15);

        return view('admin.subscription-plans.index', compact('plans'));
    }

    public function create(): View
    {
        return view('admin.subscription-plans.form', ['plan' => new SubscriptionPlan([
            'currency' => 'usd',
            'interval' => 'month',
            'is_active' => true,
        ])]);
    }

    /**
     * The plan is saved inactive first so it has an ID for Stripe metadata.
     * If Stripe rejects it, it stays as an inactive draft the admin can retry.
     */
    public function store(SubscriptionPlanRequest $request): RedirectResponse
    {
        $plan = SubscriptionPlan::create($request->planAttributes() + ['is_active' => false]);
        $plan->is_active = $request->boolean('is_active');

        try {
            $this->subscriptionService->syncPlanToStripe($plan);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Subscription plan Stripe sync failed', ['plan_id' => $plan->id, 'error' => $e->getMessage()]);

            return redirect()->route('admin.subscription-plans.edit', $plan)
                ->withErrors(['stripe' => 'Saved as an inactive draft, but Stripe rejected it: '.$e->getMessage()]);
        }

        return redirect()->route('admin.subscription-plans.index')
            ->with('success', "Plan \"{$plan->name}\" created and synced to Stripe.");
    }

    public function edit(SubscriptionPlan $subscriptionPlan): View
    {
        return view('admin.subscription-plans.form', ['plan' => $subscriptionPlan]);
    }

    /**
     * Nothing is saved unless Stripe accepts the change, so a live plan is
     * never left out of step with Stripe.
     */
    public function update(SubscriptionPlanRequest $request, SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $subscriptionPlan->fill($request->planAttributes());
        $subscriptionPlan->is_active = $request->boolean('is_active');
        $priceChanged = $subscriptionPlan->isDirty(['price', 'currency', 'interval']);

        // Subscribers keep billing at the old Stripe price, but every screen
        // reads the plan's price, so it would show them an amount they don't pay.
        if ($priceChanged && Subscription::where('subscription_plan_id', $subscriptionPlan->id)->entitled()->exists()) {
            return back()->withInput()->withErrors(['price' => 'This plan has active subscribers, so its price, currency and billing period cannot change. Create a new plan and hide this one instead.']);
        }

        try {
            $this->subscriptionService->syncPlanToStripe($subscriptionPlan);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Subscription plan Stripe sync failed', ['plan_id' => $subscriptionPlan->id, 'error' => $e->getMessage()]);

            return back()->withInput()
                ->withErrors(['stripe' => 'Not saved. Stripe rejected the change: '.$e->getMessage()]);
        }

        $message = "Plan \"{$subscriptionPlan->name}\" updated.";
        if ($priceChanged) {
            $message .= ' New subscribers pay the new price.';
        }

        return redirect()->route('admin.subscription-plans.index')->with('success', $message);
    }

    /**
     * Show or hide the plan in the app.
     */
    public function toggle(SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $subscriptionPlan->is_active = ! $subscriptionPlan->is_active;

        try {
            $this->subscriptionService->syncPlanToStripe($subscriptionPlan);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Subscription plan toggle failed', ['plan_id' => $subscriptionPlan->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['stripe' => 'Stripe rejected the change: '.$e->getMessage()]);
        }

        return back()->with('success', "Plan \"{$subscriptionPlan->name}\" is now ".($subscriptionPlan->is_active ? 'visible in the app.' : 'hidden from the app.'));
    }

    /**
     * Remove a plan nobody is subscribed to. Its Stripe Product is archived,
     * not deleted, so past invoices still resolve.
     */
    public function destroy(SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        if (Subscription::where('subscription_plan_id', $subscriptionPlan->id)->entitled()->exists()) {
            return back()->withErrors(['plan' => 'This plan has active subscribers. Hide it instead; it can be deleted once they have all ended.']);
        }

        if ($subscriptionPlan->stripe_product_id && $subscriptionPlan->is_active) {
            $subscriptionPlan->is_active = false;

            try {
                $this->subscriptionService->syncPlanToStripe($subscriptionPlan);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                Log::error('Subscription plan archive failed', ['plan_id' => $subscriptionPlan->id, 'error' => $e->getMessage()]);

                return back()->withErrors(['stripe' => 'Stripe rejected the change: '.$e->getMessage()]);
            }
        }

        $subscriptionPlan->delete();

        return redirect()->route('admin.subscription-plans.index')
            ->with('success', "Plan \"{$subscriptionPlan->name}\" deleted.");
    }

    /**
     * Download the plans as Excel or PDF.
     */
    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Subscription Plans', $this->listQuery(), [
            'ID', 'Plan', 'Price', 'Currency', 'Billing', 'Active subscribers', 'Visible in app', 'Stripe price',
        ], fn (SubscriptionPlan $p) => [
            str_pad($p->id, 5, '0', STR_PAD_LEFT), $p->name, (float) $p->price, strtoupper($p->currency),
            $p->interval === 'year' ? 'Yearly' : 'Monthly', (int) $p->subscribers_count, (bool) $p->is_active, $p->stripe_price_id ?? 'Not synced',
        ]);
    }

    /**
     * Live plan and subscriber counts for AJAX polling.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => SubscriptionPlan::count(),
            'subscribers' => Subscription::entitled()->count(),
            'latest_update' => (string) SubscriptionPlan::max('updated_at'),
        ]);
    }

    private function listQuery(): Builder
    {
        return SubscriptionPlan::withCount([
            'subscriptions as subscribers_count' => fn ($q) => $q->entitled(),
        ])
            ->orderBy('sort_order')
            ->orderBy('price');
    }
}
