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
            <div class="ms-auto">
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
                    <div class="stat-value">{{ number_format($stats['total_users']) }}</div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-sub"><i class='bx bx-plus-circle me-1'></i>{{ $stats['new_users_today'] }} joined today</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon green"><i class='bx bxs-dollar-circle'></i></div>
                    </div>
                    <div class="stat-value">${{ number_format($stats['total_revenue'], 2) }}</div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-sub">Completed payments</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon orange"><i class='bx bxs-lock-alt'></i></div>
                    </div>
                    <div class="stat-value">{{ number_format($stats['holds_holding']) }}</div>
                    <div class="stat-label">Active Vaults</div>
                    <div class="stat-sub" style="color:#c87000; font-weight:600;">
                        ${{ number_format($stats['total_holding_amount'], 2) }} locked
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon cream"><i class='bx bxs-lock-open-alt'></i></div>
                    </div>
                    <div class="stat-value">{{ number_format($stats['holds_ready']) }}</div>
                    <div class="stat-label">Ready to Withdraw</div>
                    <div class="stat-sub" style="color:#92621a; font-weight:600;">
                        ${{ number_format($stats['total_ready_amount'], 2) }} available
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
                    <div class="stat-value">${{ number_format($stats['total_transferred_amount'], 2) }}</div>
                    <div class="stat-label">Total Transferred</div>
                    <div class="stat-sub">{{ number_format($stats['holds_transferred']) }} completed holds</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon purple"><i class='bx bx-time-five'></i></div>
                    </div>
                    <div class="stat-value">{{ number_format($stats['pending_transfers']) }}</div>
                    <div class="stat-label">Pending Transfers</div>
                    <div class="stat-sub">Awaiting Stripe confirmation</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon blue"><i class='bx bxs-wallet'></i></div>
                    </div>
                    <div class="stat-value">{{ number_format($stats['holds_transferred']) }}</div>
                    <div class="stat-label">Transferred Holds</div>
                    <div class="stat-sub">Full &amp; partial transfers</div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card" style="border-color:rgba(189,126,46,0.3);">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="stat-icon gold"><i class='bx bxl-stripe'></i></div>
                    </div>
                    <div class="stat-value" style="color:var(--tv-gold);">
                        {{ number_format($stats['holds_holding'] + $stats['holds_ready']) }}
                    </div>
                    <div class="stat-label">Active Hold Records</div>
                    <div class="stat-sub">Locked + Ready</div>
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
                                            {{ $hold->hold_period_type ? str_replace('_', ' ', $hold->hold_period_type) : ($hold->hold_days ? $hold->hold_days.' days' : '—') }}
                                        </td>
                                        <td style="color:#374151; font-size:12px; font-weight:500;">
                                            {{ $hold->hold_end_at?->format('d M Y') ?? '—' }}
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
