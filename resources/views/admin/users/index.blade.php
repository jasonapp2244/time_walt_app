@extends('layouts.admin')

@section('title', 'Users')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Users</h4>
                <p class="section-sub">All registered app users</p>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <button id="users-refresh-btn" onclick="window.location.reload()"
                    style="display:none; font-size:12px; color:#c87000; font-weight:600; cursor:pointer; background:rgba(255,152,0,0.1); border:1px solid rgba(255,152,0,0.3); border-radius:6px; padding:4px 10px;">
                    <i class='bx bx-refresh me-1'></i>New users added — click to reload
                </button>
                <span class="tv-badge badge-ready" id="users-total-badge" style="font-size:13px; padding:5px 13px;">
                    {{ $users->total() }} Total Users
                </span>
            </div>
        </div>

        @if(session('success'))
            <div class="alert-success-tv mb-3"><i class='bx bx-check-circle me-2'></i>{{ session('success') }}</div>
        @endif

        <form method="GET" class="filter-bar mb-3">
            <i class='bx bx-search' style="color:#374151; font-size:16px; flex-shrink:0;"></i>
            <input type="text" name="search" value="{{ request('search') }}"
                placeholder="Search by email or user ID..." class="form-control" style="max-width:280px;">
            <select name="status" class="form-select" style="max-width:150px;">
                <option value="">All Status</option>
                <option value="active"   {{ request('status') == 'active'   ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                <option value="deleted"  {{ request('status') == 'deleted'  ? 'selected' : '' }}>Deleted</option>
            </select>
            <button type="submit" class="btn-tv"><i class='bx bx-filter-alt me-1'></i>Search</button>
            @if(request()->hasAny(['status', 'search']))
                <a href="{{ route('admin.users.index') }}" class="btn-tv-outline"><i class='bx bx-x me-1'></i>Clear</a>
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
                                <th>Phone</th>
                                <th>Status</th>
                                <th style="text-align:center;">Verified</th>
                                <th style="text-align:center;">Holds</th>
                                <th style="text-align:center;">Transfers</th>
                                <th>Joined</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($users as $index => $user)
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">
                                    {{ ($users->currentPage() - 1) * $users->perPage() + $loop->iteration }}
                                </td>
                                <td>
                                    <div style="font-weight:600; color:#111827; font-size:13px; line-height:1.3;">
                                        {{ $user->full_name ?? '—' }}
                                    </div>
                                    <div style="font-size:11px; color:#6b7280; margin-top:2px;">
                                        {{ $user->email ?? '' }}
                                    </div>
                                </td>
                                <td style="color:#374151; font-size:13px; font-weight:500;">
                                    {{ $user->phone ?? '—' }}
                                </td>
                                <td>
                                    <span class="tv-badge badge-{{ $user->status ?? 'active' }}">
                                        {{ ucfirst($user->status ?? 'active') }}
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    @if($user->is_verified)
                                        <span class="tv-badge badge-completed"><i class='bx bx-check'></i></span>
                                    @else
                                        <span class="tv-badge badge-pending">No</span>
                                    @endif
                                </td>
                                <td style="color:#92621a; font-weight:700; text-align:center; font-size:14px;">
                                    {{ $user->payment_holds_count }}
                                </td>
                                <td style="color:#1e8c3a; font-weight:700; text-align:center; font-size:14px;">
                                    {{ $user->transfers_count }}
                                </td>
                                <td style="color:#4b5563; font-size:12px; font-weight:500; white-space:nowrap;">
                                    {{ $user->created_at->format('d M Y') }}
                                </td>
                                <td style="text-align:center;">
                                    <a href="{{ route('admin.users.show', $user->id) }}" class="btn-tv-outline">
                                        <i class='bx bx-show me-1'></i>View
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bx-user-x d-block mb-2' style="font-size:32px; color:#d4963e;"></i>
                                    No users found
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($users->hasPages())
            <div class="card-footer" style="padding:12px 20px; display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:12px; color:#4b5563; font-weight:500;">
                    Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }} users
                </span>
                {{ $users->appends(request()->query())->links() }}
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
    const STATS_URL = '{{ route("admin.users.stats") }}';

    // Snapshot on page load — tracks every dimension that can change
    // without the total count changing (e.g. pending→active after OTP verify)
    const snapshot = {
        total:    {{ $users->total() }},
        active:   {{ \App\Models\User::where('role','user')->where('status','active')->count() }},
        inactive: {{ \App\Models\User::where('role','user')->where('status','inactive')->count() }},
        pending:  {{ \App\Models\User::where('role','user')->where('status','pending')->count() }},
        verified: {{ \App\Models\User::where('role','user')->where('is_verified',true)->count() }},
    };
    let reloading = false;

    function autoReload(message) {
        if (reloading) { return; }
        reloading = true;

        const banner = document.getElementById('users-refresh-btn');
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

    function pollUsers() {
        fetch(STATS_URL, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(data => {
            const changed = data.total    !== snapshot.total
                         || data.active   !== snapshot.active
                         || data.inactive !== snapshot.inactive
                         || data.pending  !== snapshot.pending
                         || data.verified !== snapshot.verified;

            if (changed) {
                // Pick a meaningful message based on what changed
                let msg = 'User data updated';
                if (data.verified !== snapshot.verified) { msg = 'User verified their account'; }
                else if (data.active !== snapshot.active)  { msg = 'User status changed'; }
                else if (data.total  !== snapshot.total)   { msg = 'New user registered'; }
                autoReload(msg);
            }
        })
        .catch(err => console.warn('[Users] Poll failed:', err));
    }

    setTimeout(pollUsers, 5000);           // first check after 5 seconds
    setInterval(pollUsers, POLL_INTERVAL); // then every minute
})();
</script>
@endpush
