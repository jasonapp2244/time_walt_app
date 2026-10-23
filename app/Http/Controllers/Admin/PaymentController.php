<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    /**
     * List all payments with optional status filter.
     */
    public function index(Request $request): View
    {
        $payments = $this->filteredQuery($request)->latest()->paginate(10)->withQueryString();

        ['statusCounts' => $statusCounts, 'totalRevenue' => $totalRevenue] = $this->buildSummary();

        return view('admin.payments.index', compact('payments', 'statusCounts', 'totalRevenue'));
    }

    /**
     * Download the list, with the page's filters, as Excel or PDF.
     */
    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Payments', $this->filteredQuery($request)->latest(), [
            'Payment ID', 'User', 'Email', 'Amount', 'Currency', 'Status', 'Card', 'Failure reason', 'Paid at',
        ], fn (Payment $p) => [
            str_pad($p->id, 5, '0', STR_PAD_LEFT), $p->user?->displayName() ?? 'Deleted user', $p->user?->displayEmail() ?? '—',
            (float) $p->amount, strtoupper($p->currency ?? ''), $p->status === 'succeeded' ? 'Completed' : ucfirst($p->status ?? ''),
            $p->card_last4 ? ucfirst($p->card_brand ?? 'card').' •••• '.$p->card_last4 : '—', $p->failure_reason ?? '—', $p->paid_at,
        ], ['Status' => $request->status]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Payment::with(['user', 'hold']);

        if ($request->filled('status')) {
            // Map display status to DB status (DB stores 'succeeded', UI shows 'completed')
            $statusMap = ['completed' => 'succeeded'];
            $query->where('status', $statusMap[$request->status] ?? $request->status);
        }

        return $query;
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
