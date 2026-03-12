<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
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

        $payments = $query->latest('paid_at')->paginate(20)->withQueryString();

        $statusCounts = [
            'completed' => Payment::where('status', 'completed')->count(),
            'pending' => Payment::where('status', 'pending')->count(),
            'failed' => Payment::where('status', 'failed')->count(),
        ];

        $totalRevenue = Payment::where('status', 'completed')->sum('amount');

        return view('admin.payments.index', compact('payments', 'statusCounts', 'totalRevenue'));
    }
}
