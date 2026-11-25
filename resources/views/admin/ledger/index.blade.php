@extends('layouts.admin')

@section('title', 'Ledger')

@section('content')
@php
    $tz = config('app.admin_timezone');
    $money = fn ($v) => '$'.number_format(abs((float) $v), 2);
    $pad = fn ($id) => $id ? '#'.str_pad((string) $id, 5, '0', STR_PAD_LEFT) : '—';
    $kindLabels = [
        'payment' => ['Deposit', 'bx-down-arrow-circle', '#1e9c42'],
        'withdrawal' => ['Withdrawal', 'bx-send', '#0d5ed4'],
        'reversal_refund' => ['Pulled back', 'bx-revision', '#5a32a3'],
        'reversal_manual' => ['Reversed in Stripe', 'bx-revision', '#c82333'],
        'refund' => ['Refund', 'bx-undo', '#5a32a3'],
        'chargeback_opened' => ['Chargeback opened', 'bx-shield-x', '#c87800'],
        'chargeback_won' => ['Chargeback won', 'bx-shield-quarter', '#1e9c42'],
        'chargeback_lost' => ['Chargeback lost', 'bx-shield-x', '#c82333'],
        'subscription' => ['Subscription', 'bx-crown', '#92621a'],
        'subscription_refund' => ['Subscription refund', 'bx-undo', '#92621a'],
    ];
@endphp
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="section-heading">Ledger</h4>
                <p class="section-sub">Every money movement of every user — deposits, withdrawals, refunds, chargebacks and subscriptions — straight from the records the app keeps with Stripe</p>
            </div>
            <div class="ms-auto">
                <span style="font-size:13px; color:#92621a; font-weight:700;">{{ number_format($lines->total()) }} lines</span>
            </div>
        </div>

        {{-- Stripe check (nightly stripe:sync-missed) --}}
        @if($stripeCheck)
            @php $checkedAt = \Illuminate\Support\Carbon::parse($stripeCheck['at'])->setTimezone($tz); @endphp
            @if(! $stripeCheck['ok'] || ! empty($stripeCheck['missing_deposits']))
                <div class="alert-error-tv mb-3">
                    <i class='bx bx-error-circle me-2'></i>
                    <strong>Stripe check {{ $checkedAt->format('d M Y H:i') }}:</strong>
                    @if(! $stripeCheck['ok']) it could not finish ({{ $stripeCheck['errors'] }} error(s)); see the server log. @endif
                    @if(! empty($stripeCheck['missing_deposits']))
                        {{ count($stripeCheck['missing_deposits']) }} deposit(s) are paid in Stripe but not in the app — check them:
                        @foreach($stripeCheck['missing_deposits'] as $line)<span class="ms-2">{{ $line }}</span>@endforeach
                    @endif
                </div>
            @else
                <div class="alert-success-tv mb-3">
                    <i class='bx bx-check-shield me-2'></i>
                    Stripe check {{ $checkedAt->format('d M Y H:i') }}: the ledger matches Stripe.
                    @if($stripeCheck['fixed'] > 0) {{ $stripeCheck['fixed'] }} missed record(s) were found and recorded. @endif
                </div>
            @endif
        @else
            <div class="alert-error-tv mb-3" style="background:rgba(255,152,0,0.08); border-color:rgba(255,152,0,0.3); color:#92621a;">
                <i class='bx bx-time-five me-2'></i>The nightly Stripe check has not run yet. It runs every night at 00:45 UTC (<code>php artisan stripe:sync-missed --apply</code>).
            </div>
        @endif

        {{-- Known: deposits withdrawn and then also refunded to the card (paid out twice) --}}
        @if($paidTwice['deposits'] > 0)
            <div class="mb-3" style="background:rgba(255,152,0,0.08); border:1px solid rgba(255,152,0,0.3); border-left:4px solid #e08900; border-radius:8px; padding:12px 16px; color:#7a4a00; font-size:13px;">
                <div style="font-weight:700; font-size:14px;">
                    <i class='bx bx-info-circle me-1'></i>Paid out twice: {{ $money($paidTwice['total']) }} on {{ $paidTwice['deposits'] }} deposit(s)
                </div>
                <div class="mt-1">
                    These deposits were withdrawn to the user's Stripe account and later <strong>also refunded to the card</strong> in the Stripe Dashboard. The ledger is correct: the user owes this money.
                    @foreach(array_slice($paidTwice['users'], 0, 5) as $u)
                        <a href="{{ route('admin.ledger.index', ['user' => $u['user_id']]) }}" class="ms-1" style="color:#7a4a00; font-weight:600; white-space:nowrap;">user #{{ $u['user_id'] ?? '—' }}: {{ $money($u['owed']) }} ({{ $u['deposits'] }})</a>@if(! $loop->last),@endif
                    @endforeach
                </div>
                <div class="mt-1" style="font-size:12px;">
                    To recover it: Stripe Dashboard → Connect → the user's account → the withdrawal → <strong>Reverse</strong>. The app records the reversal and clears this automatically.
                    <a href="{{ route('admin.refunds.index', ['attention' => 1]) }}" style="color:#7a4a00; font-weight:600;">See the refunds</a>
                </div>
            </div>
        @endif

        {{-- Internal check: every deposit's vault equals the sum of its ledger lines --}}
        @if($mismatches)
            <div class="alert-error-tv mb-3">
                <i class='bx bx-error-circle me-2'></i>
                <strong>{{ count($mismatches) }} deposit(s) do not add up</strong> (vault ≠ ledger, and no flagged refund explains it):
                @foreach(array_slice($mismatches, 0, 10) as $m)
                    <a href="{{ route('admin.ledger.index', ['hold' => $m['hold_id']]) }}" class="ms-2" style="color:inherit;">{{ $pad($m['hold_id']) }} (vault {{ ($m['vault'] < 0 ? '−' : '').$money($m['vault']) }}, ledger {{ ($m['ledger'] < 0 ? '−' : '').$money($m['ledger']) }})</a>
                @endforeach
                <div style="font-size:12px; margin-top:4px;">Nothing is changed automatically. Run <code>php artisan refunds:audit</code> for details.</div>
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
            @foreach([
                ['Deposits', $totals['deposits'], 'green', 'bx-down-arrow-circle'],
                ['Withdrawn', $totals['withdrawn'] - $totals['pulled_back'], 'blue', 'bx-send'],
                ['Refunded', $totals['refunded'], 'purple', 'bx-undo'],
                ['Chargebacks lost', $totals['chargebacks_lost'], 'red', 'bx-shield-x'],
                [$filters['from'] || $filters['to'] || $filters['group'] ? 'Vault change (filtered)' : 'In vaults now', $totals['vault'], 'gold', 'bx-lock-alt'],
                ['Subscriptions (net)', $totals['subscriptions'], 'cream', 'bx-crown'],
            ] as [$label, $value, $color, $icon])
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-icon {{ $color }} mb-2"><i class='bx {{ $icon }}'></i></div>
                        <div class="stat-value" style="font-size:18px;">{{ ($value < 0 ? '−' : '').$money($value) }}</div>
                        <div class="stat-label">{{ $label }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">User:</label>
            <input type="text" name="user" value="{{ $filters['user'] }}" class="form-control" style="max-width:220px;" placeholder="User ID or exact email">
            <label style="color:#374151; font-size:13px; font-weight:600;">Deposit:</label>
            <input type="text" name="hold" value="{{ $filters['hold_id'] }}" class="form-control" style="max-width:110px;" placeholder="#00124">
            <label style="color:#374151; font-size:13px; font-weight:600;">Type:</label>
            <select name="type" class="form-select" style="max-width:160px;">
                <option value="">All</option>
                @foreach(\App\Services\LedgerService::GROUP_LABELS as $value => $label)
                    <option value="{{ $value }}" {{ $filters['group'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <label style="color:#374151; font-size:13px; font-weight:600;">From:</label>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control" style="max-width:160px;">
            <label style="color:#374151; font-size:13px; font-weight:600;">To:</label>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control" style="max-width:160px;">
            <button type="submit" class="btn-tv">Filter</button>
            @if(request()->hasAny(['user', 'hold', 'type', 'from', 'to']))
                <a href="{{ route('admin.ledger.index') }}" class="btn-tv-outline">Clear</a>
            @endif
        </form>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>User</th>
                                <th>Transaction</th>
                                <th class="d-none d-md-table-cell">Deposit</th>
                                <th class="d-none d-md-table-cell">Status</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:right;">Effect</th>
                                <th style="text-align:right;" title="The user's vault balance right after this line">Vault after</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($lines as $line)
                                @php
                                    [$kindLabel, $kindIcon, $kindColor] = $kindLabels[$line->kind] ?? [ucfirst(str_replace('_', ' ', $line->kind)), 'bx-circle', '#374151'];
                                    $r = $line->record;
                                    $effect = $line->effect === null ? null : (float) $line->effect;
                                    $details = match ($line->kind) {
                                        'payment' => $line->status === 'succeeded'
                                            ? 'Paid by '.(($r?->card_brand && $r?->card_last4) ? ucfirst($r->card_brand).' •••• '.$r->card_last4 : 'card')
                                            : 'Payment '.$line->status.($r?->failure_reason ? ': '.\Illuminate\Support\Str::limit($r->failure_reason, 60) : '').' — no money moved',
                                        'withdrawal' => in_array($line->status, ['completed', 'pending', 'processing'], true)
                                            ? 'To the user\'s Stripe account (withdrawal '.$pad($line->source_id).')'
                                            : 'Withdrawal '.$pad($line->source_id).' '.$line->status.' — no money moved',
                                        'reversal_refund' => 'Pulled back from withdrawal '.$pad($line->source_id).' to pay a refund',
                                        'reversal_manual' => 'Withdrawal '.$pad($line->source_id).' reversed by hand in the Stripe Dashboard',
                                        'refund' => ($r ? 'To '.$r->cardLabel() : 'To the card')
                                            .($r?->source === 'stripe_dashboard' ? ' · made in the Stripe Dashboard' : ' · admin panel')
                                            .(in_array($line->status, ['failed', 'canceled'], true) ? ' — no money moved' : '')
                                            .($r ? ' · '.$r->reference() : ''),
                                        'chargeback_opened' => $effect === null
                                            ? 'On a subscription payment'.($r?->reason ? ' · '.str_replace('_', ' ', $r->reason) : '')
                                            : 'Bank dispute; deposit frozen'.($r?->reason ? ' · '.str_replace('_', ' ', $r->reason) : ''),
                                        'chargeback_won' => 'Freeze lifted; the money stays the user\'s',
                                        'chargeback_lost' => $effect === null
                                            ? 'On a subscription payment'
                                            : $money($effect).' taken from the vault'.($r?->needs_attention ? ' · user still owes part of it' : ''),
                                        'subscription' => 'Subscription payment'.($r?->invoice_number ? ' · invoice '.$r->invoice_number : ''),
                                        'subscription_refund' => 'Refunded in Stripe'.($r?->invoice_number ? ' · invoice '.$r->invoice_number : ''),
                                        default => '—',
                                    };
                                @endphp
                                <tr>
                                    <td style="color:#4b5563; font-size:12px; font-weight:500; white-space:nowrap;">
                                        @if($line->occurred_at)
                                            {{ $line->occurred_at->copy()->setTimezone($tz)->format('d M Y') }}
                                            <div style="color:#9ca3af; font-size:11px;">{{ $line->occurred_at->copy()->setTimezone($tz)->format('H:i') }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @if($line->user)
                                            <a href="{{ route('admin.ledger.index', ['user' => $line->user_id]) }}" style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;" title="Show only this user">
                                                {{ $line->user->displayName('Unknown') }}
                                            </a>
                                        @else
                                            <span style="color:#9ca3af;">Deleted user</span>
                                        @endif
                                    </td>
                                    <td style="min-width:220px; white-space:normal;">
                                        <div style="font-size:13px; font-weight:700; color:{{ $kindColor }};"><i class='bx {{ $kindIcon }} me-1'></i>{{ $kindLabel }}</div>
                                        <div style="color:#4b5563; font-size:12px; font-weight:500; line-height:1.4; overflow-wrap:anywhere;">{{ $details }}</div>
                                        <div class="d-md-none mt-1" style="font-size:11px;"><span class="tv-badge badge-{{ ['paid' => 'succeeded', 'won' => 'completed', 'lost' => 'failed', 'open' => 'holding', 'processing' => 'pending'][$line->status] ?? $line->status }}">{{ ucfirst(str_replace('_', ' ', (string) $line->status)) }}</span>@if($line->hold_id) <span style="color:#2563eb; font-weight:700;">{{ $pad($line->hold_id) }}</span>@endif</div>
                                    </td>
                                    <td class="d-none d-md-table-cell" style="font-size:12px; font-weight:700;">
                                        @if($line->hold_id)
                                            <a href="{{ route('admin.ledger.index', ['hold' => $line->hold_id]) }}" style="color:#2563eb; text-decoration:none;" title="Show only this deposit">{{ $pad($line->hold_id) }}</a>
                                        @else
                                            <span style="color:#9ca3af;">—</span>
                                        @endif
                                    </td>
                                    <td class="d-none d-md-table-cell"><span class="tv-badge badge-{{ ['paid' => 'succeeded', 'won' => 'completed', 'lost' => 'failed', 'open' => 'holding', 'processing' => 'pending'][$line->status] ?? $line->status }}">{{ ucfirst(str_replace('_', ' ', (string) $line->status)) }}</span></td>
                                    <td style="text-align:right; color:#111827; font-weight:600; font-size:13px; white-space:nowrap;">{{ $money($line->amount) }}</td>
                                    <td style="text-align:right; font-weight:700; font-size:13px; white-space:nowrap; color:{{ $effect === null ? '#9ca3af' : ($effect > 0 ? '#1e9c42' : ($effect < 0 ? '#c82333' : '#9ca3af')) }};">
                                        @if($effect === null)
                                            <span title="Subscription money is revenue, not vault money">n/a</span>
                                        @elseif($effect == 0)
                                            $0.00
                                        @else
                                            {{ ($effect > 0 ? '+' : '−').$money($effect) }}
                                        @endif
                                    </td>
                                    <td style="text-align:right; color:#374151; font-weight:600; font-size:13px; white-space:nowrap;">
                                        {{ $line->balance_after === null ? '—' : (($line->balance_after < 0 ? '−' : '').$money($line->balance_after)) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-5" style="color:#6b7280;">
                                        <i class='bx bx-book-content d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                        No transactions found
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($lines->hasPages())
                <div class="card-footer tv-pagination-footer">
                    {{ $lines->links() }}
                </div>
            @endif
        </div>

        <p class="mt-3" style="font-size:12px; color:#6b7280;">
            <strong>How to read it:</strong> "Vault effect" is how the line changed the money Time Vault holds for that user
            (deposit +, withdrawal −, money pulled back +, refund −, lost chargeback −). "Vault after" is that user's balance right after the line.
            Failed payments, withdrawals and refunds are listed for the record with $0.00 effect. Stripe is checked every night and anything missed is recorded automatically.
        </p>

    </div>
</div>
@endsection
