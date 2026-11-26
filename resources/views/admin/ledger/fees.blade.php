@extends('layouts.admin')

@section('title', 'Stripe fees')

@section('content')
@php
    $tz = config('app.admin_timezone');
    $money = fn ($v) => '$'.number_format(abs((float) $v), 2);
    $signed = fn ($v) => ((float) $v < 0 ? '−' : '').$money($v);
    $pad = fn ($id) => $id ? '#'.str_pad((string) $id, 5, '0', STR_PAD_LEFT) : '—';
    $categoryColors = [
        'deposit' => '#1e9c42',
        'subscription' => '#92621a',
        'chargeback' => '#c82333',
        'refund' => '#5a32a3',
        'platform' => '#0d5ed4',
        'other' => '#4b5563',
    ];
    $syncAt = $feeSync ? \Illuminate\Support\Carbon::parse($feeSync['at']) : null;
    $syncStale = $syncAt === null || $syncAt->lt(now()->subHours(3));
@endphp
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="section-heading">Stripe fees</h4>
                <p class="section-sub">Every fee Stripe kept: card fees on deposits and subscriptions, chargeback fees, and account fees (Connect, Radar). Read from Stripe automatically.</p>
            </div>
            <div class="ms-auto">
                <span style="font-size:13px; color:#92621a; font-weight:700;">{{ number_format($fees->total()) }} fee records</span>
            </div>
        </div>

        @include('admin.ledger.partials.tabs', ['active' => 'fees'])

        {{-- Fee sync (hourly stripe:sync-fees) --}}
        @if($feeSync && ! $feeSync['ok'])
            <div class="alert-error-tv mb-3">
                <i class='bx bx-error-circle me-2'></i>
                <strong>Fee sync {{ $syncAt->copy()->setTimezone($tz)->format('d M Y H:i') }}</strong> could not finish ({{ $feeSync['errors'] }} error(s)); see the server log. The fees below may be incomplete.
            </div>
        @elseif($syncStale)
            <div class="alert-error-tv mb-3" style="background:rgba(255,152,0,0.08); border-color:rgba(255,152,0,0.3); color:#92621a;">
                <i class='bx bx-time-five me-2'></i>
                @if($syncAt)
                    Fees last synced {{ $syncAt->copy()->setTimezone($tz)->format('d M Y H:i') }}, more than 3 hours ago. The sync runs every hour; check the scheduler.
                @else
                    The fee sync has not run yet. It runs every hour (<code>php artisan stripe:sync-fees --apply</code>); for older fees run it once with <code>--since=2026-01-01 --apply</code>.
                @endif
            </div>
        @else
            <div class="alert-success-tv mb-3">
                <i class='bx bx-check-shield me-2'></i>Fees last synced from Stripe {{ $syncAt->copy()->setTimezone($tz)->format('d M Y H:i') }}.
            </div>
        @endif

        @if($notFound)
            <div class="alert-error-tv mb-3"><i class='bx bx-search me-2'></i>No user with ID or email "{{ $filters['user'] }}".</div>
        @elseif($filterUser)
            <div class="mb-3" style="font-size:14px; color:#374151;">
                Showing <a href="{{ route('admin.users.show', $filterUser) }}" style="font-weight:700; color:#111827;">{{ $filterUser->displayName('Unknown') }}</a>
                <span style="color:#6b7280;">({{ $filterUser->displayEmail() }}, user #{{ $filterUser->id }})</span>
            </div>
        @endif

        <div class="row g-3 mb-4">
            @foreach(array_filter([
                [$filterUser ? 'This user\'s Stripe fees' : 'Total Stripe fees', $totals['total'], 'red', 'bx-receipt'],
                $filterUser ? null : ['Fees on users\' payments', $totals['users'], 'gold', 'bx-user'],
                $filterUser ? null : ['Platform / Connect fees', $totals['platform'], 'blue', 'bx-buildings'],
                ! $filterUser && $totals['unlinked'] != 0 ? ['Not linked to a user', $totals['unlinked'], 'cream', 'bx-unlink'] : ['Fee records', null, 'cream', 'bx-list-ul'],
            ]) as [$label, $value, $color, $icon])
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon {{ $color }} mb-2"><i class='bx {{ $icon }}'></i></div>
                        <div class="stat-value" style="font-size:18px;">{{ $value === null ? number_format($totals['records']) : $signed($value) }}</div>
                        <div class="stat-label">{{ $label }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($totals['by_category'])
            <div class="mb-3" style="font-size:13px; color:#374151;">
                <strong>By type:</strong>
                @foreach($totals['by_category'] as $category => $amount)
                    <a href="{{ route('admin.ledger.fees', array_filter(['user' => $filters['user'], 'from' => $filters['from'], 'to' => $filters['to'], 'category' => $category])) }}" class="ms-2" style="color:{{ $categoryColors[$category] ?? '#374151' }}; font-weight:600; white-space:nowrap; text-decoration:none;">
                        {{ \App\Models\StripeFee::CATEGORIES[$category] ?? ucfirst($category) }}: {{ $signed($amount) }}
                    </a>
                @endforeach
            </div>
        @endif

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">User:</label>
            <input type="text" name="user" value="{{ $filters['user'] }}" class="form-control" style="max-width:220px;" placeholder="User ID or exact email">
            <label style="color:#374151; font-size:13px; font-weight:600;">Type:</label>
            <select name="category" class="form-select" style="max-width:200px;">
                <option value="">All</option>
                @foreach(\App\Models\StripeFee::CATEGORIES as $value => $label)
                    <option value="{{ $value }}" {{ $filters['category'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <label style="color:#374151; font-size:13px; font-weight:600;">From:</label>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control" style="max-width:160px;">
            <label style="color:#374151; font-size:13px; font-weight:600;">To:</label>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control" style="max-width:160px;">
            <button type="submit" class="btn-tv">Filter</button>
            @if(request()->hasAny(['user', 'category', 'from', 'to']))
                <a href="{{ route('admin.ledger.fees') }}" class="btn-tv-outline">Clear</a>
            @endif
        </form>

        @if(! $filterUser && $totals['top_users'])
            <div class="card mb-4">
                <div class="card-body p-0">
                    <div style="padding:12px 16px; font-weight:700; color:#111827; font-size:14px;">Users with the most fees</div>
                    <div class="table-responsive">
                        <table class="table mb-0 tv-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th style="text-align:right;">Fee records</th>
                                    <th style="text-align:right;">Stripe fees</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($totals['top_users'] as $u)
                                    <tr>
                                        <td style="font-size:13px; font-weight:600; color:#111827;">
                                            {{ $u['user']?->displayName('Unknown') ?? 'Deleted user' }}
                                            <span style="color:#6b7280; font-weight:500;">#{{ $u['user_id'] }}</span>
                                        </td>
                                        <td style="text-align:right; font-size:13px;">{{ number_format($u['records']) }}</td>
                                        <td style="text-align:right; font-size:13px; font-weight:700; color:#b45309;">{{ $signed($u['fees']) }}</td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <a href="{{ route('admin.ledger.fees', array_filter(['user' => $u['user_id'], 'from' => $filters['from'], 'to' => $filters['to']])) }}" style="font-size:12px; font-weight:600;">Fees</a>
                                            <a href="{{ route('admin.ledger.index', ['user' => $u['user_id']]) }}" class="ms-2" style="font-size:12px; font-weight:600;">Ledger</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        @include('admin.partials.list-toolbar', ['exportRoute' => 'admin.ledger.fees.export'])

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>User</th>
                                <th>Fee for</th>
                                <th class="d-none d-md-table-cell">Deposit</th>
                                <th class="d-none d-md-table-cell" style="text-align:right;">Gross</th>
                                <th style="text-align:right;">Fee</th>
                                <th class="d-none d-md-table-cell" style="text-align:right;">Net</th>
                                <th class="d-none d-lg-table-cell">Stripe ref</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($fees as $fee)
                                @php $hold = $fee->payment?->hold; @endphp
                                <tr>
                                    <td style="color:#4b5563; font-size:12px; font-weight:500; white-space:nowrap;">
                                        {{ $fee->occurred_at?->copy()->setTimezone($tz)->format('d M Y') ?? '—' }}
                                        <div style="color:#9ca3af; font-size:11px;">{{ $fee->occurred_at?->copy()->setTimezone($tz)->format('H:i') }}</div>
                                    </td>
                                    <td>
                                        @if($fee->user)
                                            <a href="{{ route('admin.ledger.fees', array_filter(['user' => $fee->user_id, 'from' => $filters['from'], 'to' => $filters['to']])) }}" style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;" title="Show only this user's fees">
                                                {{ $fee->user->displayName('Unknown') }}
                                            </a>
                                        @elseif($fee->user_id)
                                            <span style="color:#9ca3af;">Deleted user</span>
                                        @elseif($fee->category === 'platform')
                                            <span style="color:#0d5ed4; font-size:12px; font-weight:600;">Platform (no user)</span>
                                        @else
                                            <span style="color:#9ca3af; font-size:12px; font-weight:600;" title="Stripe has this payment but the app has no record of it">No user found</span>
                                        @endif
                                    </td>
                                    <td style="min-width:200px; white-space:normal;">
                                        <div style="font-size:13px; font-weight:700; color:{{ $categoryColors[$fee->category] ?? '#374151' }};">{{ $fee->categoryLabel() }}</div>
                                        <div style="color:#4b5563; font-size:12px; font-weight:500; line-height:1.4; overflow-wrap:anywhere;">{{ $fee->description ?? ucfirst(str_replace('_', ' ', $fee->type)) }}</div>
                                    </td>
                                    <td class="d-none d-md-table-cell" style="font-size:12px; font-weight:700;">
                                        @if($hold)
                                            <a href="{{ route('admin.ledger.index', ['hold' => $hold->id]) }}" style="color:#2563eb; text-decoration:none;" title="Show this deposit in the ledger">{{ $pad($hold->id) }}</a>
                                        @else
                                            <span style="color:#9ca3af;">—</span>
                                        @endif
                                    </td>
                                    <td class="d-none d-md-table-cell" style="text-align:right; font-size:13px; color:#374151; white-space:nowrap;">{{ $signed($fee->gross) }}</td>
                                    <td style="text-align:right; font-size:13px; font-weight:700; white-space:nowrap; color:{{ (float) $fee->fee > 0 ? '#b45309' : '#1e9c42' }};">
                                        {{ ((float) $fee->fee > 0 ? '−' : '+').$money($fee->fee) }}
                                    </td>
                                    <td class="d-none d-md-table-cell" style="text-align:right; font-size:13px; color:#374151; white-space:nowrap;">{{ $signed($fee->net) }}</td>
                                    <td class="d-none d-lg-table-cell" style="font-size:12px; color:#6b7280; font-family:monospace;">{{ $fee->reference() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-5" style="color:#6b7280;">
                                        <i class='bx bx-receipt d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                        No fees found for these filters
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($fees->hasPages())
                <div class="card-footer tv-pagination-footer">
                    {{ $fees->links() }}
                </div>
            @endif
        </div>

        <p class="mt-3" style="font-size:12px; color:#6b7280;">
            <strong>How to read it:</strong> each row is one Stripe balance transaction where Stripe kept a fee. "Fee" is what Stripe kept ("+" when Stripe gave a fee back).
            Failed or canceled payments never cost a fee, so they are not listed. With no filter, the total equals Stripe Dashboard → Reports → Balance → Fees for the same dates.
            Search the Stripe ref in the Stripe Dashboard (Balance → Transactions) to see the original.
        </p>

    </div>
</div>
@endsection
