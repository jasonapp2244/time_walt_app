<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PaymentHoldController extends Controller
{
    /**
     * List all payment holds with optional status filter.
     */
    public function index(Request $request): View
    {
        $holds = $this->filteredQuery($request)->latest()->paginate(10)->withQueryString();

        ['statusCounts' => $statusCounts, 'amountTotals' => $amountTotals] = $this->buildSummary();

        return view('admin.payment-holds.index', compact('holds', 'statusCounts', 'amountTotals'));
    }

    /**
     * Download the list, with the page's filters, as Excel or PDF.
     */
    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Payment Holds', $this->filteredQuery($request)->latest(), [
            'Hold ID', 'User', 'Email', 'Title', 'Amount', 'Remaining', 'Currency', 'Status', 'Lock date', 'Unlock date',
        ], fn (PaymentHold $h) => [
            str_pad($h->id, 5, '0', STR_PAD_LEFT), $h->user?->displayName() ?? 'Deleted user', $h->user?->displayEmail() ?? '—',
            $h->title ?? '—', (float) $h->amount, (float) ($h->remaining_amount ?? $h->amount),
            strtoupper($h->payment?->currency ?? 'usd'), str_replace('_', ' ', ucfirst($h->status)), $h->hold_start_at, $h->hold_end_at,
        ], ['Status' => $request->status]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = PaymentHold::with(['user', 'payment', 'transfer'])
            ->whereNull('abandoned_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query;
    }

    /**
     * Return live hold summary counts and amounts as JSON for AJAX polling.
     */
    public function stats(): JsonResponse
    {
        ['statusCounts' => $statusCounts, 'amountTotals' => $amountTotals, 'totalRecords' => $totalRecords] = $this->buildSummary();

        return response()->json([
            'status_counts' => $statusCounts,
            'amount_totals' => $amountTotals,
            'total_records' => $totalRecords,
        ]);
    }

    /**
     * @return array{statusCounts: array<string, int>, amountTotals: array<string, float>, totalRecords: int}
     */
    private function buildSummary(): array
    {
        $statusCounts = [
            'holding' => PaymentHold::where('status', 'holding')->whereNull('abandoned_at')->count(),
            'ready_for_transfer' => PaymentHold::where('status', 'ready_for_transfer')->whereNull('abandoned_at')->count(),
            'transferred' => PaymentHold::where('status', 'transferred')->whereNull('abandoned_at')->count(),
            'partial_transferred' => PaymentHold::where('status', 'partial_transferred')->whereNull('abandoned_at')->count(),
        ];

        $amountTotals = [
            'holding' => (float) PaymentHold::where('status', 'holding')->whereNull('abandoned_at')->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(remaining_amount, amount)')),
            'ready' => (float) PaymentHold::whereIn('status', ['ready_for_transfer', 'partial_transferred'])->whereNull('abandoned_at')->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(remaining_amount, amount)')),
            'withdrawn' => (float) Transfer::where('status', 'completed')->sum('amount'),
        ];

        $totalRecords = array_sum($statusCounts);

        return compact('statusCounts', 'amountTotals', 'totalRecords');
    }
}
