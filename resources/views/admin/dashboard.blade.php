@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        {{-- Page Header --}}
        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Dashboard</h4>
                <p class="section-sub">Welcome back, {{ auth()->user()->full_name ?? 'Admin' }}</p>
            </div>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span id="stats-last-updated" style="font-size:11px; color:#9ca3af; font-weight:500; display:none;">
                    <i class='bx bx-refresh me-1'></i>Updated <span id="stats-updated-time"></span>
                </span>
                <span style="font-size:12px; color:#4b5563; font-weight:500;">
                    <i class='bx bx-time me-1' style="color:var(--tv-gold);"></i>{{ now()->format('D, d M Y — H:i') }}
                </span>
            </div>
        </div>

        {{-- ── Stats Row 1 ──────────────────────────────────────────────── --}}
        <div class="row g-3 mb-4">

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon gold"><i class='bx bxs-user-account'></i></div>
                        <span class="tv-badge badge-active">Live</span>
                    </div>
                    <div class="stat-value" data-stat="total_users">{{ number_format($stats['total_users']) }}</div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-sub"><i class='bx bx-plus-circle me-1'></i><span data-stat="new_users_today">{{ $stats['new_users_today'] }}</span> joined today</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon green"><i class='bx bxs-dollar-circle'></i></div>
                    </div>
                    <div class="stat-value" data-stat="total_revenue" data-format="money">${{ number_format($stats['total_revenue'], 2) }}</div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-sub">Succeeded payments</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon orange"><i class='bx bxs-lock-alt'></i></div>
                    </div>
                    <div class="stat-value" data-stat="holds_holding">{{ number_format($stats['holds_holding']) }}</div>
                    <div class="stat-label">Active Vaults</div>
                    <div class="stat-sub" style="color:#c87000; font-weight:600;">
                        $<span data-stat="total_holding_amount" data-format="decimal">{{ number_format($stats['total_holding_amount'], 2) }}</span> locked
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon cream"><i class='bx bxs-lock-open-alt'></i></div>
                    </div>
                    <div class="stat-value" data-stat="holds_ready">{{ number_format($stats['holds_ready']) }}</div>
                    <div class="stat-label">Ready to Withdraw</div>
                    <div class="stat-sub" style="color:#92621a; font-weight:600;">
                        $<span data-stat="total_ready_amount" data-format="decimal">{{ number_format($stats['total_ready_amount'], 2) }}</span> available
                    </div>
                </div>
            </div>

        </div>

        {{-- ── Stats Row 2 ──────────────────────────────────────────────── --}}
        <div class="row g-3 mb-4">

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon teal"><i class='bx bxs-send'></i></div>
                    </div>
                    <div class="stat-value" data-stat="total_transferred_amount" data-format="money">${{ number_format($stats['total_transferred_amount'], 2) }}</div>
                    <div class="stat-label">Total Transferred</div>
                    <div class="stat-sub"><span data-stat="holds_transferred">{{ number_format($stats['holds_transferred']) }}</span> completed holds</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon purple"><i class='bx bx-time-five'></i></div>
                    </div>
                    <div class="stat-value" data-stat="pending_transfers">{{ number_format($stats['pending_transfers']) }}</div>
                    <div class="stat-label">Pending Transfers</div>
                    <div class="stat-sub">Awaiting Stripe confirmation</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon blue"><i class='bx bxs-wallet'></i></div>
                    </div>
                    <div class="stat-value" data-stat="holds_transferred">{{ number_format($stats['holds_transferred']) }}</div>
                    <div class="stat-label">Transferred Holds</div>
                    <div class="stat-sub">Full &amp; partial transfers</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card" style="border-color:rgba(189,126,46,0.3);">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon gold"><i class='bx bxs-layer'></i></div>
                    </div>
                    <div class="stat-value" data-stat="total_holds_count" style="color:var(--tv-gold);">
                        {{ number_format($stats['total_holds_count']) }}
                    </div>
                    <div class="stat-label">Total Payment Holds</div>
                    <div class="stat-sub" style="color:#92621a; font-weight:600;">
                        $<span data-stat="total_holds_amount" data-format="decimal">{{ number_format($stats['total_holds_amount'], 2) }}</span> total
                    </div>
                </div>
            </div>

        </div>

        {{-- ── Recent Data Tables ────────────────────────────────────────── --}}
        <div class="row g-3">

            {{-- Recent Payments --}}
            <div class="col-12 col-xl-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between py-3 px-4">
                        <h6 class="mb-0" style="color:#111827; font-weight:700; font-size:14px;">
                            <i class='bx bxs-credit-card me-2' style="color:var(--tv-gold);"></i>Recent Payments
                        </h6>
                        <a href="{{ route('admin.payments.index') }}" class="btn-tv-outline">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($recentPayments as $payment)
                                    <tr>
                                        <td>
                                            <div style="font-weight:600; color:#111827; font-size:13px;">
                                                {{ $payment->user?->full_name ?? 'Unknown' }}
                                            </div>
                                            <div style="font-size:11px; color:#6b7280; margin-top:1px;">
                                                {{ $payment->user?->email ?? '' }}
                                            </div>
                                        </td>
                                        <td style="color:#92621a; font-weight:700;">
                                            ${{ number_format($payment->amount, 2) }}
                                        </td>
                                        <td>
                                            <span class="tv-badge badge-{{ $payment->status }}">
                                                {{ ucfirst($payment->status) }}
                                            </span>
                                        </td>
                                        <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                            {{ $payment->paid_at?->format('d M Y') ?? '—' }}
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="4" class="text-center py-4" style="color:#6b7280; font-size:13px;">
                                            <i class='bx bx-credit-card d-block mb-1' style="font-size:24px; color:#d4963e;"></i>
                                            No payments yet
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Recent Transfers --}}
            <div class="col-12 col-xl-6">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between py-3 px-4">
                        <h6 class="mb-0" style="color:#111827; font-weight:700; font-size:14px;">
                            <i class='bx bxs-send me-2' style="color:var(--tv-gold);"></i>Recent Transfers
                        </h6>
                        <a href="{{ route('admin.transfers.index') }}" class="btn-tv-outline">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Amount</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($recentTransfers as $transfer)
                                    <tr>
                                        <td>
                                            <div style="font-weight:600; color:#111827; font-size:13px;">
                                                {{ $transfer->user?->full_name ?? 'Unknown' }}
                                            </div>
                                        </td>
                                        <td style="color:#1e8c3a; font-weight:700;">
                                            ${{ number_format($transfer->amount, 2) }}
                                        </td>
                                        <td style="font-size:12px; color:#374151; font-weight:500;">
                                            {{ str_replace('_', ' ', ucfirst($transfer->transfer_type ?? '—')) }}
                                        </td>
                                        <td>
                                            <span class="tv-badge badge-{{ $transfer->status }}">
                                                {{ ucfirst($transfer->status) }}
                                            </span>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="4" class="text-center py-4" style="color:#6b7280; font-size:13px;">
                                            <i class='bx bx-send d-block mb-1' style="font-size:24px; color:#d4963e;"></i>
                                            No transfers yet
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Recent Holds --}}
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between py-3 px-4">
                        <h6 class="mb-0" style="color:#111827; font-weight:700; font-size:14px;">
                            <i class='bx bxs-lock-alt me-2' style="color:var(--tv-gold);"></i>Recent Payment Holds
                        </h6>
                        <a href="{{ route('admin.payment-holds.index') }}" class="btn-tv-outline">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Title</th>
                                        <th>Amount</th>
                                        <th>Hold Period</th>
                                        <th>Unlock Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($recentHolds as $hold)
                                    <tr>
                                        <td>
                                            <div style="font-weight:600; color:#111827; font-size:13px;">
                                                {{ $hold->user?->full_name ?? 'Unknown' }}
                                            </div>
                                        </td>
                                        <td style="color:#1f2937; font-size:13px; font-weight:500;">
                                            {{ $hold->title ?? '—' }}
                                        </td>
                                        <td style="color:#92621a; font-weight:700;">
                                            ${{ number_format($hold->amount, 2) }}
                                        </td>
                                        <td style="color:#374151; font-size:12px; font-weight:500;">
                                            @php
                                                $parts = [];
                                                if ($hold->hold_days) $parts[] = $hold->hold_days . 'd';
                                                if ($hold->hold_hours) $parts[] = $hold->hold_hours . 'h';
                                                if ($hold->hold_minutes) $parts[] = $hold->hold_minutes . 'm';
                                            @endphp
                                            {{ $parts ? implode(' ', $parts) : ($hold->hold_period_type ? str_replace('_', ' ', $hold->hold_period_type) : '—') }}
                                        </td>
                                        <td style="color:#374151; font-size:12px; font-weight:500;">
                                            {{ $hold->hold_end_at?->format('d M Y, h:i A') ?? '—' }}
                                        </td>
                                        <td>
                                            <span class="tv-badge badge-{{ str_replace('_','-',$hold->status) }}">
                                                {{ ucwords(str_replace('_', ' ', $hold->status)) }}
                                            </span>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4" style="color:#6b7280; font-size:13px;">
                                            <i class='bx bx-lock d-block mb-1' style="font-size:24px; color:#d4963e;"></i>
                                            No payment holds yet
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- end row --}}

    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const POLL_INTERVAL = 1 * 60 * 1000; // 1 minute — testing (change back to 5 * 60 * 1000 in production)
    const STATS_URL = '{{ route("admin.dashboard.stats") }}';

    function fmt(n) {
        return new Intl.NumberFormat('en-US').format(n);
    }

    function fmtDecimal(n) {
        return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
    }

    // Snapshot on page load to detect table-level changes
    const initialSnapshot = {
        total_users:       {{ $stats['total_users'] }},
        total_holds_count: {{ $stats['total_holds_count'] }},
        pending_transfers: {{ $stats['pending_transfers'] }},
        holds_transferred: {{ $stats['holds_transferred'] }},
    };
    let reloading = false;

    function autoReload(message) {
        if (reloading) { return; }
        reloading = true;

        const label = document.getElementById('stats-last-updated');
        const timeEl = document.getElementById('stats-updated-time');
        let secs = 5;

        if (label) {
            label.style.display = 'inline-flex';
            label.style.color = '#c87000';
            label.innerHTML = `<i class='bx bx-refresh me-1'></i>${message} — reloading in <span id="tv-cd">${secs}</span>s`;
            label.style.cursor = 'pointer';
            label.onclick = () => window.location.reload();
        }

        const timer = setInterval(() => {
            secs--;
            const cd = document.getElementById('tv-cd');
            if (cd) { cd.textContent = secs; }
            if (secs <= 0) { clearInterval(timer); window.location.reload(); }
        }, 1000);
    }

    function applyStats(data) {
        const map = {
            total_users:              fmt(data.total_users),
            new_users_today:          fmt(data.new_users_today),
            holds_holding:            fmt(data.holds_holding),
            total_holding_amount:     fmtDecimal(data.total_holding_amount),
            holds_ready:              fmt(data.holds_ready),
            total_ready_amount:       fmtDecimal(data.total_ready_amount),
            holds_transferred:        fmt(data.holds_transferred),
            total_transferred_amount: '$' + fmtDecimal(data.total_transferred_amount),
            total_revenue:            '$' + fmtDecimal(data.total_revenue),
            pending_transfers:        fmt(data.pending_transfers),
            total_holds_count:        fmt(data.total_holds_count),
            total_holds_amount:       fmtDecimal(data.total_holds_amount),
        };

        Object.entries(map).forEach(([key, value]) => {
            document.querySelectorAll('[data-stat="' + key + '"]').forEach(el => {
                if (el.textContent.trim() !== value) {
                    el.style.transition = 'opacity 0.3s';
                    el.style.opacity = '0.3';
                    setTimeout(() => { el.textContent = value; el.style.opacity = '1'; }, 300);
                }
            });
        });

        // Update "last updated" timestamp
        const updatedEl = document.getElementById('stats-updated-time');
        const labelEl   = document.getElementById('stats-last-updated');
        if (!reloading) {
            if (updatedEl) {
                updatedEl.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            }
            if (labelEl) { labelEl.style.display = 'inline-flex'; }
        }

        // Auto-reload when recent-table data is stale (new users, holds, or transfers changed)
        const tableChanged = data.total_users       !== initialSnapshot.total_users
                          || data.total_holds_count !== initialSnapshot.total_holds_count
                          || data.pending_transfers !== initialSnapshot.pending_transfers
                          || data.holds_transferred !== initialSnapshot.holds_transferred;

        if (tableChanged) {
            autoReload('New data available');
        }
    }

    function pollStats() {
        fetch(STATS_URL, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(applyStats)
        .catch(err => console.warn('[Dashboard] Stats poll failed:', err));
    }

    setTimeout(pollStats, 5000);           // first check after 5 seconds
    setInterval(pollStats, POLL_INTERVAL); // then every minute
})();
</script>
@endpush
