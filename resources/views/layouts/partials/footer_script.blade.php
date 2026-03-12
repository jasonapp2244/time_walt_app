<!-- Bootstrap JS -->
<script src="{{ asset('admin/js/bootstrap.bundle.min.js') }}"></script>

<!-- Plugins -->
<script src="{{ asset('admin/js/jquery.min.js') }}"></script>
<script src="{{ asset('admin/plugins/simplebar/js/simplebar.min.js') }}"></script>
<script src="{{ asset('admin/plugins/metismenu/js/metisMenu.min.js') }}"></script>
<script src="{{ asset('admin/plugins/perfect-scrollbar/js/perfect-scrollbar.js') }}"></script>

<!-- App JS -->
<script src="{{ asset('admin/js/app.js') }}"></script>

<script>
    $(function () {

        // ── Desktop sidebar toggle (arrow) ─────────────────────────────
        $(document).on('click', '.toggle-icon', function () {
            if ($('.wrapper').hasClass('toggled')) {
                $('.wrapper').removeClass('toggled');
                $('.sidebar-wrapper').off('mouseenter mouseleave');
            } else {
                $('.wrapper').addClass('toggled');
                $('.sidebar-wrapper').on('mouseenter', function () {
                    $('.wrapper').addClass('sidebar-hovered');
                }).on('mouseleave', function () {
                    $('.wrapper').removeClass('sidebar-hovered');
                });
            }
        });

        // ── Mobile hamburger: open/close sidebar ───────────────────────
        $('.mobile-toggle-menu').off('click').on('click', function () {
            $('.wrapper').toggleClass('toggled');
        });

        // ── Close mobile sidebar on overlay click ──────────────────────
        $(document).on('click', '.overlay', function () {
            $('.wrapper').removeClass('toggled');
        });

    });
</script>

@stack('scripts')
