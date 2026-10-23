<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Services\StripeService;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TransferController extends Controller
{
    /**
     * List all transfers with optional status filter.
     */
    public function index(Request $request): View
    {
        $transfers = $this->filteredQuery($request)->latest()->paginate(10)->withQueryString();

        ['statusCounts' => $statusCounts, 'totalTransferred' => $totalTransferred] = $this->buildSummary();

        return view('admin.transfers.index', compact('transfers', 'statusCounts', 'totalTransferred'));
    }

    /**
     * Download the list, with the page's filters, as Excel or PDF.
     */
    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Transfers', $this->filteredQuery($request)->latest(), [
            'Transfer ID', 'User', 'Email', 'Amount', 'Currency', 'To account', 'Type', 'Status', 'Failure reason', 'Date',
        ], fn (Transfer $t) => [
            str_pad($t->id, 5, '0', STR_PAD_LEFT), $t->user?->displayName() ?? 'Deleted user', $t->user?->displayEmail() ?? '—',
            (float) $t->amount, strtoupper($t->currency ?? ''),
            $t->bankAccount ? $t->bankAccount->safe('bank_name').' '.$t->bankAccount->masked_account_number : '—',
            str_replace('_', ' ', ucfirst($t->transfer_type ?? '—')), ucfirst($t->status ?? ''), $t->failure_reason ?? '—',
            $t->transferred_at ?? $t->created_at,
        ], ['Status' => $request->status]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Transfer::with(['user', 'hold', 'bankAccount'])
            ->whereNull('abandoned_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query;
    }

    /**
     * Return live transfer stats as JSON for AJAX polling.
     */
    public function stats(): JsonResponse
    {
        return response()->json($this->buildSummary());
    }

    /**
     * @return array{statusCounts: array<string, int>, totalTransferred: float, totalRecords: int}
     */
    private function buildSummary(): array
    {
        $statusCounts = [
            'pending' => Transfer::where('status', 'pending')->whereNull('abandoned_at')->count(),
            'completed' => Transfer::where('status', 'completed')->whereNull('abandoned_at')->count(),
            'failed' => Transfer::where('status', 'failed')->whereNull('abandoned_at')->count(),
        ];

        return [
            'statusCounts' => $statusCounts,
            'totalTransferred' => (float) Transfer::where('status', 'completed')->sum('amount'),
            'totalRecords' => array_sum($statusCounts),
        ];
    }

    /**
     * Execute a manual transfer for a payment hold (admin action).
     */
    public function execute(PaymentHold $hold, StripeService $stripeService): RedirectResponse
    {
        if (! in_array($hold->status, ['ready_for_transfer', 'partial_transferred'])) {
            return back()->with('error', 'This hold is not ready for transfer.');
        }

        try {
            $transfer = $stripeService->createTransfer($hold, 'manual');

            if ($transfer) {
                return back()->with('success', 'Transfer executed successfully.');
            }

            return back()->with('error', 'Transfer could not be executed.');
        } catch (\Exception $e) {
            return back()->with('error', 'Transfer failed: '.$e->getMessage());
        }
    }
}
