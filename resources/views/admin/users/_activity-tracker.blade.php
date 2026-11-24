{{--
    Activity tracker: the user's deposits, card payments, withdrawals and
    refunds counted by status, plus the Stripe account state. A deposit or
    withdrawal count filters the table further down; a refund count opens the
    Refunds page for this user.
--}}
@php
    $badgeFor = [
        'holding' => 'holding', 'ready_for_transfer' => 'ready', 'partial_transferred' => 'partial',
        'transferred' => 'transferred', 'refunded' => 'refunded', 'canceled' => 'canceled',
        'succeeded' => 'succeeded', 'pending' => 'pending', 'processing' => 'pending',
        'failed' => 'failed', 'completed' => 'completed',
    ];

    $sections = [
        [
            'key' => 'holds', 'title' => 'Deposits (holds)', 'icon' => 'bxs-lock-alt',
            'link' => fn ($status) => route('admin.users.show', ['user' => $user->id, 'hold_status' => $status]).'#holds',
            'active' => $holdStatus,
        ],
        [
            'key' => 'payments', 'title' => 'Card payments', 'icon' => 'bx-credit-card',
            'link' => null, 'active' => null,
        ],
        [
            'key' => 'transfers', 'title' => 'Withdrawals (transfers)', 'icon' => 'bxs-send',
            'link' => fn ($status) => route('admin.users.show', ['user' => $user->id, 'transfer_status' => $status]).'#transfers',
            'active' => $transferStatus,
        ],
        [
            'key' => 'refunds', 'title' => 'Refunds', 'icon' => 'bx-undo',
            'link' => fn ($status) => route('admin.refunds.index', ['user_id' => $user->id, 'status' => $status]),
            'active' => null,
        ],
    ];

    $stripe = $activity['stripe'];
    $problems = array_filter([
        'failed card payment(s)' => $activity['payments']['statuses']['failed']['count'],
        'failed withdrawal(s)' => $activity['transfers']['statuses']['failed']['count'],
        'failed refund(s)' => $activity['refunds']['statuses']['failed']['count'] + $activity['refunds']['statuses']['canceled']['count'],
        'refund(s) need attention' => $activity['refunds']['attention'],
        'abandoned deposit(s)' => $activity['holds']['abandoned'],
    ]);
@endphp

<div class="card mb-4" id="activity-tracker">
    <div class="card-header py-3 px-4 d-flex align-items-center flex-wrap gap-2">
        <h6 class="mb-0" style="color:#111827; font-weight:700;">
            <i class='bx bx-list-check me-2' style="color:var(--tv-gold);"></i>Activity tracker
        </h6>
        <span class="ms-auto" style="font-size:12px; color:#6b7280;">Counts by Stripe status · click a count to see those records</span>
    </div>
    <div class="card-body p-3">

        @if($problems)
            <div class="alert-error-tv mb-3" style="font-size:13px;">
                <i class='bx bx-error-circle me-1'></i><strong>Check:</strong>
                @foreach($problems as $label => $count)
                    <span class="ms-2">{{ $count }} {{ $label }}</span>@if(! $loop->last) · @endif
                @endforeach
            </div>
        @endif

        <div class="row g-3">
            @foreach($sections as $section)
                @php $data = $activity[$section['key']]; @endphp
                <div class="col-12 col-md-6 col-xl">
                    <div class="tv-tracker-box h-100">
                        <div class="d-flex align-items-center mb-2">
                            <i class='bx {{ $section['icon'] }} me-2' style="color:#92621a; font-size:18px;"></i>
                            <span class="tv-tracker-title">{{ $section['title'] }}</span>
                            <span class="ms-auto tv-tracker-total" data-tracker-total="{{ $section['key'] }}">{{ $data['total'] }}</span>
                        </div>

                        @foreach($data['statuses'] as $status => $row)
                            @php
                                $isActive = $section['active'] === $status;
                                $clickable = $section['link'] && $row['count'] > 0;
                            @endphp
                            @if($clickable)
                                <a href="{{ ($section['link'])($status) }}" class="tv-tracker-row {{ $isActive ? 'is-active' : '' }}" title="Show {{ strtolower($row['label']) }} records">
                            @else
                                <div class="tv-tracker-row {{ $row['count'] === 0 ? 'is-empty' : '' }}">
                            @endif
                                <span class="tv-badge badge-{{ $badgeFor[$status] ?? 'pending' }}" style="font-size:10px;">{{ $row['label'] }}</span>
                                <span class="ms-auto tv-tracker-amount">${{ number_format($row['amount'], 2) }}</span>
                                <span class="tv-tracker-count">{{ $row['count'] }}</span>
                            @if($clickable)
                                </a>
                            @else
                                </div>
                            @endif
                        @endforeach

                        @if($section['key'] === 'transfers' && $data['reversed']['count'] > 0)
                            <div class="tv-tracker-note">{{ $data['reversed']['count'] }} pulled back for refunds (${{ number_format($data['reversed']['amount'], 2) }})</div>
                        @endif
                        @if($section['key'] === 'holds' && $data['abandoned'] > 0)
                            <div class="tv-tracker-note">{{ $data['abandoned'] }} abandoned</div>
                        @endif
                        @if($section['key'] === 'refunds' && $data['attention'] > 0)
                            <a href="{{ route('admin.refunds.index', ['user_id' => $user->id, 'attention' => 1]) }}" class="tv-tracker-note" style="color:#c82333; font-weight:700;">{{ $data['attention'] }} need attention</a>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- Stripe account --}}
            <div class="col-12 col-md-6 col-xl">
                <div class="tv-tracker-box h-100">
                    <div class="d-flex align-items-center mb-2">
                        <i class='bx bxl-stripe me-2' style="color:#92621a; font-size:18px;"></i>
                        <span class="tv-tracker-title">Stripe account</span>
                    </div>
                    @if(! $stripe['connected'])
                        <div class="tv-tracker-row">
                            <span class="tv-badge badge-inactive" style="font-size:10px;">Not connected</span>
                        </div>
                        <div class="tv-tracker-note">No payout account yet, so the user cannot withdraw.</div>
                    @else
                        @php
                            [$stripeLabel, $stripeBadge] = match ($stripe['status']) {
                                'verified' => ['Verified', 'completed'],
                                'restricted' => ['Restricted (blocked by Stripe)', 'failed'],
                                default => ['Pending verification', 'pending'],
                            };
                        @endphp
                        <div class="tv-tracker-row">
                            <span style="font-size:12px; color:#374151; font-weight:600;">Status</span>
                            <span class="ms-auto tv-badge badge-{{ $stripeBadge }}" style="font-size:10px;">{{ $stripeLabel }}</span>
                        </div>
                        <div class="tv-tracker-row">
                            <span style="font-size:12px; color:#374151; font-weight:600;">Payouts</span>
                            <span class="ms-auto tv-badge {{ $stripe['payouts_enabled'] ? 'badge-completed' : 'badge-failed' }}" style="font-size:10px;">{{ $stripe['payouts_enabled'] ? 'Enabled' : 'Blocked' }}</span>
                        </div>
                        @if($stripe['verified_at'])
                            <div class="tv-tracker-note">Verified {{ $stripe['verified_at']->setTimezone(config('app.admin_timezone'))->format('d M Y') }}</div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .tv-tracker-box { border:1px solid rgba(189,126,46,0.16); border-radius:10px; padding:12px 14px; background:#fffdf8; }
    .tv-tracker-title { font-size:12px; font-weight:700; color:#111827; text-transform:uppercase; letter-spacing:0.5px; }
    .tv-tracker-total { font-size:18px; font-weight:800; color:#92621a; }
    .tv-tracker-row { display:flex; align-items:center; gap:8px; padding:5px 6px; border-radius:6px; text-decoration:none; color:inherit; }
    a.tv-tracker-row:hover { background:rgba(189,126,46,0.08); }
    .tv-tracker-row.is-active { background:rgba(189,126,46,0.16); outline:1px solid rgba(189,126,46,0.4); }
    .tv-tracker-row.is-empty { opacity:0.45; }
    .tv-tracker-amount { font-size:11px; color:#6b7280; font-weight:500; }
    .tv-tracker-count { min-width:26px; text-align:right; font-size:14px; font-weight:700; color:#111827; }
    .tv-tracker-note { display:block; font-size:11px; color:#6b7280; margin-top:6px; padding-left:6px; text-decoration:none; }
</style>
