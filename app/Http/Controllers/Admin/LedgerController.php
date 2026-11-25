<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\StripeSyncMissed;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * The money ledger: every deposit, withdrawal, refund, chargeback, reversal
 * and subscription payment of every user in one list. Read-only.
 */
class LedgerController extends Controller
{
    public function __construct(
        protected LedgerService $ledger
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $user = $filters['user_id'] ? User::find($filters['user_id']) : null;

        return view('admin.ledger.index', [
            'lines' => $this->ledger->page($filters)->withQueryString(),
            'totals' => $this->ledger->totals($filters),
            'mismatches' => $this->ledger->mismatches(),
            'stripeCheck' => Cache::get(StripeSyncMissed::CACHE_KEY),
            'filters' => $filters,
            'filterUser' => $user,
            'notFound' => $filters['user_id'] === 0,
        ]);
    }

    /** Only known values reach the query. */
    private function filters(Request $request): array
    {
        $userId = null;
        $raw = $request->query('user');
        $search = is_string($raw) ? trim($raw) : '';

        if ($search !== '') {
            $userId = ctype_digit($search)
                ? User::whereKey((int) $search)->value('id')
                : User::where('email_index', User::blindIndex(strtolower($search)))->value('id');
            $userId ??= 0; // unknown user: show no lines rather than everyone's
        }

        $date = fn (string $key) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query($key))
            ? $request->query($key)
            : null;

        $group = $request->query('type');
        $hold = $request->query('hold');

        return [
            'user_id' => $userId,
            'user' => $search,
            'group' => is_string($group) && isset(LedgerService::GROUPS[$group]) ? $group : null,
            'hold_id' => is_string($hold) && ctype_digit(ltrim($hold, '#')) ? (int) ltrim($hold, '#') : null,
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }
}
