@extends('layouts.admin')

@section('title', 'Feedback')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">User Feedback</h4>
                <p class="section-sub">Ratings and comments submitted by users</p>
            </div>
            <div class="ms-auto" style="text-align:right;">
                <div style="font-size:20px; font-weight:700; color:#935510;">
                    {{ number_format($averageRating, 1) }} <span style="font-size:14px; color:#d4963e;">★</span>
                </div>
                <div style="font-size:11px; color:#374151; font-weight:600; text-transform:uppercase; letter-spacing:0.8px;">Average Rating</div>
            </div>
        </div>

        {{-- Rating Summary --}}
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4">
                <a href="{{ route('admin.feedback.index') }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon green mb-2"><i class='bx bxs-message-rounded-detail'></i></div>
                        <div class="stat-value">{{ $totalCount }}</div>
                        <div class="stat-label">Total Feedback</div>
                    </div>
                </a>
            </div>
            @foreach([5, 4] as $star)
            <div class="col-12 col-sm-4">
                <a href="{{ route('admin.feedback.index', ['rating' => $star]) }}" style="text-decoration:none;">
                    <div class="stat-card">
                        <div class="stat-icon orange mb-2"><i class='bx bxs-star'></i></div>
                        <div class="stat-value">{{ $ratingCounts[$star] }}</div>
                        <div class="stat-label">{{ $star }}-Star Ratings</div>
                    </div>
                </a>
            </div>
            @endforeach
        </div>

        <form method="GET" class="filter-bar">
            <label style="color:#374151; font-size:13px; font-weight:600;">Rating:</label>
            <select name="rating" class="form-select" style="max-width:160px;">
                <option value="">All</option>
                @for($star = 5; $star >= 1; $star--)
                    <option value="{{ $star }}" {{ (string) request('rating') === (string) $star ? 'selected' : '' }}>
                        {{ $star }} Star{{ $star > 1 ? 's' : '' }} ({{ $ratingCounts[$star] }})
                    </option>
                @endfor
            </select>
            <button type="submit" class="btn-tv">Filter</button>
            @if(request('rating'))
                <a href="{{ route('admin.feedback.index') }}" class="btn-tv-outline">Clear</a>
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
                                <th>Email</th>
                                <th style="width:140px;">Rating</th>
                                <th>Feedback</th>
                                <th style="width:150px;">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($feedbacks as $feedback)
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">
                                    {{ ($feedbacks->currentPage() - 1) * $feedbacks->perPage() + $loop->iteration }}
                                </td>
                                <td>
                                    @if($feedback->user)
                                        <a href="{{ route('admin.users.show', $feedback->user_id) }}"
                                           style="color:#111827; font-weight:600; font-size:13px; text-decoration:none;">
                                            {{ $feedback->user->displayName('Unknown') }}
                                        </a>
                                    @else
                                        <span style="color:#9ca3af;">Deleted user</span>
                                    @endif
                                </td>
                                <td style="color:#374151; font-size:12px; font-weight:500;">
                                    {{ $feedback->user?->displayEmail() ?? '—' }}
                                </td>
                                <td style="color:#d4963e; font-size:14px; letter-spacing:1px; white-space:nowrap;">
                                    {{ str_repeat('★', $feedback->rating) }}<span style="color:#e5e7eb;">{{ str_repeat('★', 5 - $feedback->rating) }}</span>
                                    <span style="color:#6b7280; font-size:11px; font-weight:600;">({{ $feedback->rating }}/5)</span>
                                </td>
                                <td style="color:#374151; font-size:13px; max-width:420px; word-break:break-word; white-space:normal;">
                                    {{ $feedback->message }}
                                </td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500;">
                                    {{ $feedback->created_at?->setTimezone(config('app.admin_timezone'))->format('d M Y H:i') }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bx-message-rounded-detail d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No feedback found
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($feedbacks->hasPages())
            <div class="card-footer tv-pagination-footer">
                {{ $feedbacks->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection
