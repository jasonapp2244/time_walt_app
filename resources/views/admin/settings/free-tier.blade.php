@extends('layouts.admin')

@section('title', 'Free Tier Settings')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Free Tier Settings</h4>
                <p class="section-sub">How many locks and withdrawals a user may make before the app asks them to subscribe</p>
            </div>
        </div>

        @if(session('success'))
            <div class="alert-success-tv mb-4"><i class='bx bx-check-circle me-2'></i>{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert-error-tv mb-4"><i class='bx bx-error-circle me-2'></i>{{ $errors->first() }}</div>
        @endif

        <div class="row g-3">
            <div class="col-12 col-lg-7">
                <div class="card">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('admin.settings.free-tier.update') }}">
                            @csrf

                            <div class="mb-4">
                                <label class="tv-label">Free locks per account</label>
                                <input type="number" name="free_lock_limit" min="0" max="1000" value="{{ old('free_lock_limit', $lockLimit) }}" class="tv-input" style="max-width:160px;" required>
                                <div style="color:#6b7280; font-size:12px; margin-top:4px;">The next lock after this many opens the subscription plans screen. 0 means every lock needs a subscription.</div>
                                @error('free_lock_limit')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#374151;">
                                    <input type="hidden" name="withdraw_limit_enabled" value="0">
                                    <input type="checkbox" name="withdraw_limit_enabled" value="1" {{ old('withdraw_limit_enabled', $withdrawLimitEnabled) ? 'checked' : '' }}>
                                    Limit withdrawals too
                                </label>
                                <div style="color:#6b7280; font-size:12px; margin-top:4px;">When off, users can always withdraw money whose hold period has ended.</div>
                            </div>

                            <div class="mb-4">
                                <label class="tv-label">Free withdrawals per account</label>
                                <input type="number" name="free_withdraw_limit" min="0" max="1000" value="{{ old('free_withdraw_limit', $withdrawLimit) }}" class="tv-input" style="max-width:160px;" required>
                                @error('free_withdraw_limit')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                            </div>

                            <button type="submit" class="btn-tv"><i class='bx bx-save me-1'></i>Save</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="card">
                    <div class="card-body p-4" style="font-size:13px; color:#374151;">
                        <h6 style="font-weight:700; color:#111827;"><i class='bx bx-info-circle me-1' style="color:var(--tv-gold);"></i>How the limit works</h6>
                        <ul style="padding-left:18px; margin:0;">
                            <li class="mb-2">Counts are per account and never reset on their own. Reset one user from their detail page.</li>
                            <li class="mb-2">A lock counts once its hold is created, so an abandoned payment does not use one up.</li>
                            <li class="mb-2">A withdrawal counts only when money is actually sent.</li>
                            <li>Subscribers are never limited or counted.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
