@extends('layouts.admin')

@section('title', 'Payments')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Payments</h4>
                <p class="section-sub">All Stripe payment records</p>
            </div>
            <div class="ms-auto">
                <div style="text-align:right;">
                    <div style="font-size:20px; font-weight:700; color:#1e8c3a;">${{ number_format($totalRevenue, 2) }}</div>
                    <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.8px;">Total Revenue</div>
                </div>
            </div>
        </div>

        {{-- Status Summary --}}
        <div class="row g-3 mb-4">
            <div class="col-4">
                <a href="{{ route('admin.payments.index', ['status' => 'completed']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon green mb-2"><i class='bx bxs-check-circle'></i></div>
                        <div class="stat-value">{{ $statusCounts['completed'] }}</div>
                        <div class="stat-label">Completed</div>
                    </div>
                </a>
            </div>
            <div class="col-4">
                <a href="{{ route('admin.payments.index', ['status' => 'pending']) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon orange mb-2"><i class='bx bx-time'></i></div>
                        <div class="stat-value">{{ $statusCounts['pending'] }}</div>
                        <div class="stat-label">Pending</div>
                    </div>
                </a>
            </div>
            <div class="col-4">
                <a href="{{ route('admin.payments.index', ['status' => 'failed']) }}" style="text-decoration:none;">
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
                <a href="{{ route('admin.payments.index') }}" class="btn-tv-outline">Clear</a>
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
                                <th>Status</th>
                                <th>Failure Reason</th>
                                <th>Paid At</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($payments as $payment)
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">
                                    {{ ($payments->currentPage() - 1) * $payments->perPage() + $loop->iteration }}
                                </td>
                                <td>
                                    <a href="{{ route('admin.users.show', $payment->user_id) }}"
                                       style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">
                                        {{ $payment->user?->full_name ?? 'Unknown' }}
                                    </a>
                                    <div style="font-size:11px; color:#6b7280; margin-top:1px;">
                                        {{ $payment->user?->email ?? '' }}
                                    </div>
                                </td>
                                <td style="color:#92621a; font-weight:700; font-size:15px;">
                                    ${{ number_format($payment->amount, 2) }}
                                </td>
                                <td style="color:#374151; font-size:12px; font-weight:600;">
                                    {{ strtoupper($payment->currency ?? 'USD') }}
                                </td>
                                <td>
                                    <span class="tv-badge badge-{{ $payment->status }}">
                                        {{ ucfirst($payment->status) }}
                                    </span>
                                </td>
                                <td style="color:#c82333; font-size:12px; font-weight:500; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    {{ $payment->failure_reason ?? '—' }}
                                </td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $payment->paid_at?->format('d M Y H:i') ?? '—' }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bx-credit-card d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No payments found
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($payments->hasPages())
            <div class="card-footer" style="padding:12px 20px; display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:12px; color:#4b5563; font-weight:500;">
                    Showing {{ $payments->firstItem() }}–{{ $payments->lastItem() }} of {{ $payments->total() }} records
                </span>
                {{ $payments->appends(request()->query())->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection
