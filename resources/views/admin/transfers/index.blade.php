@extends('layouts.admin')

@section('title', 'Transfers')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Transfers</h4>
                <p class="section-sub">All Stripe transfers to user accounts</p>
            </div>
            <div class="ms-auto">
                <div style="text-align:right;">
                    <div style="font-size:20px; font-weight:700; color:#1e8c3a;">${{ number_format($totalTransferred, 2) }}</div>
                    <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.8px;">Total Transferred</div>
                </div>
            </div>
        </div>

        @if(session('success'))
            <div class="alert-success-tv mb-3"><i class='bx bx-check-circle me-2'></i>{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert-error-tv mb-3"><i class='bx bx-error-circle me-2'></i>{{ session('error') }}</div>
        @endif

        {{-- Status Summary --}}
        <div class="row g-3 mb-4">
            <div class="col-4">
                <a href="{{ route('admin.transfers.index', ['status' => 'completed']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon green mb-2"><i class='bx bxs-check-circle'></i></div>
                        <div class="stat-value">{{ $statusCounts['completed'] }}</div>
                        <div class="stat-label">Completed</div>
                    </div>
                </a>
            </div>
            <div class="col-4">
                <a href="{{ route('admin.transfers.index', ['status' => 'pending']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon orange mb-2"><i class='bx bx-time'></i></div>
                        <div class="stat-value">{{ $statusCounts['pending'] }}</div>
                        <div class="stat-label">Pending</div>
                    </div>
                </a>
            </div>
            <div class="col-4">
                <a href="{{ route('admin.transfers.index', ['status' => 'failed']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon red mb-2"><i class='bx bx-x-circle'></i></div>
                        <div class="stat-value">{{ $statusCounts['failed'] }}</div>
                        <div class="stat-label">Failed</div>
                    </div>
                </a>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">Status:</label>
            <select name="status" class="form-select" style="max-width:160px;">
                <option value="">All</option>
                <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>Failed</option>
            </select>
            <button type="submit" class="btn-tv">Filter</button>
            @if(request('status'))
                <a href="{{ route('admin.transfers.index') }}" class="btn-tv-outline">Clear</a>
            @endif
        </form>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>User</th>
                                <th>Amount</th>
                                <th>Currency</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Failure Reason</th>
                                <th>Date</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($transfers as $transfer)
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">
                                    {{ ($transfers->currentPage() - 1) * $transfers->perPage() + $loop->iteration }}
                                </td>
                                <td>
                                    <a href="{{ route('admin.users.show', $transfer->user_id) }}"
                                       style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">
                                        {{ $transfer->user?->full_name ?? 'Unknown' }}
                                    </a>
                                </td>
                                <td style="color:#1e8c3a; font-weight:700; font-size:15px;">
                                    ${{ number_format($transfer->amount, 2) }}
                                </td>
                                <td style="color:#374151; font-size:12px; font-weight:600;">
                                    {{ strtoupper($transfer->currency ?? 'USD') }}
                                </td>
                                <td style="color:#374151; font-size:12px; font-weight:500;">
                                    {{ str_replace('_', ' ', ucfirst($transfer->transfer_type ?? '—')) }}
                                </td>
                                <td>
                                    <span class="tv-badge badge-{{ $transfer->status }}">
                                        {{ ucfirst($transfer->status) }}
                                    </span>
                                </td>
                                <td style="color:#c82333; font-size:12px; font-weight:500; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    {{ $transfer->failure_reason ?? '—' }}
                                </td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $transfer->transferred_at?->format('d M Y H:i') ?? $transfer->created_at->format('d M Y') }}
                                </td>
                                <td style="text-align:center;">
                                    @if($transfer->hold && in_array($transfer->hold->status, ['ready_for_transfer', 'partial_transferred']))
                                        <form method="POST" action="{{ route('admin.transfers.execute', $transfer->hold_id) }}"
                                              onsubmit="return confirm('Execute manual transfer for this hold?')">
                                            @csrf
                                            <button type="submit" class="btn-tv-outline" style="font-size:11px; padding:3px 9px;">
                                                <i class='bx bxs-send me-1'></i>Transfer
                                            </button>
                                        </form>
                                    @else
                                        <span style="color:#9ca3af; font-size:11px;">—</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bx-send d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No transfers found
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($transfers->hasPages())
            <div class="card-footer" style="padding:12px 20px; display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:12px; color:#4b5563; font-weight:500;">
                    Showing {{ $transfers->firstItem() }}–{{ $transfers->lastItem() }} of {{ $transfers->total() }} records
                </span>
                {{ $transfers->appends(request()->query())->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection
