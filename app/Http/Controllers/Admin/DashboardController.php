<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the admin dashboard with real-time stats.
     */
    public function index(): View
    {
        $stats = $this->buildStats();

        $recentPayments = Payment::with('user')
            ->latest()
            ->limit(5)
            ->get();

        $recentTransfers = Transfer::with(['user', 'hold'])
            ->latest()
            ->limit(5)
            ->get();

        $recentHolds = PaymentHold::with('user')
            ->whereNull('abandoned_at')
            ->latest()
            ->limit(5)
            ->get();

        return view('admin.dashboard', compact(
            'stats',
            'recentPayments',
            'recentTransfers',
            'recentHolds'
        ));
    }

    /**
     * Return live dashboard stats as JSON for AJAX polling.
     */
    public function stats(): JsonResponse
    {
        return response()->json($this->buildStats());
    }

    /**
     * @return array{total_users: int, total_revenue: float, holds_holding: int, total_holding_amount: float, holds_ready: int, total_ready_amount: float, holds_transferred: int, total_transferred_amount: float, pending_transfers: int, new_users_today: int}
     */
    private function buildStats(): array
    {
        return [
            'total_users' => User::where('role', 'user')
                ->where('status', '!=', 'deleted')
                ->count(),

            'total_revenue' => (float) Payment::where('status', 'succeeded')
                ->sum('amount'),

            'holds_holding' => PaymentHold::where('status', 'holding')
                ->whereNull('abandoned_at')
                ->count(),

            'total_holding_amount' => (float) PaymentHold::where('status', 'holding')
                ->whereNull('abandoned_at')
                ->sum('amount'),

            'holds_ready' => PaymentHold::whereIn('status', ['ready_for_transfer', 'partial_transferred'])
                ->whereNull('abandoned_at')
                ->count(),

            'total_ready_amount' => (float) PaymentHold::whereIn('status', ['ready_for_transfer', 'partial_transferred'])
                ->whereNull('abandoned_at')
                ->sum('remaining_amount'),

            'holds_transferred' => PaymentHold::whereIn('status', ['transferred', 'partial_transferred'])
                ->whereNull('abandoned_at')
                ->count(),

            'total_transferred_amount' => (float) Transfer::where('status', 'completed')
                ->sum('amount'),

            'pending_transfers' => Transfer::where('status', 'pending')->count(),

            'new_users_today' => User::where('role', 'user')
                ->whereDate('created_at', today())
                ->count(),

            'total_holds_count' => PaymentHold::whereIn('status', ['holding', 'ready_for_transfer'])
                ->whereNull('abandoned_at')
                ->count(),

            'total_holds_amount' => (float) PaymentHold::whereIn('status', ['holding', 'ready_for_transfer'])
                ->whereNull('abandoned_at')
                ->sum('amount'),
        ];
    }
}
