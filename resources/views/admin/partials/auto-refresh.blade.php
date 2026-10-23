{{--
    Auto-refresh for a list without live stat cards. Polls a stats endpoint
    every minute and, when the returned values change (new or edited rows),
    shows a banner that reloads the page on click. Never reloads by itself,
    so an admin mid-way through reading or filtering is not interrupted.

    Usage: @include('admin.partials.auto-refresh', ['statsRoute' => 'admin.feedback.stats', 'label' => 'New feedback'])
--}}
<div class="d-flex justify-content-end mb-2">
    <span id="list-refresh-alert" style="display:none; font-size:12px; color:#c87000; font-weight:600; cursor:pointer; background:rgba(255,152,0,0.1); border:1px solid rgba(255,152,0,0.3); border-radius:6px; padding:4px 10px;" onclick="window.location.reload()">
        <i class='bx bx-refresh me-1'></i>{{ $label }} — click to reload
    </span>
</div>

@push('scripts')
<script>
(function () {
    const STATS_URL = '{{ route($statsRoute) }}';
    let baseline = null;

    function poll() {
        fetch(STATS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(data => {
                const snapshot = JSON.stringify(data);
                if (baseline === null) { baseline = snapshot; return; }
                if (snapshot !== baseline) {
                    document.getElementById('list-refresh-alert').style.display = 'inline-flex';
                }
            })
            .catch(err => console.warn('[Auto refresh] Poll failed:', err));
    }

    poll();                       // baseline as the page loads
    setInterval(poll, 60 * 1000); // then every minute
})();
</script>
@endpush
