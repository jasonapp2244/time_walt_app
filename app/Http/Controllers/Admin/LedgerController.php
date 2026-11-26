<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\StripeSyncFees;
use App\Console\Commands\StripeSyncMissed;
use App\Http\Controllers\Controller;
use App\Models\StripeFee;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\ListExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The money ledger: every deposit, withdrawal, refund, chargeback, reversal
 * and subscription payment of every user in one list, plus every fee Stripe
 * kept (the Fees tab). Read-only.
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
            'paidTwice' => $this->ledger->paidTwice(),
            'stripeCheck' => Cache::get(StripeSyncMissed::CACHE_KEY),
            'feeSync' => Cache::get(StripeSyncFees::CACHE_KEY),
            // All of this user's Stripe fees (same number as the Fees tab), including fees on payments with no ledger line.
            'userFees' => $user ? $this->ledger->feeTotals(['user_id' => $user->id, 'from' => $filters['from'], 'to' => $filters['to']])['total'] : null,
            'filters' => $filters,
            'filterUser' => $user,
            'notFound' => $filters['user_id'] === 0,
        ]);
    }

    /** Every fee Stripe kept: per record, per user and in total. */
    public function fees(Request $request): View
    {
        $filters = $this->feeFilters($request);

        return view('admin.ledger.fees', [
            'fees' => $this->ledger->fees($filters)->withQueryString(),
            'totals' => $this->ledger->feeTotals($filters),
            'feeSync' => Cache::get(StripeSyncFees::CACHE_KEY),
            'filters' => $filters,
            'filterUser' => $filters['user_id'] ? User::find($filters['user_id']) : null,
            'notFound' => $filters['user_id'] === 0,
        ]);
    }

    public function feesExport(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';
        $filters = $this->feeFilters($request);

        return ListExport::download(
            $format,
            'Stripe fees',
            $this->ledger->feeQuery($filters)->with(['user', 'payment.hold'])->orderByDesc('occurred_at')->orderByDesc('id'),
            ['Date', 'User ID', 'User', 'Email', 'Deposit', 'Category', 'Description', 'Gross', 'Fee', 'Net', 'Stripe ref'],
            fn (StripeFee $f) => [
                $f->occurred_at,
                $f->user_id,
                $f->user?->displayName() ?? ($f->user_id ? 'Deleted user' : ($f->category === 'platform' ? 'Platform (no user)' : 'No user found')),
                $f->user?->displayEmail() ?? '—',
                $f->payment?->hold ? str_pad((string) $f->payment->hold->id, 5, '0', STR_PAD_LEFT) : '—',
                $f->categoryLabel(),
                $f->description ?? '—',
                (float) $f->gross,
                (float) $f->fee,
                (float) $f->net,
                $f->reference(),
            ],
            array_filter([
                'User' => $filters['user'],
                'Category' => $filters['category'] ? StripeFee::CATEGORIES[$filters['category']] : null,
                'From' => $filters['from'],
                'To' => $filters['to'],
                'Total fees' => '$'.number_format($this->ledger->feeTotals($filters)['total'], 2),
            ]),
        );
    }

    /** The ledger filters plus the fee category. */
    private function feeFilters(Request $request): array
    {
        $category = $request->query('category');

        return $this->filters($request) + [
            'category' => is_string($category) && isset(StripeFee::CATEGORIES[$category]) ? $category : null,
        ];
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
