@extends('layouts.admin')

@section('title', 'My Profile')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        {{-- Page Header --}}
        <div class="d-flex align-items-center mb-3">
            <div>
                <h4 class="section-heading">My Profile</h4>
                <p class="section-sub">Manage your admin account details and password</p>
            </div>
        </div>

        <div class="row g-3">

            {{-- ── Profile Info Card ──────────────────────────────── --}}
            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header px-3 py-2 d-flex align-items-center gap-2">
                        <i class='bx bxs-user-circle' style="color:var(--tv-gold); font-size:17px;"></i>
                        <span style="font-size:13px; font-weight:600; color:#1a1a1a;">Profile Information</span>
                    </div>
                    <div class="card-body p-3">

                        {{-- Alerts --}}
                        @if(session('success'))
                            <div class="alert-success-tv mb-3" style="padding:8px 12px; font-size:12px;">
                                <i class='bx bx-check-circle me-1'></i>{{ session('success') }}
                            </div>
                        @endif
                        @if($errors->has('email') || $errors->has('full_name'))
                            <div class="alert-error-tv mb-3" style="padding:8px 12px; font-size:12px;">
                                <i class='bx bx-error-circle me-1'></i>{{ $errors->first('email') ?: $errors->first('full_name') }}
                            </div>
                        @endif

                        {{-- Avatar + name row --}}
                        <div class="d-flex align-items-center gap-3 mb-3 pb-3" style="border-bottom:1px solid rgba(189,126,46,0.1);">
                            <div style="width:48px; height:48px; border-radius:50%; flex-shrink:0;
                                        background:rgba(189,126,46,0.12); border:2px solid rgba(189,126,46,0.3);
                                        display:flex; align-items:center; justify-content:center;
                                        font-size:20px; font-weight:700; color:var(--tv-gold);">
                                {{ strtoupper(substr($admin->full_name ?? 'A', 0, 1)) }}
                            </div>
                            <div>
                                <div style="font-size:14px; font-weight:600; color:#1a1a1a;">{{ $admin->full_name ?? 'Admin' }}</div>
                                <div style="font-size:11px; color:rgba(0,0,0,0.4);">{{ $admin->email ?? '' }}</div>
                            </div>
                            <span class="tv-badge badge-active ms-auto">Administrator</span>
                        </div>

                        <form method="POST" action="{{ route('admin.profile.update') }}">
                            @csrf

                            <div class="mb-2">
                                <label class="tv-label">Full Name</label>
                                <input
                                    type="text"
                                    name="full_name"
                                    value="{{ old('full_name', $admin->full_name) }}"
                                    required
                                    class="tv-input"
                                    style="padding:6px 10px; font-size:13px;"
                                    onfocus="this.style.borderColor='rgba(189,126,46,0.55)'"
                                    onblur="this.style.borderColor='rgba(189,126,46,0.22)'"
                                    placeholder="Enter full name"
                                >
                            </div>

                            <div class="mb-3">
                                <label class="tv-label">Email Address</label>
                                <input
                                    type="email"
                                    name="email"
                                    value="{{ old('email', $admin->email) }}"
                                    required
                                    class="tv-input"
                                    style="padding:6px 10px; font-size:13px;"
                                    onfocus="this.style.borderColor='rgba(189,126,46,0.55)'"
                                    onblur="this.style.borderColor='rgba(189,126,46,0.22)'"
                                    placeholder="Enter email address"
                                >
                            </div>

                            <button type="submit"
                                    style="background:linear-gradient(135deg,var(--tv-gold),var(--tv-gold-light));
                                           border:none; border-radius:6px; color:#fff; font-weight:600;
                                           font-size:12px; padding:7px 16px; cursor:pointer;
                                           display:inline-flex; align-items:center; gap:6px;">
                                <i class='bx bx-save'></i>Save Changes
                            </button>
                        </form>

                    </div>
                </div>
            </div>

            {{-- ── Change Password Card ────────────────────────────── --}}
            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header px-3 py-2 d-flex align-items-center gap-2">
                        <i class='bx bxs-lock-alt' style="color:var(--tv-gold); font-size:17px;"></i>
                        <span style="font-size:13px; font-weight:600; color:#1a1a1a;">Change Password</span>
                    </div>
                    <div class="card-body p-3">

                        {{-- Alerts --}}
                        @if(session('password_success'))
                            <div class="alert-success-tv mb-3" style="padding:8px 12px; font-size:12px;">
                                <i class='bx bx-check-circle me-1'></i>{{ session('password_success') }}
                            </div>
                        @endif
                        @if($errors->has('current_password') || $errors->has('new_password'))
                            <div class="alert-error-tv mb-3" style="padding:8px 12px; font-size:12px;">
                                <i class='bx bx-error-circle me-1'></i>{{ $errors->first('current_password') ?: $errors->first('new_password') }}
                            </div>
                        @endif

                        <form method="POST" action="{{ route('admin.profile.change-password') }}">
                            @csrf

                            <div class="mb-2">
                                <label class="tv-label">Current Password</label>
                                <div style="position:relative;">
                                    <input
                                        type="password"
                                        name="current_password"
                                        id="cur_pwd"
                                        required
                                        class="tv-input"
                                        style="padding:6px 34px 6px 10px; font-size:13px;"
                                        onfocus="this.style.borderColor='rgba(189,126,46,0.55)'"
                                        onblur="this.style.borderColor='rgba(189,126,46,0.22)'"
                                        placeholder="Enter current password"
                                    >
                                    <i class='bx bx-hide' onclick="togglePwd('cur_pwd',this)"
                                       style="position:absolute; right:10px; top:50%; transform:translateY(-50%);
                                              color:rgba(0,0,0,0.28); cursor:pointer; font-size:15px;"></i>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="tv-label">New Password</label>
                                <div style="position:relative;">
                                    <input
                                        type="password"
                                        name="new_password"
                                        id="new_pwd"
                                        required
                                        class="tv-input"
                                        style="padding:6px 34px 6px 10px; font-size:13px;"
                                        onfocus="this.style.borderColor='rgba(189,126,46,0.55)'"
                                        onblur="this.style.borderColor='rgba(189,126,46,0.22)'"
                                        placeholder="Min. 8 characters"
                                    >
                                    <i class='bx bx-hide' onclick="togglePwd('new_pwd',this)"
                                       style="position:absolute; right:10px; top:50%; transform:translateY(-50%);
                                              color:rgba(0,0,0,0.28); cursor:pointer; font-size:15px;"></i>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="tv-label">Confirm New Password</label>
                                <div style="position:relative;">
                                    <input
                                        type="password"
                                        name="new_password_confirmation"
                                        id="conf_pwd"
                                        required
                                        class="tv-input"
                                        style="padding:6px 34px 6px 10px; font-size:13px;"
                                        onfocus="this.style.borderColor='rgba(189,126,46,0.55)'"
                                        onblur="this.style.borderColor='rgba(189,126,46,0.22)'"
                                        placeholder="Repeat new password"
                                    >
                                    <i class='bx bx-hide' onclick="togglePwd('conf_pwd',this)"
                                       style="position:absolute; right:10px; top:50%; transform:translateY(-50%);
                                              color:rgba(0,0,0,0.28); cursor:pointer; font-size:15px;"></i>
                                </div>
                            </div>

                            <button type="submit"
                                    style="background:linear-gradient(135deg,#c82333,#a71d2a);
                                           border:none; border-radius:6px; color:#fff; font-weight:600;
                                           font-size:12px; padding:7px 16px; cursor:pointer;
                                           display:inline-flex; align-items:center; gap:6px;">
                                <i class='bx bxs-lock-alt'></i>Change Password
                            </button>
                        </form>

                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function togglePwd(id, icon) {
    var input = document.getElementById(id);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bx-hide', 'bx-show');
    } else {
        input.type = 'password';
        icon.classList.replace('bx-show', 'bx-hide');
    }
}
</script>
@endpush
