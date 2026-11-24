<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\RefundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundRequest;
use App\Models\Dispute;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Services\RefundService;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class RefundController extends Controller
{
    public const AUDIT_CACHE_KEY = 'refunds:audit:mismatches';

    public function __construct(
        protected RefundService $refunds
    ) {}

    /**
     * Data for the refund popup, computed fresh on the server.
     */
    public function preview(PaymentHold $hold): JsonResponse
    {
        return response()->json($this->refunds->preview($hold));
    }

    /**
     * Refund the chosen amount (all or part of the deposit) to the card that paid it.
     */
    public function store(RefundRequest $request, PaymentHold $hold): RedirectResponse
    {
        $admin = $request->user();

        try {
            $refund = $this->refunds->refundHold(
                $hold,
                $admin,
                $this->toCents((string) $request->validated('amount')),
                $request->validated('reason_code'),
                trim($request->validated('reason_note')),
                $request->validated('request_token'),
            );
        } catch (RefundException $e) {
            Log::warning('Admin refund refused or failed', ['hold_id' => $hold->id, 'admin_id' => $admin->id, 'reason' => $e->getMessage()]);

            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        $amount = '$'.number_format((float) $refund->amount, 2);
        $state = $refund->status === 'succeeded' ? 'Refunded' : 'Refund sent to Stripe (pending):';
        $hold->refresh()->load('payment');
        $total = ' Total refunded on this deposit: $'.number_format((float) $hold->payment->refunded_amount, 2).' of $'.number_format((float) $hold->amount, 2).'.';

        return back()->with('success', "{$state} {$amount} to {$refund->cardLabel()} (Stripe {$refund->reference()}).{$total} The user and admin have been emailed.");
    }

    /** "25.5" → 2550, from the validated decimal string, without float rounding. */
    private function toCents(string $amount): int
    {
        [$dollars, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $dollars) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * Every refund, with filters and totals.
     */
    public function index(Request $request): View
    {
        $refundsList = $this->filteredQuery($request)->latest()->paginate(15)->withQueryString();

        $totals = [
            'succeeded' => (float) Refund::where('status', 'succeeded')->sum('amount'),
            'pending' => (float) Refund::where('status', 'pending')->sum('amount'),
            'failed' => Refund::whereIn('status', ['failed', 'canceled'])->count(),
            'attention' => Refund::where('needs_attention', true)->count(),
        ];

        $auditMismatches = Cache::get(self::AUDIT_CACHE_KEY, []);

        // Chargebacks: every open one, plus the latest decided ones.
        $disputes = Dispute::with(['user', 'hold'])
            ->where(fn ($q) => $q->where('outcome', 'open')->orWhere('needs_attention', true))
            ->latest()
            ->get()
            ->merge(Dispute::with(['user', 'hold'])->where('outcome', '!=', 'open')->where('needs_attention', false)->latest()->limit(10)->get());
        $disputeTotals = [
            'open' => Dispute::where('outcome', 'open')->count(),
            // Only deposit chargebacks freeze vault money.
            'open_amount' => (float) Dispute::where('outcome', 'open')->whereNotNull('payment_id')->sum('amount'),
            'lost_amount' => (float) Dispute::where('outcome', 'lost')->sum('amount'),
            'won' => Dispute::where('outcome', 'won')->count(),
        ];

        return view('admin.refunds.index', compact('refundsList', 'totals', 'auditMismatches', 'disputes', 'disputeTotals'));
    }

    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Refunds', $this->filteredQuery($request)->latest(), [
            'Refund ID', 'Date', 'User', 'Email', 'Deposit', 'Card', 'Type', 'Amount', 'From vault', 'From Connect', 'Status', 'Source', 'Reason', 'Note', 'Admin', 'Stripe ref', 'ARN',
        ], fn (Refund $r) => [
            str_pad((string) $r->id, 5, '0', STR_PAD_LEFT),
            $r->created_at?->setTimezone(config('app.admin_timezone'))->format('Y-m-d H:i'),
            $r->user?->displayName() ?? 'Deleted user',
            $r->user?->displayEmail() ?? '—',
            $r->payment_hold_id ? str_pad((string) $r->payment_hold_id, 5, '0', STR_PAD_LEFT) : '—',
            $r->cardLabel(),
            $r->isPartial() ? 'Partial' : 'Full',
            (float) $r->amount,
            (float) $r->vault_amount,
            (float) $r->reversed_amount,
            ucfirst($r->status),
            $r->source === 'admin' ? 'Admin panel' : 'Stripe Dashboard',
            $r->reasonLabel(),
            $r->reason_note ?? '—',
            $r->admin?->displayName() ?? '—',
            $r->reference(),
            $r->arn ?? '—',
        ], array_filter([
            'Status' => $request->status,
            'Source' => $request->source,
            'From' => $request->from,
            'To' => $request->to,
        ]));
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Refund::with(['user', 'admin', 'hold', 'payment']);

        if (in_array($request->status, ['pending', 'succeeded', 'failed', 'canceled'], true)) {
            $query->where('status', $request->status);
        }

        if (in_array($request->source, ['admin', 'stripe_dashboard'], true)) {
            $query->where('source', $request->source);
        }

        if ($request->boolean('attention')) {
            $query->where('needs_attention', true);
        }

        if ($request->filled('user_id') && ctype_digit((string) $request->user_id)) {
            $query->where('user_id', (int) $request->user_id);
        }

        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if ($request->filled($field) && strtotime((string) $request->$field) !== false) {
                $day = \Illuminate\Support\Carbon::parse((string) $request->$field, config('app.admin_timezone'));
                $query->where('created_at', $operator, ($field === 'from' ? $day->startOfDay() : $day->endOfDay())->utc());
            }
        }

        return $query;
    }
}
