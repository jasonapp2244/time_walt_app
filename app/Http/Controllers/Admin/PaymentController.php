<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    /**
     * List all payments with optional status filter.
     */
    public function index(Request $request): View
    {
        $query = Payment::with(['user', 'hold']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $payments = $query->latest()->paginate(10)->withQueryString();

        ['statusCounts' => $statusCounts, 'totalRevenue' => $totalRevenue] = $this->buildSummary();

        return view('admin.payments.index', compact('payments', 'statusCounts', 'totalRevenue'));
    }

    /**
     * Return live payment stats as JSON for AJAX polling.
     */
    public function stats(): JsonResponse
    {
        return response()->json($this->buildSummary());
    }

    /**
     * @return array{statusCounts: array<string, int>, totalRevenue: float, totalRecords: int}
     */
    private function buildSummary(): array
    {
        $statusCounts = [
            'completed' => Payment::where('status', 'succeeded')->count(),
            'pending' => Payment::where('status', 'pending')->count(),
            'failed' => Payment::whereIn('status', ['failed', 'canceled'])->count(),
        ];

        return [
            'statusCounts' => $statusCounts,
            'totalRevenue' => (float) Payment::where('status', 'succeeded')->sum('amount'),
            'totalRecords' => array_sum($statusCounts),
        ];
    }
}
