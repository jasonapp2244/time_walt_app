@extends('layouts.admin')

@section('title', 'Payment Holds')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Payment Holds</h4>
                <p class="section-sub">All vaulted funds and their status</p>
            </div>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span id="holds-refresh-alert" style="display:none; font-size:12px; color:#c87000; font-weight:600; cursor:pointer; background:rgba(255,152,0,0.1); border:1px solid rgba(255,152,0,0.3); border-radius:6px; padding:4px 10px;" onclick="window.location.reload()">
                    <i class='bx bx-refresh me-1'></i>New updates — click to reload
                </span>
                <span style="font-size:13px; color:#92621a; font-weight:700;" data-stat="total_records">{{ $holds->total() }} records</span>
            </div>
        </div>

        {{-- Amount Summary Banner --}}
        <div class="row g-3 mb-3">
            <div class="col-4">
                <div style="background:#ffffff; border:1px solid rgba(255,152,0,0.22); border-radius:10px; padding:14px 18px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
                    <div style="width:40px; height:40px; border-radius:10px; background:rgba(255,152,0,0.12); display:flex; align-items:center; justify-content:center; font-size:18px; color:#c87000; flex-shrink:0;">
                        <i class='bx bxs-lock-alt'></i>
                    </div>
                    <div>
                        <div style="font-size:17px; font-weight:700; color:#c87000;" data-stat="amount_holding">${{ number_format($amountTotals['holding'], 2) }}</div>
                        <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.6px;">Total Holding</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div style="background:#ffffff; border:1px solid rgba(189,126,46,0.22); border-radius:10px; padding:14px 18px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
                    <div style="width:40px; height:40px; border-radius:10px; background:rgba(189,126,46,0.12); display:flex; align-items:center; justify-content:center; font-size:18px; color:#92621a; flex-shrink:0;">
                        <i class='bx bxs-lock-open-alt'></i>
                    </div>
                    <div>
                        <div style="font-size:17px; font-weight:700; color:#92621a;" data-stat="amount_ready">${{ number_format($amountTotals['ready'], 2) }}</div>
                        <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.6px;">Total Ready</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div style="background:#ffffff; border:1px solid rgba(40,167,69,0.22); border-radius:10px; padding:14px 18px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
                    <div style="width:40px; height:40px; border-radius:10px; background:rgba(40,167,69,0.12); display:flex; align-items:center; justify-content:center; font-size:18px; color:#1e8c3a; flex-shrink:0;">
                        <i class='bx bxs-send'></i>
                    </div>
                    <div>
                        <div style="font-size:17px; font-weight:700; color:#1e8c3a;" data-stat="amount_withdrawn">${{ number_format($amountTotals['withdrawn'], 2) }}</div>
                        <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.6px;">Total Withdrawn</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Status Summary Cards --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <a href="{{ route('admin.payment-holds.index', ['status' => 'holding']) }}" style="text-decoration:none;">
                    <div class="stat-card" style="{{ request('status') == 'holding' ? 'border-color:rgba(255,152,0,0.45);' : '' }}">
                        <div class="stat-icon orange mb-2"><i class='bx bxs-lock-alt'></i></div>
                        <div class="stat-value" data-stat="count_holding">{{ $statusCounts['holding'] }}</div>
                        <div class="stat-label">Holding</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="{{ route('admin.payment-holds.index', ['status' => 'ready_for_transfer']) }}" style="text-decoration:none;">
                    <div class="stat-card" style="{{ request('status') == 'ready_for_transfer' ? 'border-color:rgba(189,126,46,0.45);' : '' }}">
                        <div class="stat-icon cream mb-2"><i class='bx bxs-lock-open-alt'></i></div>
                        <div class="stat-value" data-stat="count_ready">{{ $statusCounts['ready_for_transfer'] }}</div>
                        <div class="stat-label">Ready</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="{{ route('admin.payment-holds.index', ['status' => 'transferred']) }}" style="text-decoration:none;">
                    <div class="stat-card" style="{{ request('status') == 'transferred' ? 'border-color:rgba(40,167,69,0.45);' : '' }}">
                        <div class="stat-icon green mb-2"><i class='bx bxs-check-circle'></i></div>
                        <div class="stat-value" data-stat="count_transferred">{{ $statusCounts['transferred'] }}</div>
                        <div class="stat-label">Transferred</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="{{ route('admin.payment-holds.index', ['status' => 'partial_transferred']) }}" style="text-decoration:none;">
                    <div class="stat-card" style="{{ request('status') == 'partial_transferred' ? 'border-color:rgba(13,202,240,0.45);' : '' }}">
                        <div class="stat-icon blue mb-2"><i class='bx bx-transfer'></i></div>
                        <div class="stat-value" data-stat="count_partial">{{ $statusCounts['partial_transferred'] }}</div>
                        <div class="stat-label">Partial</div>
                    </div>
                </a>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">Status:</label>
            <select name="status" class="form-select" style="max-width:200px;">
                <option value="">All</option>
                <option value="holding" {{ request('status') == 'holding' ? 'selected' : '' }}>Holding</option>
                <option value="ready_for_transfer" {{ request('status') == 'ready_for_transfer' ? 'selected' : '' }}>Ready for Transfer</option>
                <option value="transferred" {{ request('status') == 'transferred' ? 'selected' : '' }}>Transferred</option>
                <option value="partial_transferred" {{ request('status') == 'partial_transferred' ? 'selected' : '' }}>Partial Transferred</option>
            </select>
            <button type="submit" class="btn-tv">Filter</button>
            @if(request('status'))
                <a href="{{ route('admin.payment-holds.index') }}" class="btn-tv-outline">Clear</a>
            @endif
        </form>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Hold ID</th>
                                <th>User</th>
                                <th>Title</th>
                                <th>Amount</th>
                                <th>Remaining</th>
                                <th>Period</th>
                                <th>Lock Date</th>
                                <th>Unlock Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($holds as $hold)
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">
                                    {{ ($holds->currentPage() - 1) * $holds->perPage() + $loop->iteration }}
                                </td>
                                <td style="color:#2563eb; font-size:12px; font-weight:700;">
                                    {{ str_pad($hold->id, 5, '0', STR_PAD_LEFT) }}
                                </td>
                                <td>
                                    <a href="{{ route('admin.users.show', $hold->user_id) }}"
                                       style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">
                                        {{ $hold->user?->full_name ?? 'Unknown' }}
                                    </a>
                                </td>
                                <td style="color:#1f2937; font-size:13px; font-weight:500;">{{ $hold->title ?? '—' }}</td>
                                <td style="color:#92621a; font-weight:700;">
                                    ${{ number_format($hold->amount, 2) }}
                                </td>
                                <td style="color:#374151; font-size:13px; font-weight:600;">
                                    ${{ number_format($hold->remaining_amount ?? $hold->amount, 2) }}
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
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $hold->hold_start_at?->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A') ?? '—' }}
                                </td>
                                <td style="font-size:12px; font-weight:500;">
                                    @if($hold->hold_end_at)
                                        @if($hold->hold_end_at->isPast())
                                            <span style="color:#1e8c3a; font-weight:600;">{{ $hold->hold_end_at->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A') }}</span>
                                        @else
                                            <span style="color:#374151;">{{ $hold->hold_end_at->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A') }}</span>
                                        @endif
                                    @else
                                        <span style="color:#6b7280;">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="tv-badge badge-{{ str_replace('_','-',$hold->status) }}">
                                        {{ ucwords(str_replace('_', ' ', $hold->status)) }}
                                    </span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="10" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bx-lock d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No payment holds found
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($holds->hasPages())
            <div class="card-footer tv-pagination-footer">
                {{ $holds->appends(request()->query())->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const POLL_INTERVAL = 1 * 60 * 1000; // 1 minute — testing (change back to 5 * 60 * 1000 in production)
    const STATS_URL = '{{ route("admin.payment-holds.stats") }}';

    function fmtMoney(n) {
        return '$' + new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
    }

    function fmt(n) {
        return new Intl.NumberFormat('en-US').format(n);
    }

    function flash(el, newVal) {
        if (el && el.textContent.trim() !== newVal) {
            el.style.transition = 'opacity 0.3s';
            el.style.opacity = '0.3';
            setTimeout(() => { el.textContent = newVal; el.style.opacity = '1'; }, 300);
        }
    }

    const initialCounts = {
        holding: {{ $statusCounts['holding'] }},
        ready:   {{ $statusCounts['ready_for_transfer'] }},
        total:   {{ array_sum($statusCounts) }},
    };
    let reloading = false;

    function autoReload(bannerId, message) {
        if (reloading) { return; }
        reloading = true;

        const banner = document.getElementById(bannerId);
        let secs = 5;

        if (banner) {
            banner.style.display = 'inline-flex';
            banner.onclick = () => window.location.reload();
            banner.innerHTML = `<i class='bx bx-refresh me-1'></i>${message} — reloading in <span id="tv-cd">${secs}</span>s`;
        }

        const timer = setInterval(() => {
            secs--;
            const cd = document.getElementById('tv-cd');
            if (cd) { cd.textContent = secs; }
            if (secs <= 0) { clearInterval(timer); window.location.reload(); }
        }, 1000);
    }

    function pollStats() {
        fetch(STATS_URL, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(data => {
            // Update amount banners and count cards in-place
            flash(document.querySelector('[data-stat="amount_holding"]'),    fmtMoney(data.amount_totals.holding));
            flash(document.querySelector('[data-stat="amount_ready"]'),      fmtMoney(data.amount_totals.ready));
            flash(document.querySelector('[data-stat="amount_withdrawn"]'),  fmtMoney(data.amount_totals.withdrawn));
            flash(document.querySelector('[data-stat="count_holding"]'),     fmt(data.status_counts.holding));
            flash(document.querySelector('[data-stat="count_ready"]'),       fmt(data.status_counts.ready_for_transfer));
            flash(document.querySelector('[data-stat="count_transferred"]'), fmt(data.status_counts.transferred));
            flash(document.querySelector('[data-stat="count_partial"]'),     fmt(data.status_counts.partial_transferred));
            flash(document.querySelector('[data-stat="total_records"]'),     fmt(data.total_records) + ' records');

            // Auto-reload table when any record status changed
            const changed = data.status_counts.holding            !== initialCounts.holding
                         || data.status_counts.ready_for_transfer !== initialCounts.ready
                         || data.total_records                    !== initialCounts.total;

            if (changed) {
                autoReload('holds-refresh-alert', 'Hold statuses updated');
            }
        })
        .catch(err => console.warn('[PaymentHolds] Stats poll failed:', err));
    }

    setTimeout(pollStats, 5000);           // first check after 5 seconds
    setInterval(pollStats, POLL_INTERVAL); // then every minute
})();
</script>
@endpush
