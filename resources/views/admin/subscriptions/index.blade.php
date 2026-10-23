@extends('layouts.admin')

@section('title', 'Subscriptions')

@php
    $mrrText = fn (array $mrr) => $mrr === []
        ? '0.00'
        : collect($mrr)->map(fn ($amount, $currency) => $currency.' '.number_format($amount, 2))->implode(' · ');
@endphp

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Subscriptions</h4>
                <p class="section-sub">Users on a paid plan. Renewals are charged by Stripe automatically.</p>
            </div>
            <div class="ms-auto">
                <span id="subscriptions-refresh-alert" style="display:none; font-size:12px; color:#c87000; font-weight:600; cursor:pointer; background:rgba(255,152,0,0.1); border:1px solid rgba(255,152,0,0.3); border-radius:6px; padding:4px 10px;"></span>
            </div>
        </div>

        @if(session('success'))
            <div class="alert-success-tv mb-4"><i class='bx bx-check-circle me-2'></i>{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert-error-tv mb-4"><i class='bx bx-error-circle me-2'></i>{{ $errors->first() }}</div>
        @endif

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <a href="{{ route('admin.subscriptions.index', ['status' => 'active']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon green mb-2"><i class='bx bxs-crown'></i></div>
                        <div class="stat-value" data-stat="active">{{ number_format($summary['active']) }}</div>
                        <div class="stat-label">Active</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-xl-3">
                <a href="{{ route('admin.subscriptions.index', ['status' => 'past_due']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon orange mb-2"><i class='bx bxs-error'></i></div>
                        <div class="stat-value" data-stat="past_due">{{ number_format($summary['past_due']) }}</div>
                        <div class="stat-label">Renewal Failing</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-xl-3">
                <a href="{{ route('admin.subscriptions.index', ['status' => 'canceling']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon cream mb-2"><i class='bx bxs-time-five'></i></div>
                        <div class="stat-value" data-stat="canceling">{{ number_format($summary['canceling']) }}</div>
                        <div class="stat-label">Ending at Period End</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon gold mb-2"><i class='bx bxs-dollar-circle'></i></div>
                    <div class="stat-value" data-stat="mrr" style="font-size:18px;">{{ $mrrText($summary['mrr']) }}</div>
                    <div class="stat-label">Monthly Recurring Revenue</div>
                </div>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">Status:</label>
            <select name="status" class="form-select" style="max-width:190px;">
                <option value="">All</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="past_due" {{ request('status') === 'past_due' ? 'selected' : '' }}>Renewal failing</option>
                <option value="canceling" {{ request('status') === 'canceling' ? 'selected' : '' }}>Ending at period end</option>
                <option value="canceled" {{ request('status') === 'canceled' ? 'selected' : '' }}>Ended</option>
                <option value="incomplete" {{ request('status') === 'incomplete' ? 'selected' : '' }}>Awaiting payment</option>
            </select>
            <input type="text" name="search" value="{{ request('search') }}" class="form-control" style="max-width:260px;" placeholder="Full email">
            <button type="submit" class="btn-tv">Filter</button>
            @if(request('status') || request('search'))
                <a href="{{ route('admin.subscriptions.index') }}" class="btn-tv-outline">Clear</a>
            @endif
        </form>

        @include('admin.partials.list-toolbar', ['exportRoute' => 'admin.subscriptions.export'])

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
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Renews / Ends</th>
                                <th style="width:230px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($subscriptions as $subscription)
                            @php
                                $entitled = $subscription->isEntitled();
                                [$badge, $label] = match (true) {
                                    $entitled && $subscription->cancel_at_period_end => ['badge-pending', 'Ending'],
                                    $subscription->status === 'past_due' && $entitled => ['badge-partial', 'Renewal failing'],
                                    $entitled => ['badge-active', 'Active'],
                                    $subscription->status === 'incomplete' => ['badge-pending', 'Awaiting payment'],
                                    default => ['badge-inactive', 'Ended'],
                                };
                            @endphp
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">
                                    {{ ($subscriptions->currentPage() - 1) * $subscriptions->perPage() + $loop->iteration }}
                                </td>
                                <td style="color:#6b7280; font-size:12px; font-weight:600;">{{ str_pad($subscription->id, 5, '0', STR_PAD_LEFT) }}</td>
                                <td>
                                    @if($subscription->user)
                                        <a href="{{ route('admin.users.show', $subscription->user_id) }}" style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">
                                            {{ $subscription->user->displayName('Unknown') }}
                                        </a>
                                        <div style="color:#6b7280; font-size:12px;">{{ $subscription->user->displayEmail() }}</div>
                                    @else
                                        <span style="color:#9ca3af;">Deleted user</span>
                                    @endif
                                </td>
                                <td style="color:#111827; font-size:13px; font-weight:600;">{{ $subscription->plan?->name ?? '—' }}</td>
                                <td style="color:#374151; font-size:13px;">
                                    @if($subscription->plan)
                                        {{ strtoupper($subscription->plan->currency) }} {{ number_format($subscription->plan->price, 2) }} / {{ $subscription->plan->interval }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td><span class="tv-badge {{ $badge }}">{{ $label }}</span></td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $subscription->current_period_end?->setTimezone(config('app.admin_timezone'))->format('d M Y H:i') ?? '—' }}
                                </td>
                                <td>
                                    @if($entitled)
                                        <div class="d-flex gap-2 flex-wrap">
                                            @unless($subscription->cancel_at_period_end)
                                                <form method="POST" action="{{ route('admin.subscriptions.cancel', $subscription) }}"
                                                      onsubmit="return confirm('Stop renewing? The user keeps access until the end of the paid period.');">
                                                    @csrf
                                                    <button type="submit" class="btn-tv-outline" style="padding:4px 10px; font-size:12px;">Cancel at period end</button>
                                                </form>
                                            @endunless
                                            <form method="POST" action="{{ route('admin.subscriptions.cancel-now', $subscription) }}"
                                                  onsubmit="return confirm('End this subscription now? The user loses access immediately and is not refunded automatically.');">
                                                @csrf
                                                <button type="submit" class="btn-tv-outline" style="padding:4px 10px; font-size:12px; color:#c82333; border-color:rgba(200,35,51,0.35);">Cancel now</button>
                                            </form>
                                        </div>
                                    @else
                                        <span style="color:#9ca3af; font-size:12px;">—</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bxs-crown d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No subscriptions found
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($subscriptions->hasPages())
            <div class="card-footer tv-pagination-footer">
                {{ $subscriptions->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const POLL_INTERVAL = 60 * 1000;
    const STATS_URL = '{{ route("admin.subscriptions.stats") }}';
    const initialTotal = {{ $summary['total'] }};
    let reloading = false;

    function fmt(n) { return new Intl.NumberFormat('en-US').format(n); }
    function fmtMrr(mrr) {
        const entries = Object.entries(mrr || {});
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

    function pollStats() {
        fetch(STATS_URL, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(data => {
            flash(document.querySelector('[data-stat="active"]'), fmt(data.active));
            flash(document.querySelector('[data-stat="past_due"]'), fmt(data.past_due));
            flash(document.querySelector('[data-stat="canceling"]'), fmt(data.canceling));
            flash(document.querySelector('[data-stat="mrr"]'), fmtMrr(data.mrr));

            if (data.total !== initialTotal && !reloading) {
                reloading = true;
                const banner = document.getElementById('subscriptions-refresh-alert');
                if (banner) {
                    banner.style.display = 'inline-flex';
                    banner.innerHTML = "<i class='bx bx-refresh me-1'></i>New subscription — click to reload";
                    banner.onclick = () => window.location.reload();
                }
            }
        })
        .catch(err => console.warn('[Subscriptions] Poll failed:', err));
    }

    setTimeout(pollStats, 5000);
    setInterval(pollStats, POLL_INTERVAL);
})();
</script>
@endpush
