<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * List all non-admin users with search and pagination.
     *
     * Because email and full_name are encrypted, free-text search is not possible via SQL LIKE.
     * We support: exact email lookup via blind index, or lookup by numeric user ID.
     */
    public function index(Request $request): View
    {
        $query = User::where('role', 'user')
            ->withCount(['paymentHolds', 'transfers']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            if (is_numeric($search)) {
                $query->where('id', (int) $search);
            } else {
                $query->where('email_index', User::blindIndex(strtolower($search)));
            }
        }

        $users = $query->latest()->paginate(20)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show a single user with all their activity.
     */
    public function show(User $user): View
    {
        $user->load([
            'notificationSettings',
            'paymentHolds.payment',
            'paymentHolds.transfer',
            'transfers.hold',
        ]);

        $userAmounts = [
            'total_held' => $user->paymentHolds->sum('amount'),
            'total_ready' => $user->paymentHolds
                ->where('status', 'ready_for_transfer')
                ->sum('amount'),
            'total_withdrawn' => $user->transfers
                ->where('status', 'completed')
                ->sum('amount'),
        ];

        return view('admin.users.show', compact('user', 'userAmounts'));
    }
}
