@extends('layouts.admin')

@section('title', 'Subscription Plans')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <div class="d-flex align-items-center mb-4">
            <div>
                <h4 class="section-heading">Subscription Plans</h4>
                <p class="section-sub">Plans shown in the app once a user has used the free locks or withdrawals. Saving a plan updates Stripe automatically.</p>
            </div>
            <div class="ms-auto">
                <a href="{{ route('admin.subscription-plans.create') }}" class="btn-tv">
                    <i class='bx bx-plus me-1'></i>New Plan
                </a>
            </div>
        </div>

        @if(session('success'))
            <div class="alert-success-tv mb-4"><i class='bx bx-check-circle me-2'></i>{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert-error-tv mb-4"><i class='bx bx-error-circle me-2'></i>{{ $errors->first() }}</div>
        @endif

        @include('admin.partials.auto-refresh', ['statsRoute' => 'admin.subscription-plans.stats', 'label' => 'Plans or subscribers changed'])
        @include('admin.partials.list-toolbar', ['exportRoute' => 'admin.subscription-plans.export'])

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 tv-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th style="width:80px;">ID</th>
                                <th>Plan</th>
                                <th>Price</th>
                                <th>Active Subscribers</th>
                                <th>Stripe</th>
                                <th>Status</th>
                                <th style="width:260px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($plans as $plan)
                            <tr>
                                <td style="color:#6b7280; font-size:12px; font-weight:500;">{{ ($plans->currentPage() - 1) * $plans->perPage() + $loop->iteration }}</td>
                                <td style="color:#6b7280; font-size:12px; font-weight:600;">{{ str_pad($plan->id, 5, '0', STR_PAD_LEFT) }}</td>
                                <td>
                                    <div style="color:#111827; font-weight:600; font-size:13px;">{{ $plan->name }}</div>
                                    @if($plan->description)
                                        <div style="color:#6b7280; font-size:12px; max-width:320px; white-space:normal;">{{ $plan->description }}</div>
                                    @endif
                                </td>
                                <td style="color:#111827; font-size:13px; font-weight:600;">
                                    {{ strtoupper($plan->currency) }} {{ number_format($plan->price, 2) }}
                                    <span style="color:#6b7280; font-weight:500;">/ {{ $plan->interval }}</span>
                                </td>
                                <td style="color:#374151; font-size:13px; font-weight:600;">{{ number_format($plan->subscribers_count) }}</td>
                                <td>
                                    @if($plan->stripe_price_id)
                                        <span class="tv-badge badge-completed">Synced</span>
                                    @else
                                        <span class="tv-badge badge-failed">Not synced</span>
                                    @endif
                                </td>
                                <td>
                                    @if($plan->is_active)
                                        <span class="tv-badge badge-active">Visible</span>
                                    @else
                                        <span class="tv-badge badge-inactive">Hidden</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <a href="{{ route('admin.subscription-plans.edit', $plan) }}" class="btn-tv-outline" style="padding:4px 10px; font-size:12px;">Edit</a>
                                        <form method="POST" action="{{ route('admin.subscription-plans.toggle', $plan) }}">
                                            @csrf
                                            <button type="submit" class="btn-tv-outline" style="padding:4px 10px; font-size:12px;">
                                                {{ $plan->is_active ? 'Hide' : 'Show' }}
                                            </button>
                                        </form>
                                        @if($plan->subscribers_count === 0)
                                            <form method="POST" action="{{ route('admin.subscription-plans.destroy', $plan) }}"
                                                  onsubmit="return confirm('Delete this plan? Its Stripe product will be archived.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-tv-outline" style="padding:4px 10px; font-size:12px; color:#c82333; border-color:rgba(200,35,51,0.35);">Delete</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="text-center py-5" style="color:#6b7280;">
                                    <i class='bx bxs-crown d-block mb-2' style="font-size:28px; color:#d4963e;"></i>
                                    No plans yet. Users who reach the free limit will see an empty plans screen until one is created.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($plans->hasPages())
            <div class="card-footer tv-pagination-footer">
                {{ $plans->links() }}
            </div>
            @endif
        </div>

    </div>
</div>
@endsection
