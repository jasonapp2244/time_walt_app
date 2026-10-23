<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every collected subscription payment: first payments and automatic renewals.
 */
class SubscriptionPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $payments = $this->filteredQuery($request)->latest('paid_at')->paginate(15)->withQueryString();
        $summary = $this->buildSummary();

        return view('admin.subscription-payments.index', compact('payments', 'summary'));
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->buildSummary());
    }

    /**
     * Download the list, with the page's filters, as Excel or PDF.
     */
    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Subscription Payments', $this->filteredQuery($request)->latest('paid_at'), [
            'ID', 'User', 'Email', 'Plan', 'Type', 'Amount', 'Currency', 'Invoice', 'Paid at', 'Paid until',
        ], fn (SubscriptionInvoice $i) => [
            str_pad($i->id, 5, '0', STR_PAD_LEFT), $i->user?->displayName() ?? 'Deleted user', $i->user?->displayEmail() ?? '—',
            $i->subscription?->plan?->name ?? '—', $i->typeLabel(), (float) $i->amount, strtoupper($i->currency),
            $i->invoice_number ?? '—', $i->paid_at, $i->period_end,
        ], ['Type' => $request->type, 'Search' => $request->search]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = SubscriptionInvoice::with(['user', 'subscription.plan']);

        match ($request->input('type')) {
            'new' => $query->where('billing_reason', 'subscription_create'),
            'renewal' => $query->where('billing_reason', '!=', 'subscription_create'),
            default => null,
        };

        if ($request->filled('search')) {
            $query->whereHas('user', function ($u) use ($request) {
                $u->where('email_index', User::blindIndex(strtolower(trim($request->search))));
            });
        }

        return $query;
    }

    /**
     * Collected revenue per currency (all time and this month, in the admin's
     * timezone). Currencies are never added together.
     *
     * @return array{total: int, new: int, renewals: int, revenue: array<string, float>, this_month: array<string, float>}
     */
    private function buildSummary(): array
    {
        $monthStart = now(config('app.admin_timezone', 'UTC'))->startOfMonth()->utc();

        $sumByCurrency = fn (Builder $q) => $q->selectRaw('UPPER(currency) as cur, SUM(amount) as total')
            ->groupBy('cur')
            ->pluck('total', 'cur')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        return [
            'total' => SubscriptionInvoice::count(),
            'new' => SubscriptionInvoice::where('billing_reason', 'subscription_create')->count(),
            'renewals' => SubscriptionInvoice::where('billing_reason', '!=', 'subscription_create')->count(),
            'revenue' => $sumByCurrency(SubscriptionInvoice::query()),
            'this_month' => $sumByCurrency(SubscriptionInvoice::where('paid_at', '>=', $monthStart)),
        ];
    }
}
