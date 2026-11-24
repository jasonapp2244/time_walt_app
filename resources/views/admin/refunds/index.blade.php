@extends('layouts.admin')

@section('title', 'Refunds')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Refunds</h4>
                <p class="section-sub">Deposits returned, in full or in part, to the card that paid them</p>
            </div>
            <div class="ms-auto">
                <span style="font-size:13px; color:#92621a; font-weight:700;">{{ $refundsList->total() }} records</span>
            </div>
        </div>

        @if($auditMismatches)
            <div class="alert-error-tv mb-4">
                <i class='bx bx-error-circle me-2'></i>
                <strong>Refund audit found {{ count($auditMismatches) }} mismatch(es)</strong> — deposits whose numbers do not add up:
                @foreach(array_slice($auditMismatches, 0, 10) as $m)
                    <span class="ms-2">#{{ str_pad((string) $m['hold_id'], 5, '0', STR_PAD_LEFT) }} (off by ${{ number_format(abs($m['difference']), 2) }})</span>
                @endforeach
                <div style="font-size:12px; margin-top:4px;">Run <code>php artisan refunds:audit</code> for details. Nothing is changed automatically.</div>
            </div>
        @endif

        @if($totals['attention'] > 0)
            <a href="{{ route('admin.refunds.index', ['attention' => 1]) }}" style="text-decoration:none;">
                <div class="alert-error-tv mb-4"><i class='bx bx-bell me-2'></i>{{ $totals['attention'] }} refund(s) need attention — click to show them.</div>
            </a>
        @endif

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon green mb-2"><i class='bx bx-check-circle'></i></div>
                    <div class="stat-value">${{ number_format($totals['succeeded'], 2) }}</div>
                    <div class="stat-label">Refunded</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon orange mb-2"><i class='bx bx-time-five'></i></div>
                    <div class="stat-value">${{ number_format($totals['pending'], 2) }}</div>
                    <div class="stat-label">Pending at Stripe</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon cream mb-2"><i class='bx bx-x-circle'></i></div>
                    <div class="stat-value">{{ $totals['failed'] }}</div>
                    <div class="stat-label">Failed</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon blue mb-2"><i class='bx bx-bell'></i></div>
                    <div class="stat-value">{{ $totals['attention'] }}</div>
                    <div class="stat-label">Needs attention</div>
                </div>
            </div>
        </div>

        {{-- Chargebacks (Stripe disputes) --}}
        <div class="card mb-4" id="chargebacks">
            <div class="card-header py-3 px-4 d-flex align-items-center flex-wrap gap-2">
                <h6 class="mb-0" style="color:#111827; font-weight:700;">
                    <i class='bx bx-shield-x me-2' style="color:#c82333;"></i>Chargebacks
                </h6>
                <span class="ms-auto" style="font-size:12px; color:#374151; font-weight:600;">
                    <span style="color:#c82333;">{{ $disputeTotals['open'] }} open (${{ number_format($disputeTotals['open_amount'], 2) }} frozen)</span>
                    · {{ $disputeTotals['won'] }} won
                    · ${{ number_format($disputeTotals['lost_amount'], 2) }} lost
                </span>
            </div>
            <div class="card-body p-0">
                @if($disputes->isEmpty())
                    <div class="text-center py-4" style="color:#6b7280; font-size:13px;">
                        No chargebacks. If a customer disputes a deposit with their bank, it appears here and the deposit is frozen automatically.
                    </div>
                @else
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th>Opened</th>
                                <th>User</th>
                                <th>Deposit</th>
                                <th>Amount</th>
                                <th>Reason</th>
                                <th>Result</th>
                                <th>Evidence due</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($disputes as $dispute)
                            <tr>
                                <td style="color:#4b5563; font-size:12px;">{{ $dispute->created_at?->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A') }}</td>
                                <td>
                                    @if($dispute->user_id)
                                        <a href="{{ route('admin.users.show', $dispute->user_id) }}" style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">{{ $dispute->user?->displayName('Unknown') ?? 'Deleted user' }}</a>
                                    @else
                                        <span style="color:#6b7280;">Deleted user</span>
                                    @endif
                                </td>
                                <td style="font-size:12px; font-weight:600; color:#374151;">
                                    @if($dispute->subscription_invoice_id)
                                        <span class="tv-badge badge-active" style="font-size:10px;">Subscription</span>
                                    @else
                                        {{ $dispute->payment_hold_id ? '#'.str_pad((string) $dispute->payment_hold_id, 5, '0', STR_PAD_LEFT) : '—' }}
                                    @endif
                                </td>
                                <td style="color:#92621a; font-weight:700;">${{ number_format((float) $dispute->amount, 2) }}</td>
                                <td style="font-size:12px; color:#374151;">{{ $dispute->reasonLabel() }}</td>
                                <td>
                                    @php
                                        [$label, $badge] = match ($dispute->outcome) {
                                            'won' => ['Won', 'completed'],
                                            'lost' => ['Lost', 'failed'],
                                            default => [$dispute->subscription_invoice_id ? 'Open' : 'Open · deposit frozen', 'pending'],
                                        };
                                    @endphp
                                    <span class="tv-badge badge-{{ $badge }}">{{ $label }}</span>
                                    @if($dispute->needs_attention)
                                        <div style="font-size:11px; color:#c82333; max-width:260px;"><i class='bx bxs-bell-ring'></i> {{ $dispute->note }}</div>
                                    @endif
                                </td>
                                <td style="font-size:12px; color:{{ $dispute->outcome === 'open' ? '#c82333' : '#6b7280' }}; font-weight:600;">
                                    {{ $dispute->outcome === 'open' ? ($dispute->evidence_due_by?->setTimezone(config('app.admin_timezone'))->format('d M Y') ?? '—') : '—' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="font-size:12px; color:#6b7280; padding:10px 16px;">Answer open chargebacks in the Stripe Dashboard (Payments → Disputes) before the evidence date.</div>
                @endif
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">Status:</label>
            <select name="status" class="form-select" style="max-width:160px;">
                <option value="">All</option>
                @foreach(['succeeded' => 'Succeeded', 'pending' => 'Pending', 'failed' => 'Failed', 'canceled' => 'Canceled'] as $value => $label)
                    <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <label style="color:#374151; font-size:13px; font-weight:600;">Source:</label>
            <select name="source" class="form-select" style="max-width:170px;">
                <option value="">All</option>
                <option value="admin" {{ request('source') === 'admin' ? 'selected' : '' }}>Admin panel</option>
                <option value="stripe_dashboard" {{ request('source') === 'stripe_dashboard' ? 'selected' : '' }}>Stripe Dashboard</option>
            </select>
            <label style="color:#374151; font-size:13px; font-weight:600;">From:</label>
            <input type="date" name="from" value="{{ request('from') }}" class="form-control" style="max-width:160px;">
            <label style="color:#374151; font-size:13px; font-weight:600;">To:</label>
            <input type="date" name="to" value="{{ request('to') }}" class="form-control" style="max-width:160px;">
            @if(request('user_id'))
                <input type="hidden" name="user_id" value="{{ request('user_id') }}">
            @endif
            <button type="submit" class="btn-tv">Filter</button>
            @if(request()->hasAny(['status', 'source', 'from', 'to', 'user_id', 'attention']))
                <a href="{{ route('admin.refunds.index') }}" class="btn-tv-outline">Clear</a>
            @endif
        </form>

        @include('admin.partials.list-toolbar', ['exportRoute' => 'admin.refunds.export'])

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th>Refund</th>
                                <th>Date</th>
                                <th>User</th>
                                <th>Deposit</th>
                                <th>Card</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Source</th>
                                <th>Reason</th>
                                <th>Stripe ref / ARN</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($refundsList as $refund)
                            <tr>
                                <td style="color:#2563eb; font-size:12px; font-weight:700;">
                                    {{ str_pad((string) $refund->id, 5, '0', STR_PAD_LEFT) }}
                                    @if($refund->needs_attention)
                                        <i class='bx bxs-bell-ring ms-1' style="color:#c82333;" title="Needs attention"></i>
                                    @endif
                                </td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $refund->created_at?->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A') }}
                                </td>
                                <td>
                                    @if($refund->user_id)
                                        <a href="{{ route('admin.users.show', $refund->user_id) }}" style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">
                                            {{ $refund->user?->displayName('Unknown') ?? 'Deleted user' }}
                                        </a>
                                        <div style="font-size:11px; color:#6b7280;">{{ $refund->user?->displayEmail() ?? '—' }}</div>
                                    @else
                                        <span style="color:#6b7280;">Deleted user</span>
                                    @endif
                                </td>
                                <td style="font-size:12px; font-weight:600; color:#374151;">
                                    {{ $refund->payment_hold_id ? '#'.str_pad((string) $refund->payment_hold_id, 5, '0', STR_PAD_LEFT) : '—' }}
                                    @if($refund->hold?->title)
                                        <div style="font-size:11px; color:#6b7280; font-weight:500;">{{ $refund->hold->title }}</div>
                                    @endif
                                </td>
                                <td style="font-family:monospace; font-size:12px; color:#111827;">{{ $refund->cardLabel() }}</td>
                                <td style="color:#92621a; font-weight:700;">
                                    ${{ number_format((float) $refund->amount, 2) }}
                                    <span class="tv-badge {{ $refund->isPartial() ? 'badge-partial' : 'badge-refunded' }} ms-1" style="font-size:10px;">{{ $refund->isPartial() ? 'Partial' : 'Full' }}</span>
                                    @if((float) $refund->reversed_amount > 0)
                                        <div style="font-size:11px; color:#6b7280; font-weight:500;">${{ number_format((float) $refund->reversed_amount, 2) }} from Connect</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="tv-badge badge-{{ $refund->status }}">{{ ucfirst($refund->status) }}</span>
                                    @if($refund->failure_reason)
                                        <div style="font-size:11px; color:#c82333; max-width:220px;">{{ \Illuminate\Support\Str::limit($refund->failure_reason, 120) }}</div>
                                    @endif
                                </td>
                                <td style="font-size:12px; color:#374151;">
                                    {{ $refund->source === 'admin' ? 'Admin panel' : 'Stripe Dashboard' }}
                                    @if($refund->admin)
                                        <div style="font-size:11px; color:#6b7280;">by {{ $refund->admin->displayName('Admin') }}</div>
                                    @endif
                                </td>
                                <td style="font-size:12px; color:#374151; max-width:220px;">
                                    <strong>{{ $refund->reasonLabel() }}</strong>
                                    @if($refund->reason_note)
                                        <div style="font-size:11px; color:#6b7280;">{{ $refund->reason_note }}</div>
                                    @endif
                                </td>
                                <td style="font-family:monospace; font-size:12px; color:#374151;">
                                    {{ $refund->reference() }}
                                    <div style="font-size:11px; color:#6b7280;" title="Bank tracking number (ARN) to give a user who has not received the money">
                                        ARN: {{ $refund->arn ?? 'not yet' }}
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="10" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bx-undo d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No refunds yet. Refund a deposit from the Payment Holds page.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($refundsList->hasPages())
            <div class="card-footer tv-pagination-footer">
                {{ $refundsList->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection
