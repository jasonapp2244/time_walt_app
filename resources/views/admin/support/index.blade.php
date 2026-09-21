@extends('layouts.admin')

@section('title', 'Support')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Support Requests</h4>
                <p class="section-sub">Help requests submitted by users from the app</p>
            </div>
        </div>

        {{-- Summary --}}
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4">
                <a href="{{ route('admin.support.index') }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon green mb-2"><i class='bx bxs-help-circle'></i></div>
                        <div class="stat-value">{{ $totalCount }}</div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                </a>
            </div>
            <div class="col-12 col-sm-4">
                <div class="stat-card">
                    <div class="stat-icon orange mb-2"><i class='bx bxs-time-five'></i></div>
                    <div class="stat-value">{{ $todayCount }}</div>
                    <div class="stat-label">Today</div>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="stat-card">
                    <div class="stat-icon orange mb-2"><i class='bx bxs-calendar'></i></div>
                    <div class="stat-value">{{ $weekCount }}</div>
                    <div class="stat-label">Last 7 Days</div>
                </div>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">Search:</label>
            <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                   style="max-width:320px;" placeholder="Subject, message or full email">
            <button type="submit" class="btn-tv">Search</button>
            @if(request('search'))
                <a href="{{ route('admin.support.index') }}" class="btn-tv-outline">Clear</a>
            @endif
        </form>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th style="width:80px;">ID</th>
                                <th>User</th>
                                <th>Email</th>
                                <th style="width:220px;">Subject</th>
                                <th>Message</th>
                                <th style="width:150px;">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($supportRequests as $supportRequest)
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">
                                    {{ ($supportRequests->currentPage() - 1) * $supportRequests->perPage() + $loop->iteration }}
                                </td>
                                <td style="color:#6b7280; font-size:12px; font-weight:600;">
                                    {{ str_pad($supportRequest->id, 5, '0', STR_PAD_LEFT) }}
                                </td>
                                <td>
                                    @if($supportRequest->user)
                                        <a href="{{ route('admin.users.show', $supportRequest->user_id) }}"
                                           style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">
                                            {{ $supportRequest->user->displayName('Unknown') }}
                                        </a>
                                    @else
                                        <span style="color:#9ca3af;">Deleted user</span>
                                    @endif
                                </td>
                                <td style="color:#374151; font-size:12px; font-weight:500;">
                                    {{ $supportRequest->user?->displayEmail() ?? '—' }}
                                </td>
                                <td style="color:#111827; font-size:13px; font-weight:600; word-break:break-word; white-space:normal;">
                                    {{ $supportRequest->subject }}
                                </td>
                                <td style="color:#374151; font-size:13px; max-width:420px; word-break:break-word; white-space:normal;">
                                    {{ $supportRequest->message }}
                                </td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $supportRequest->created_at?->setTimezone(config('app.admin_timezone'))->format('d M Y H:i') }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bx-help-circle d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No support requests found
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($supportRequests->hasPages())
            <div class="card-footer tv-pagination-footer">
                {{ $supportRequests->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection
