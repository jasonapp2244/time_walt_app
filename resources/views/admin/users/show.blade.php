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

                <div class="card mb-3">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0" style="color:#111827; font-weight:700;">
                            <i class='bx bxs-bank me-2' style="color:var(--tv-gold);"></i>
                            Bank Accounts ({{ $bankAccounts->count() }})
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>Bank Name</th>
                                        <th>Account</th>
                                        <th>Type</th>
                                        <th>Country</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($bankAccounts as $bank)
                                    <tr>
                                        <td style="color:#1f2937; font-size:13px; font-weight:600;">{{ $bank->bank_name }}</td>
                                        <td style="color:#374151; font-size:13px; font-weight:600; font-family:monospace;">{{ $bank->masked_account_number }}</td>
                                        <td style="color:#374151; font-size:12px; font-weight:500;">{{ ucfirst($bank->account_type) }}</td>
                                        <td style="color:#374151; font-size:12px; font-weight:500;">{{ strtoupper($bank->country) }} ({{ strtoupper($bank->currency) }})</td>
                                        <td>
                                            @if($bank->is_primary)
                                                <span class="tv-badge badge-completed">Primary</span>
                                            @else
                                                <span class="tv-badge badge-holding" style="opacity:0.7;">Secondary</span>
                                            @endif
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm"
                                                style="background:rgba(189,126,46,0.1); color:#92621a; font-weight:600; font-size:11px; border:1px solid rgba(189,126,46,0.25); border-radius:6px; padding:4px 12px;"
                                                data-bs-toggle="modal" data-bs-target="#bankModal{{ $bank->id }}">
                                                <i class='bx bx-show me-1'></i>View
                                            </button>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-3" style="color:#6b7280;">No bank accounts</td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Bank Account Detail Modals --}}
                @foreach($bankAccounts as $bank)
                <div class="modal fade" id="bankModal{{ $bank->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="border:1px solid rgba(189,126,46,0.2); border-radius:12px; overflow:hidden;">
                            <div class="modal-header" style="background:rgba(189,126,46,0.06); border-bottom:1px solid rgba(189,126,46,0.12); padding:16px 20px;">
                                <h6 class="modal-title" style="font-weight:700; color:#111827;">
                                    <i class='bx bxs-bank me-2' style="color:#92621a;"></i>{{ $bank->bank_name }}
                                    @if($bank->is_primary)
                                        <span class="tv-badge badge-completed ms-2" style="font-size:10px;">Primary</span>
                                    @endif
                                </h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" style="padding:0;">
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Account Holder</span>
                                    <span style="font-size:13px; color:#111827; font-weight:600;">{{ $bank->account_holder_name }}</span>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Account Number</span>
                                    <span style="font-size:14px; color:#111827; font-weight:700; font-family:monospace; letter-spacing:1px;">{{ $bank->masked_account_number }}</span>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Bank Name</span>
                                    <span style="font-size:13px; color:#111827; font-weight:600;">{{ $bank->bank_name }}</span>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Account Type</span>
                                    <span style="font-size:13px; color:#111827; font-weight:600;">{{ ucfirst($bank->account_type) }}</span>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Country</span>
                                    <span style="font-size:13px; color:#111827; font-weight:600;">{{ strtoupper($bank->country) }}</span>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Currency</span>
                                    <span style="font-size:13px; color:#111827; font-weight:600;">{{ strtoupper($bank->currency) }}</span>
                                </div>
                                @if($bank->iban)
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">IBAN</span>
                                    <span style="font-size:13px; color:#111827; font-weight:600;">{{ $bank->iban }}</span>
                                </div>
                                @endif
                                @if($bank->swift_code)
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">SWIFT Code</span>
                                    <span style="font-size:13px; color:#111827; font-weight:600;">{{ $bank->swift_code }}</span>
                                </div>
                                @endif
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Status</span>
                                    @if($bank->is_primary)
                                        <span class="tv-badge badge-completed">Primary</span>
                                    @else
                                        <span class="tv-badge badge-holding">Secondary</span>
                                    @endif
                                </div>
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px;">
                                    <span style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Added On</span>
                                    <span style="font-size:13px; color:#111827; font-weight:600;">{{ $bank->created_at->format('d M Y H:i') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach

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
