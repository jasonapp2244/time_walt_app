<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Services\StripeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransferController extends Controller
{
    /**
     * List all transfers with optional status filter.
     */
    public function index(Request $request): View
    {
        $query = Transfer::with(['user', 'hold'])
            ->whereNull('abandoned_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transfers = $query->latest()->paginate(20)->withQueryString();

        $statusCounts = [
            'pending' => Transfer::where('status', 'pending')->whereNull('abandoned_at')->count(),
            'completed' => Transfer::where('status', 'completed')->whereNull('abandoned_at')->count(),
            'failed' => Transfer::where('status', 'failed')->whereNull('abandoned_at')->count(),
        ];

        $totalTransferred = Transfer::where('status', 'completed')->sum('amount');

        return view('admin.transfers.index', compact('transfers', 'statusCounts', 'totalTransferred'));
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
