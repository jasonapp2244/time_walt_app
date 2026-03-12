<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the admin dashboard with real-time stats.
     */
    public function index(): View
    {
        $stats = [
            'total_users' => User::where('role', 'user')
                ->where('status', '!=', 'deleted')
                ->count(),

            'total_revenue' => Payment::where('status', 'completed')
                ->sum('amount'),

            'holds_holding' => PaymentHold::where('status', 'holding')
                ->whereNull('abandoned_at')
                ->count(),

            'total_holding_amount' => PaymentHold::where('status', 'holding')
                ->whereNull('abandoned_at')
                ->sum('amount'),

            'holds_ready' => PaymentHold::where('status', 'ready_for_transfer')
                ->whereNull('abandoned_at')
                ->count(),

            'total_ready_amount' => PaymentHold::where('status', 'ready_for_transfer')
                ->whereNull('abandoned_at')
                ->sum('amount'),

            'holds_transferred' => PaymentHold::whereIn('status', ['transferred', 'partial_transferred'])
                ->whereNull('abandoned_at')
                ->count(),

            'total_transferred_amount' => Transfer::where('status', 'completed')
                ->sum('amount'),

            'pending_transfers' => Transfer::where('status', 'pending')->count(),

            'new_users_today' => User::where('role', 'user')
                ->whereDate('created_at', today())
                ->count(),
        ];

        $recentPayments = Payment::with('user')
            ->latest('paid_at')
            ->limit(8)
            ->get();

        $recentTransfers = Transfer::with(['user', 'hold'])
            ->latest()
            ->limit(8)
            ->get();

        $recentHolds = PaymentHold::with('user')
            ->whereNull('abandoned_at')
            ->latest()
            ->limit(8)
            ->get();

        return view('admin.dashboard', compact(
            'stats',
            'recentPayments',
            'recentTransfers',
            'recentHolds'
        ));
    }
}
