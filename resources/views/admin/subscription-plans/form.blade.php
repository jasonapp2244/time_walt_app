@extends('layouts.admin')

@section('title', $plan->exists ? 'Edit Plan' : 'New Plan')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <a href="{{ route('admin.subscription-plans.index') }}" class="btn-tv-outline me-3">
                <i class='bx bx-arrow-back me-1'></i>Back
            </a>
            <div>
                <h4 class="section-heading">{{ $plan->exists ? 'Edit Plan' : 'New Plan' }}</h4>
                <p class="section-sub">Saving creates or updates the matching Product and Price in Stripe</p>
            </div>
        </div>

        @if($errors->any())
            <div class="alert-error-tv mb-4"><i class='bx bx-error-circle me-2'></i>{{ $errors->first() }}</div>
        @endif

        <div class="row g-3">
            <div class="col-12 col-lg-8">
                <div class="card">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ $plan->exists ? route('admin.subscription-plans.update', $plan) : route('admin.subscription-plans.store') }}">
                            @csrf
                            @if($plan->exists)
                                @method('PUT')
                            @endif

                            <div class="mb-4">
                                <label class="tv-label">Name</label>
                                <input type="text" name="name" value="{{ old('name', $plan->name) }}" class="tv-input" maxlength="100" placeholder="Premium" required>
                                @error('name')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-4">
                                <label class="tv-label">Description</label>
                                <textarea name="description" rows="2" class="tv-input" maxlength="1000" placeholder="Shown under the plan name in the app">{{ old('description', $plan->description) }}</textarea>
                                @error('description')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-4">
                                <label class="tv-label">Features (one per line)</label>
                                <textarea name="features" rows="4" class="tv-input" placeholder="Unlimited locks&#10;Unlimited withdrawals">{{ old('features', implode("\n", $plan->features ?? [])) }}</textarea>
                                @error('features')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-12 col-md-4">
                                    <label class="tv-label">Price</label>
                                    <input type="number" name="price" step="0.01" min="0.50" max="99999.99" value="{{ old('price', $plan->price) }}" class="tv-input" required>
                                    @error('price')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="tv-label">Currency</label>
                                    <select name="currency" class="form-select">
                                        @foreach(config('services.stripe.supported_currencies') as $code => $label)
                                            <option value="{{ $code }}" {{ old('currency', $plan->currency) === $code ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('currency')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="tv-label">Billing</label>
                                    <select name="interval" class="form-select">
                                        <option value="month" {{ old('interval', $plan->interval) === 'month' ? 'selected' : '' }}>Monthly</option>
                                        <option value="year" {{ old('interval', $plan->interval) === 'year' ? 'selected' : '' }}>Yearly</option>
                                    </select>
                                    @error('interval')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-12 col-md-4">
                                    <label class="tv-label">Sort order</label>
                                    <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $plan->sort_order ?? 0) }}" class="tv-input">
                                    @error('sort_order')<div style="color:#c82333; font-size:12px; margin-top:4px;">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12 col-md-8 d-flex align-items-end">
                                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#374151;">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $plan->is_active) ? 'checked' : '' }}>
                                        Show this plan in the app
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn-tv">
                                <i class='bx bx-save me-1'></i>{{ $plan->exists ? 'Save and sync to Stripe' : 'Create and sync to Stripe' }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card">
                    <div class="card-body p-4" style="font-size:13px; color:#374151;">
                        <h6 style="font-weight:700; color:#111827;"><i class='bx bx-info-circle me-1' style="color:var(--tv-gold);"></i>How changes apply</h6>
                        <ul style="padding-left:18px; margin:0;">
                            <li class="mb-2">Changing the <strong>price, currency or billing period</strong> creates a new Stripe Price. New subscribers pay the new price; existing subscribers keep theirs.</li>
                            <li class="mb-2">Name and description changes update the Stripe Product only.</li>
                            <li class="mb-2">A hidden plan disappears from the app but its subscribers keep renewing.</li>
                            @if($plan->stripe_price_id)
                                <li style="color:#6b7280;">Stripe price: {{ $plan->stripe_price_id }}</li>
                            @endif
                        </ul>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
