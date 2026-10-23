<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * All subscriptions, filterable by status. Email search goes through the
     * blind index and matches the full address only, like the users list.
     */
    public function index(Request $request): View
    {
        $subscriptions = $this->filteredQuery($request)->latest('id')->paginate(15)->withQueryString();
        $summary = $this->buildSummary();

        return view('admin.subscriptions.index', compact('subscriptions', 'summary'));
    }

    /**
     * Download the list, with the page's filters, as Excel or PDF.
     */
    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Subscriptions', $this->filteredQuery($request)->latest('id'), [
            'ID', 'User', 'Email', 'Plan', 'Amount', 'Currency', 'Billing', 'Status', 'Renews / ends', 'Ending at period end', 'Started',
        ], fn (Subscription $s) => [
            str_pad($s->id, 5, '0', STR_PAD_LEFT), $s->user?->displayName() ?? 'Deleted user', $s->user?->displayEmail() ?? '—',
            $s->plan?->name ?? '—', (float) ($s->plan?->price ?? 0), strtoupper($s->plan?->currency ?? ''),
            $s->plan?->interval === 'year' ? 'Yearly' : 'Monthly', $s->isEntitled() ? ($s->status === 'past_due' ? 'Renewal failing' : 'Active') : ucfirst(str_replace('_', ' ', $s->status)),
            $s->current_period_end, (bool) $s->cancel_at_period_end, $s->created_at,
        ], ['Status' => $request->status, 'Search' => $request->search]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Subscription::with(['user', 'plan']);

        match ($request->input('status')) {
            'active' => $query->entitled(),
            'past_due' => $query->where('status', 'past_due'),
            'canceling' => $query->entitled()->where('cancel_at_period_end', true),
            'canceled' => $query->whereIn('status', ['canceled', 'unpaid', 'incomplete_expired']),
            'incomplete' => $query->where('status', 'incomplete'),
            default => null,
        };

        if ($request->filled('search')) {
            $query->whereHas('user', function ($u) use ($request) {
                $u->where('email_index', User::blindIndex(strtolower(trim($request->search))));
            });
        }

        return $query;
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->buildSummary());
    }

    public function cancelAtPeriodEnd(Subscription $subscription): RedirectResponse
    {
        return $this->runStripeAction($subscription, 'cancelAtPeriodEnd', 'will end at the close of the current period');
    }

    public function cancelNow(Subscription $subscription): RedirectResponse
    {
        return $this->runStripeAction($subscription, 'cancelNow', 'has been canceled');
    }

    private function runStripeAction(Subscription $subscription, string $method, string $done): RedirectResponse
    {
        if (! $subscription->isEntitled()) {
            return back()->withErrors(['subscription' => 'This subscription is not active.']);
        }

        try {
            $this->subscriptionService->{$method}($subscription);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Admin subscription action failed', [
                'subscription_id' => $subscription->id,
                'action' => $method,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['stripe' => 'Stripe rejected the request: '.$e->getMessage()]);
        }

        return back()->with('success', 'Subscription #'.str_pad($subscription->id, 5, '0', STR_PAD_LEFT)." {$done}.");
    }

    /**
     * @return array{active: int, past_due: int, canceling: int, canceled: int, mrr: array<string, float>, total: int}
     */
    private function buildSummary(): array
    {
        $entitled = Subscription::entitled()->with('plan')->get();

        // MRR per currency: plans may be priced in USD, EUR or GBP, and adding
        // them together would be meaningless. Subscriptions set to end are left out.
        $mrr = $entitled->where('cancel_at_period_end', false)
            ->groupBy(fn ($s) => strtoupper($s->plan?->currency ?? 'usd'))
            ->map(fn ($group) => round($group->sum(fn ($s) => $s->plan?->monthlyAmount() ?? 0), 2))
            ->all();

        return [
            'active' => $entitled->count(),
            'past_due' => $entitled->where('status', 'past_due')->count(),
            'canceling' => $entitled->where('cancel_at_period_end', true)->count(),
            'canceled' => Subscription::whereIn('status', ['canceled', 'unpaid', 'incomplete_expired'])->count(),
            'mrr' => $mrr,
            'total' => Subscription::count(),
        ];
    }
}
