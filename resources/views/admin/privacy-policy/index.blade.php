@extends('layouts.admin')

@section('title', 'Privacy Policy')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Privacy Policy</h4>
                <p class="section-sub">Manage the active privacy policy shown in the mobile app</p>
            </div>
        </div>

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="alert-success-tv mb-4"><i class='bx bx-check-circle me-2'></i>{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert-error-tv mb-4">
                <i class='bx bx-error-circle me-2'></i>
                {{ $errors->first() }}
            </div>
        @endif

        <div class="row g-3">
            <div class="col-12 col-lg-8">
                <div class="card">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0" style="color:#111827; font-weight:700;">
                            <i class='bx bxs-edit me-2' style="color:var(--tv-gold);"></i>
                            {{ $policy ? 'Update Privacy Policy' : 'Create Privacy Policy' }}
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('admin.privacy-policy.store') }}">
                            @csrf

                            <div class="mb-4">
                                <label class="tv-label">Title</label>
                                <input
                                    type="text"
                                    name="title"
                                    value="{{ old('title', $policy?->title) }}"
                                    placeholder="Privacy Policy"
                                    class="tv-input"
                                    onfocus="this.style.borderColor='rgba(189,126,46,0.55)'"
                                    onblur="this.style.borderColor='rgba(189,126,46,0.22)'"
                                    required
                                >
                                @error('title')
                                    <div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label class="tv-label">Effective Date</label>
                                <input
                                    type="date"
                                    name="effective_date"
                                    value="{{ old('effective_date', $policy?->effective_date?->format('Y-m-d')) }}"
                                    class="tv-input"
                                    style="width:auto;"
                                    onfocus="this.style.borderColor='rgba(189,126,46,0.55)'"
                                    onblur="this.style.borderColor='rgba(189,126,46,0.22)'"
                                    required
                                >
                                @error('effective_date')
                                    <div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label class="tv-label">Content</label>
                                <textarea
                                    name="content"
                                    rows="16"
                                    placeholder="Write your privacy policy here..."
                                    class="tv-input"
                                    style="line-height:1.6; resize:vertical;"
                                    onfocus="this.style.borderColor='rgba(189,126,46,0.55)'"
                                    onblur="this.style.borderColor='rgba(189,126,46,0.22)'"
                                    required
                                >{{ old('content', $policy?->content) }}</textarea>
                                @error('content')
                                    <div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit"
                                    style="background:linear-gradient(135deg,#bd7e2e,#d4963e); border:none;
                                           border-radius:7px; color:#fff; font-weight:600; font-size:13px;
                                           padding:9px 22px; cursor:pointer; display:inline-flex; align-items:center; letter-spacing:0.3px;">
                                <i class='bx bx-save me-2'></i>
                                {{ $policy ? 'Update Policy' : 'Publish Policy' }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Current Policy Info --}}
            <div class="col-12 col-lg-4">
                <div class="card">
                    <div class="card-header py-3 px-4">
                        <h6 class="mb-0" style="color:#111827; font-weight:700;">
                            <i class='bx bxs-info-circle me-2' style="color:var(--tv-gold);"></i>Current Policy
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        @if($policy)
                            <div class="mb-3">
                                <div style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px;">Title</div>
                                <div style="color:#111827; font-weight:600;">{{ $policy->title }}</div>
                            </div>
                            <div class="mb-3">
                                <div style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px;">Status</div>
                                <span class="tv-badge badge-completed">Active</span>
                            </div>
                            <div class="mb-3">
                                <div style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px;">Effective Date</div>
                                <div style="color:#111827; font-weight:600;">{{ $policy->effective_date?->format('d M Y') ?? '—' }}</div>
                            </div>
                            <div>
                                <div style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px;">Last Updated</div>
                                <div style="color:#4b5563; font-size:13px; font-weight:500;">{{ $policy->updated_at->format('d M Y H:i') }}</div>
                            </div>
                        @else
                            <div style="color:#6b7280; text-align:center; padding:20px 0; font-size:13px;">
                                <i class='bx bx-file mb-2 d-block' style="font-size:28px; color:#d4963e;"></i>
                                No policy published yet
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
