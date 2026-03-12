<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentHold;
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

        $holds = $query->latest()->paginate(20)->withQueryString();

        $statusCounts = [
            'holding' => PaymentHold::where('status', 'holding')->whereNull('abandoned_at')->count(),
            'ready_for_transfer' => PaymentHold::where('status', 'ready_for_transfer')->whereNull('abandoned_at')->count(),
            'transferred' => PaymentHold::where('status', 'transferred')->whereNull('abandoned_at')->count(),
            'partial_transferred' => PaymentHold::where('status', 'partial_transferred')->whereNull('abandoned_at')->count(),
        ];

        $amountTotals = [
            'holding' => PaymentHold::where('status', 'holding')->whereNull('abandoned_at')->sum('amount'),
            'ready' => PaymentHold::where('status', 'ready_for_transfer')->whereNull('abandoned_at')->sum('amount'),
            'withdrawn' => PaymentHold::whereIn('status', ['transferred', 'partial_transferred'])->whereNull('abandoned_at')->sum('amount'),
        ];

        return view('admin.payment-holds.index', compact('holds', 'statusCounts', 'amountTotals'));
    }
}
