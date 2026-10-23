{{--
    Export buttons for an admin list. The current filters (query string) are
    carried over, so the download matches what is on screen.
    Usage: @include('admin.partials.list-toolbar', ['exportRoute' => 'admin.users.export'])
--}}
@php
    $exportQuery = collect(request()->query())->except(['page', 'format'])->all();
@endphp
<div class="d-flex justify-content-end align-items-center gap-2 mb-2">
    <span style="font-size:12px; color:#6b7280; font-weight:500;">Download{{ $exportQuery ? ' (filtered)' : '' }}:</span>
    <a href="{{ route($exportRoute, $exportQuery + ['format' => 'xlsx']) }}" class="btn-tv-outline" style="padding:4px 12px; font-size:12px;">
        <i class='bx bxs-spreadsheet me-1' style="color:#1e8c3a;"></i>Excel
    </a>
    <a href="{{ route($exportRoute, $exportQuery + ['format' => 'pdf']) }}" class="btn-tv-outline" style="padding:4px 12px; font-size:12px;">
        <i class='bx bxs-file-pdf me-1' style="color:#c82333;"></i>PDF
    </a>
</div>
