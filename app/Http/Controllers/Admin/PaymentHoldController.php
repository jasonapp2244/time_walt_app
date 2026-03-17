<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentHold;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentHoldController extends Controller
{
    /**
     * List all payment holds with optional status filter.
     */
    public function index(Request $request): View
    {
        $query = PaymentHold::with(['user', 'payment', 'transfer'])
            ->whereNull('abandoned_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $holds = $query->latest()->paginate(10)->withQueryString();

        ['statusCounts' => $statusCounts, 'amountTotals' => $amountTotals] = $this->buildSummary();

        return view('admin.payment-holds.index', compact('holds', 'statusCounts', 'amountTotals'));
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
            'holding' => (float) PaymentHold::where('status', 'holding')->whereNull('abandoned_at')->sum('amount'),
            'ready' => (float) PaymentHold::whereIn('status', ['ready_for_transfer', 'partial_transferred'])->whereNull('abandoned_at')->sum('remaining_amount'),
            'withdrawn' => (float) Transfer::where('status', 'completed')->sum('amount'),
        ];

        $totalRecords = array_sum($statusCounts);

        return compact('statusCounts', 'amountTotals', 'totalRecords');
    }
}
