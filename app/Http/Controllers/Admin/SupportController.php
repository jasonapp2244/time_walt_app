<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportController extends Controller
{
    /**
     * List all support requests with optional search and pagination.
     *
     * Subject and message are plain columns so they can be searched with LIKE.
     * The user's email is encrypted, so an email search goes through the blind
     * index and only matches in full, exactly as the users list does.
     */
    public function index(Request $request): View
    {
        $query = SupportRequest::with('user');

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', '%'.$search.'%')
                    ->orWhere('message', 'like', '%'.$search.'%')
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('email_index', User::blindIndex(strtolower($search)));
                    });
            });
        }

        $supportRequests = $query->latest()->paginate(15)->withQueryString();

        // The summary describes the whole table, not the filtered page, and the
        // day boundaries follow the admin's timezone rather than stored UTC.
        $adminTimezone = config('app.admin_timezone', 'UTC');

        $totalCount = SupportRequest::count();

        $todayCount = SupportRequest::where(
            'created_at', '>=', now($adminTimezone)->startOfDay()->utc()
        )->count();

        $weekCount = SupportRequest::where(
            'created_at', '>=', now($adminTimezone)->subDays(6)->startOfDay()->utc()
        )->count();

        return view('admin.support.index', compact(
            'supportRequests',
            'totalCount',
            'todayCount',
            'weekCount'
        ));
    }
}
