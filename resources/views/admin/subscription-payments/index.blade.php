@extends('layouts.admin')

@section('title', 'Subscription Payments')

@php
    $money = fn (array $byCurrency) => $byCurrency === []
        ? '0.00'
        : collect($byCurrency)->map(fn ($amount, $currency) => $currency.' '.number_format($amount, 2))->implode(' · ');
@endphp

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Subscription Payments</h4>
                <p class="section-sub">Every collected subscription payment: first payments and automatic renewals</p>
            </div>
            <div class="ms-auto">
                <span id="list-refresh-alert" style="display:none; font-size:12px; color:#c87000; font-weight:600; cursor:pointer; background:rgba(255,152,0,0.1); border:1px solid rgba(255,152,0,0.3); border-radius:6px; padding:4px 10px;" onclick="window.location.reload()"></span>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon gold mb-2"><i class='bx bxs-receipt'></i></div>
                    <div class="stat-value" data-stat="total">{{ number_format($summary['total']) }}</div>
                    <div class="stat-label">Payments</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <a href="{{ route('admin.subscription-payments.index', ['type' => 'renewal']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon teal mb-2"><i class='bx bx-refresh'></i></div>
                        <div class="stat-value" data-stat="renewals">{{ number_format($summary['renewals']) }}</div>
                        <div class="stat-label">Auto Renewals</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon green mb-2"><i class='bx bxs-dollar-circle'></i></div>
                    <div class="stat-value" data-stat="this_month" style="font-size:18px;">{{ $money($summary['this_month']) }}</div>
                    <div class="stat-label">Collected This Month</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon gold mb-2"><i class='bx bx-line-chart'></i></div>
                    <div class="stat-value" data-stat="revenue" style="font-size:18px;">{{ $money($summary['revenue']) }}</div>
                    <div class="stat-label">Collected All Time</div>
                </div>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">Type:</label>
            <select name="type" class="form-select" style="max-width:180px;">
                <option value="">All</option>
                <option value="new" {{ request('type') === 'new' ? 'selected' : '' }}>New subscription</option>
                <option value="renewal" {{ request('type') === 'renewal' ? 'selected' : '' }}>Renewal</option>
            </select>
            <input type="text" name="search" value="{{ request('search') }}" class="form-control" style="max-width:260px;" placeholder="Full email">
            <button type="submit" class="btn-tv">Filter</button>
            @if(request('type') || request('search'))
                <a href="{{ route('admin.subscription-payments.index') }}" class="btn-tv-outline">Clear</a>
            @endif
        </form>

        @include('admin.partials.list-toolbar', ['exportRoute' => 'admin.subscription-payments.export'])

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th style="width:80px;">ID</th>
                                <th>User</th>
                                <th>Plan</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Invoice</th>
                                <th>Paid At</th>
                                <th>Paid Until</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($payments as $payment)
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">
                                    {{ ($payments->currentPage() - 1) * $payments->perPage() + $loop->iteration }}
                                </td>
                                <td style="color:#6b7280; font-size:12px; font-weight:600;">{{ str_pad($payment->id, 5, '0', STR_PAD_LEFT) }}</td>
                                <td>
                                    @if($payment->user)
                                        <a href="{{ route('admin.users.show', $payment->user_id) }}" style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">
                                            {{ $payment->user->displayName('Unknown') }}
                                        </a>
                                        <div style="color:#6b7280; font-size:12px;">{{ $payment->user->displayEmail() }}</div>
                                    @else
                                        <span style="color:#9ca3af;">Deleted user</span>
                                    @endif
                                </td>
                                <td style="color:#111827; font-size:13px; font-weight:600;">{{ $payment->subscription?->plan?->name ?? '—' }}</td>
                                <td>
                                    <span class="tv-badge {{ $payment->isRenewal() ? 'badge-ready' : 'badge-active' }}">{{ $payment->typeLabel() }}</span>
                                </td>
                                <td style="color:#1e8c3a; font-size:13px; font-weight:700;">
                                    {{ strtoupper($payment->currency) }} {{ number_format($payment->amount, 2) }}
                                </td>
                                <td style="font-size:12px;">
                                    @if($payment->hosted_invoice_url)
                                        <a href="{{ $payment->hosted_invoice_url }}" target="_blank" rel="noopener" style="color:#92621a; font-weight:600;">
                                            {{ $payment->invoice_number ?? 'View' }}
                                        </a>
                                    @else
                                        {{ $payment->invoice_number ?? '—' }}
                                    @endif
                                </td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $payment->paid_at->setTimezone(config('app.admin_timezone'))->format('d M Y H:i') }}
                                </td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $payment->period_end?->setTimezone(config('app.admin_timezone'))->format('d M Y') ?? '—' }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bxs-receipt d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No subscription payments yet
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($payments->hasPages())
            <div class="card-footer tv-pagination-footer">
                {{ $payments->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const STATS_URL = '{{ route("admin.subscription-payments.stats") }}';
    const initialTotal = {{ $summary['total'] }};

    function fmt(n) { return new Intl.NumberFormat('en-US').format(n); }
    function fmtMoney(byCurrency) {
        const entries = Object.entries(byCurrency || {});
        if (!entries.length) { return '0.00'; }
        return entries.map(([cur, amt]) => cur + ' ' + new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amt)).join(' · ');
    }
    function flash(el, val) {
        if (el && el.textContent.trim() !== val) {
            el.style.transition = 'opacity 0.3s';
            el.style.opacity = '0.3';
            setTimeout(() => { el.textContent = val; el.style.opacity = '1'; }, 300);
        }
    }

    function poll() {
        fetch(STATS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(data => {
                flash(document.querySelector('[data-stat="total"]'), fmt(data.total));
                flash(document.querySelector('[data-stat="renewals"]'), fmt(data.renewals));
                flash(document.querySelector('[data-stat="this_month"]'), fmtMoney(data.this_month));
                flash(document.querySelector('[data-stat="revenue"]'), fmtMoney(data.revenue));

                if (data.total !== initialTotal) {
                    const banner = document.getElementById('list-refresh-alert');
                    banner.style.display = 'inline-flex';
                    banner.innerHTML = "<i class='bx bx-refresh me-1'></i>New payment received — click to reload";
                }
            })
            .catch(err => console.warn('[Subscription payments] Poll failed:', err));
    }

    setTimeout(poll, 5000);
    setInterval(poll, 60 * 1000);
})();
</script>
@endpush
