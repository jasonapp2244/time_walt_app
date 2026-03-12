@extends('layouts.admin')

@section('title', 'User Detail')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <a href="{{ route('admin.users.index') }}" class="btn-tv-outline me-3">
                <i class='bx bx-arrow-back me-1'></i>Back
            </a>
            <div>
                <h4 class="section-heading">{{ $user->full_name ?? 'User Detail' }}</h4>
                <p class="section-sub">User ID #{{ $user->id }}</p>
            </div>
        </div>

        {{-- User Amount Summary --}}
        <div class="row g-3 mb-4">
            <div class="col-4">
                <div style="background:#ffffff; border:1px solid rgba(255,152,0,0.25); border-radius:10px; padding:14px 18px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
                    <div style="width:40px; height:40px; border-radius:10px; background:rgba(255,152,0,0.12); display:flex; align-items:center; justify-content:center; font-size:18px; color:#c87000; flex-shrink:0;">
                        <i class='bx bxs-lock-alt'></i>
                    </div>
                    <div>
                        <div style="font-size:17px; font-weight:700; color:#c87000;">${{ number_format($userAmounts['total_held'], 2) }}</div>
                        <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.6px;">Total Hold</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div style="background:#ffffff; border:1px solid rgba(189,126,46,0.25); border-radius:10px; padding:14px 18px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
                    <div style="width:40px; height:40px; border-radius:10px; background:rgba(189,126,46,0.12); display:flex; align-items:center; justify-content:center; font-size:18px; color:#92621a; flex-shrink:0;">
                        <i class='bx bxs-lock-open-alt'></i>
                    </div>
                    <div>
                        <div style="font-size:17px; font-weight:700; color:#92621a;">${{ number_format($userAmounts['total_ready'], 2) }}</div>
                        <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.6px;">Total Ready</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div style="background:#ffffff; border:1px solid rgba(40,167,69,0.25); border-radius:10px; padding:14px 18px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
                    <div style="width:40px; height:40px; border-radius:10px; background:rgba(40,167,69,0.12); display:flex; align-items:center; justify-content:center; font-size:18px; color:#1e8c3a; flex-shrink:0;">
                        <i class='bx bxs-send'></i>
                    </div>
                    <div>
                        <div style="font-size:17px; font-weight:700; color:#1e8c3a;">${{ number_format($userAmounts['total_withdrawn'], 2) }}</div>
                        <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.6px;">Total Withdrawn</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">

            {{-- Profile Card --}}
            <div class="col-12 col-lg-4">
                <div style="background:#ffffff; border:1px solid rgba(189,126,46,0.14); border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,0.04);">

                    <div style="padding:24px 20px 18px; text-align:center; border-bottom:1px solid rgba(189,126,46,0.1);">
                        <div style="width:68px; height:68px; border-radius:50%; background:rgba(189,126,46,0.12);
                                    border:2px solid rgba(189,126,46,0.35); display:flex; align-items:center;
                                    justify-content:center; font-size:28px; font-weight:700; color:#92621a;
                                    margin:0 auto 12px;">
                            {{ strtoupper(substr($user->full_name ?? 'U', 0, 1)) }}
                        </div>
                        <div style="font-size:16px; font-weight:700; color:#111827; margin-bottom:3px;">
                            {{ $user->full_name ?? '—' }}
                        </div>
                        <div style="font-size:12px; color:#6b7280; font-weight:500; margin-bottom:10px;">
                            {{ $user->email ?? '—' }}
                        </div>
                        <span class="tv-badge badge-{{ $user->status ?? 'active' }}">
                            {{ ucfirst($user->status ?? 'active') }}
                        </span>
                    </div>

                    <div style="padding:4px 0 8px;">

                        <div style="display:flex; align-items:center; justify-content:space-between;
                                    padding:9px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                            <span style="font-size:11px; color:#374151; font-weight:700;
                                         text-transform:uppercase; letter-spacing:0.5px;">Phone</span>
                            <span style="font-size:13px; color:#111827; font-weight:600;">
                                {{ $user->phone ?? '—' }}
                            </span>
                        </div>

                        <div style="display:flex; align-items:center; justify-content:space-between;
                                    padding:9px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                            <span style="font-size:11px; color:#374151; font-weight:700;
                                         text-transform:uppercase; letter-spacing:0.5px;">Provider</span>
                            <span style="font-size:13px; color:#111827; font-weight:600;">
                                {{ ucfirst($user->provider ?? 'email') }}
                            </span>
                        </div>

                        <div style="display:flex; align-items:center; justify-content:space-between;
                                    padding:9px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                            <span style="font-size:11px; color:#374151; font-weight:700;
                                         text-transform:uppercase; letter-spacing:0.5px;">Joined</span>
                            <span style="font-size:13px; color:#111827; font-weight:600;">
                                {{ $user->created_at->format('d M Y') }}
                            </span>
                        </div>

                        <div style="display:flex; align-items:center; justify-content:space-between;
                                    padding:9px 20px;">
                            <span style="font-size:11px; color:#374151; font-weight:700;
                                         text-transform:uppercase; letter-spacing:0.5px;">Verified</span>
                            @if($user->is_verified)
                                <span class="tv-badge badge-completed">Yes</span>
                            @else
                                <span class="tv-badge badge-failed">No</span>
                            @endif
                        </div>

                    </div>
                </div>
            </div>

            {{-- Activity --}}
            <div class="col-12 col-lg-8">

                <div class="card mb-3">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0" style="color:#111827; font-weight:700;">
                            <i class='bx bxs-lock-alt me-2' style="color:var(--tv-gold);"></i>
                            Payment Holds ({{ $user->paymentHolds->count() }})
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Amount</th>
                                        <th>Hold Period</th>
                                        <th>Unlock Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($user->paymentHolds as $hold)
                                    <tr>
                                        <td style="color:#1f2937; font-size:13px; font-weight:500;">{{ $hold->title ?? '—' }}</td>
                                        <td style="color:#92621a; font-weight:700;">
                                            ${{ number_format($hold->amount, 2) }}
                                        </td>
                                        <td style="color:#374151; font-size:12px; font-weight:500;">
                                            {{ $hold->hold_period_type ? str_replace('_', ' ', $hold->hold_period_type) : ($hold->hold_days ? $hold->hold_days.' days' : '—') }}
                                        </td>
                                        <td style="color:#4b5563; font-size:12px; font-weight:500;">
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
                                        <td colspan="5" class="text-center py-3" style="color:#6b7280;">No holds</td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0" style="color:#111827; font-weight:700;">
                            <i class='bx bxs-send me-2' style="color:var(--tv-gold);"></i>
                            Transfers ({{ $user->transfers->count() }})
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>Amount</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($user->transfers as $transfer)
                                    <tr>
                                        <td style="color:#1e8c3a; font-weight:700;">
                                            ${{ number_format($transfer->amount, 2) }}
                                        </td>
                                        <td style="color:#374151; font-size:12px; font-weight:500;">
                                            {{ str_replace('_', ' ', ucfirst($transfer->transfer_type ?? '—')) }}
                                        </td>
                                        <td>
                                            <span class="tv-badge badge-{{ $transfer->status }}">
                                                {{ ucfirst($transfer->status) }}
                                            </span>
                                        </td>
                                        <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                            {{ $transfer->transferred_at?->format('d M Y H:i') ?? $transfer->created_at->format('d M Y') }}
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="4" class="text-center py-3" style="color:#6b7280;">No transfers</td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>
@endsection
