<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="{{ asset('admin/images/favicon.png') }}" type="image/png" />

    <!-- Plugins -->
    <link href="{{ asset('admin/plugins/vectormap/jquery-jvectormap-2.0.2.css') }}" rel="stylesheet" />
    <link href="{{ asset('admin/plugins/simplebar/css/simplebar.css') }}" rel="stylesheet" />
    <link href="{{ asset('admin/plugins/perfect-scrollbar/css/perfect-scrollbar.css') }}" rel="stylesheet" />
    <link href="{{ asset('admin/plugins/metismenu/css/metisMenu.min.css') }}" rel="stylesheet" />

    <!-- Loader -->
    <link href="{{ asset('admin/css/pace.min.css') }}" rel="stylesheet" />
    <script src="{{ asset('admin/js/pace.min.js') }}"></script>
    <style>
        .pace .pace-progress { background: #bd7e2e !important; }
        .pace .pace-progress-inner { box-shadow: 0 0 10px #bd7e2e, 0 0 5px #bd7e2e !important; }
        .pace .pace-activity { border-top-color: #bd7e2e !important; border-left-color: #bd7e2e !important; }
    </style>

    <!-- Bootstrap & App CSS -->
    <link href="{{ asset('admin/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/css/bootstrap-extended.css') }}" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="{{ asset('admin/css/app.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/css/icons.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/css/dark-theme.css') }}" rel="stylesheet" />
    <link href="{{ asset('admin/css/semi-dark.css') }}" rel="stylesheet" />
    <link href="{{ asset('admin/css/header-colors.css') }}" rel="stylesheet" />

    <title>@yield('title', 'Admin') — Time Vault</title>

    <style>
        :root {
            --tv-gold: #bd7e2e;
            --tv-gold-light: #d4963e;
            --tv-gold-pale: #f5ead8;
            --tv-bg: #f0ece4;
            --tv-white: #ffffff;
            --tv-text: #1a1a1a;
            --tv-text-muted: #6b7280;
            --tv-border: rgba(189,126,46,0.15);
        }

        /* ── Global Layout ────────────────────────── */
        body,
        .wrapper,
        .page-wrapper,
        .page-content {
            background: #f0ece4 !important;
        }

        /* ── Sidebar ──────────────────────────────── */
        .sidebar-wrapper {
            background: #ffffff !important;
            border-right: 1px solid rgba(189,126,46,0.15) !important;
            box-shadow: 2px 0 8px rgba(0,0,0,0.04) !important;
        }

        .sidebar-header {
            background: #ffffff !important;
            border-bottom: 1px solid rgba(189,126,46,0.12) !important;
            padding: 16px 20px !important;
        }

        .logo-text {
            color: var(--tv-gold) !important;
            font-weight: 700 !important;
            font-size: 18px !important;
            letter-spacing: 2px !important;
            text-transform: uppercase !important;
        }

        .metismenu li a {
            color: #4b5563 !important;
            border-radius: 8px !important;
            margin: 2px 12px !important;
            padding: 9px 14px !important;
            transition: all 0.2s !important;
        }

        .metismenu li a:hover {
            color: var(--tv-gold) !important;
            background: rgba(189,126,46,0.07) !important;
        }

        .metismenu li.mm-active > a,
        .metismenu li a.active {
            color: #ffffff !important;
            background: linear-gradient(135deg, var(--tv-gold), var(--tv-gold-light)) !important;
            box-shadow: 0 4px 12px rgba(189,126,46,0.28) !important;
        }

        .metismenu li.mm-active > a .parent-icon i,
        .metismenu li a.active .parent-icon i {
            color: #ffffff !important;
        }

        .metismenu .parent-icon i { color: var(--tv-gold) !important; font-size: 20px !important; }
        .metismenu .menu-title { font-size: 13px !important; font-weight: 500 !important; }

        .sidebar-header .logo-tv-img {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }

        /* ── Sidebar toggle arrow ─────────────────── */
        .toggle-icon { cursor: pointer; }
        .toggle-icon i {
            font-size: 22px;
            color: #6b7280;
            transition: transform 0.3s ease, color 0.2s;
            display: inline-block;
        }
        .toggle-icon:hover i { color: var(--tv-gold); }

        /* Keep toggle-icon always visible on desktop so user can re-expand */
        @media screen and (min-width: 1025px) {
            .wrapper.toggled:not(.sidebar-hovered) .sidebar-wrapper .sidebar-header .toggle-icon {
                display: flex !important;
            }
            .wrapper.toggled:not(.sidebar-hovered) .sidebar-header .toggle-icon i {
                transform: rotate(180deg);
            }
            /* Ensure sidebar collapses and page shifts */
            .wrapper.toggled:not(.sidebar-hovered) .sidebar-wrapper {
                width: 70px !important;
            }
            .wrapper.toggled .page-wrapper {
                margin-left: 70px !important;
            }
        }

        /* Hide toggle arrow on mobile — hamburger menu handles it */
        @media screen and (max-width: 1024px) {
            .toggle-icon { display: none !important; }
        }

        .wrapper.toggled:not(.sidebar-hovered) .sidebar-header .logo-tv-img,
        .wrapper.toggled:not(.sidebar-hovered) .sidebar-header .logo-text {
            display: none !important;
        }

        /* ── Topbar Header ────────────────────────── */
        .topbar {
            background: #ffffff !important;
            border-bottom: 1px solid rgba(189,126,46,0.12) !important;
            box-shadow: 0 1px 6px rgba(0,0,0,0.05) !important;
        }

        .user-name { color: #1a1a1a !important; font-weight: 600 !important; font-size: 14px !important; }
        .designattion { color: var(--tv-gold) !important; font-size: 12px !important; }

        /* ── Cards ────────────────────────────────── */
        .card {
            background: #ffffff !important;
            border: 1px solid rgba(189,126,46,0.12) !important;
            border-radius: 12px !important;
            overflow: hidden !important;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04) !important;
        }

        .card-header {
            background: #faf8f5 !important;
            border-bottom: 1px solid rgba(189,126,46,0.1) !important;
            border-radius: 0 !important;
        }

        .card-footer {
            background: #faf8f5 !important;
            border-top: 1px solid rgba(189,126,46,0.1) !important;
            border-radius: 0 !important;
        }

        /* ── Stat Cards ───────────────────────────── */
        .stat-card {
            background: #ffffff;
            border: 1px solid rgba(189,126,46,0.14);
            border-radius: 12px;
            padding: 18px 20px;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(189,126,46,0.12);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-icon.gold   { background: rgba(189,126,46,0.12); color: var(--tv-gold); }
        .stat-icon.cream  { background: rgba(189,126,46,0.09); color: #c8962a; }
        .stat-icon.green  { background: rgba(40,167,69,0.10);  color: #1e9c42; }
        .stat-icon.red    { background: rgba(220,53,69,0.10);  color: #c82333; }
        .stat-icon.blue   { background: rgba(13,110,253,0.10); color: #0d5ed4; }
        .stat-icon.orange { background: rgba(255,152,0,0.10);  color: #e08900; }
        .stat-icon.purple { background: rgba(111,66,193,0.10); color: #6234b0; }
        .stat-icon.teal   { background: rgba(32,201,151,0.10); color: #18a775; }

        .stat-value { font-size: 24px; font-weight: 700; color: #111827; }
        .stat-label { font-size: 11px; color: #374151; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 2px; font-weight: 600; }
        .stat-sub   { font-size: 12px; color: #4b5563; margin-top: 4px; font-weight: 500; }

        /* ── Tables ───────────────────────────────── */
        .table {
            color: #1a1a1a !important;
            --bs-table-bg: #ffffff;
            --bs-table-hover-bg: #fdf9f4;
            --bs-table-hover-color: #1a1a1a;
            --bs-table-striped-bg: #faf8f5;
            --bs-table-color: #1a1a1a;
        }
        .table thead th {
            background: #faf7f2 !important;
            color: #92621a !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.8px !important;
            border-color: rgba(189,126,46,0.12) !important;
            padding: 10px 16px !important;
        }
        .table tbody tr {
            background-color: #ffffff !important;
        }
        .table tbody td,
        .table tbody th {
            background-color: #ffffff !important;
            color: #1a1a1a !important;
            border-color: rgba(0,0,0,0.05) !important;
            padding: 10px 16px !important;
            vertical-align: middle !important;
            font-size: 13px !important;
        }
        .table tbody tr:hover td,
        .table tbody tr:hover th,
        .tv-table tbody tr:hover td,
        .tv-table tbody tr:hover th {
            background-color: #fdf9f4 !important;
            color: #1a1a1a !important;
        }
        .table-light { --bs-table-bg: #faf7f2 !important; }

        /* ── Badges ───────────────────────────────── */
        .badge-holding      { background: rgba(255,152,0,0.11);   color: #c87800; border: 1px solid rgba(255,152,0,0.22); }
        .badge-ready        { background: rgba(189,126,46,0.11);  color: var(--tv-gold); border: 1px solid rgba(189,126,46,0.22); }
        .badge-transferred  { background: rgba(40,167,69,0.11);   color: #1e9c42; border: 1px solid rgba(40,167,69,0.22); }
        .badge-partial      { background: rgba(13,202,240,0.11);  color: #0a9ab8; border: 1px solid rgba(13,202,240,0.22); }
        .badge-failed       { background: rgba(220,53,69,0.11);   color: #c82333; border: 1px solid rgba(220,53,69,0.22); }
        .badge-pending      { background: rgba(108,117,125,0.11); color: #495057; border: 1px solid rgba(108,117,125,0.22); }
        .badge-completed    { background: rgba(40,167,69,0.11);   color: #1e9c42; border: 1px solid rgba(40,167,69,0.22); }
        .badge-succeeded    { background: rgba(40,167,69,0.11);   color: #1e9c42; border: 1px solid rgba(40,167,69,0.22); }
        .badge-active       { background: rgba(40,167,69,0.11);   color: #1e9c42; border: 1px solid rgba(40,167,69,0.22); }
        .badge-deleted      { background: rgba(220,53,69,0.11);   color: #c82333; border: 1px solid rgba(220,53,69,0.22); }
        .badge-inactive     { background: rgba(108,117,125,0.11); color: #495057; border: 1px solid rgba(108,117,125,0.22); }

        .tv-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        /* ── Filter bar ───────────────────────────── */
        .filter-bar {
            background: #ffffff;
            border: 1px solid rgba(189,126,46,0.14);
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        .filter-bar .form-select,
        .filter-bar .form-control {
            background: #faf8f5 !important;
            border: 1px solid rgba(189,126,46,0.2) !important;
            color: #1a1a1a !important;
            border-radius: 7px !important;
            font-size: 13px !important;
            padding: 6px 10px !important;
            height: auto !important;
        }

        .filter-bar .form-select:focus,
        .filter-bar .form-control:focus {
            border-color: rgba(189,126,46,0.5) !important;
            box-shadow: none !important;
            outline: none !important;
        }

        .filter-bar .btn-tv {
            background: linear-gradient(135deg, var(--tv-gold), var(--tv-gold-light));
            border: none;
            border-radius: 7px;
            color: #ffffff;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 16px;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .filter-bar .btn-tv:hover { opacity: 0.9; }

        .btn-tv {
            background: linear-gradient(135deg, var(--tv-gold), var(--tv-gold-light));
            border: none;
            border-radius: 7px;
            color: #ffffff;
            font-weight: 600;
            font-size: 13px;
            padding: 8px 18px;
            cursor: pointer;
            transition: opacity 0.2s;
            display: inline-flex;
            align-items: center;
        }

        .btn-tv:hover { opacity: 0.9; }

        .btn-tv-outline {
            background: transparent;
            border: 1px solid var(--tv-gold);
            border-radius: 7px;
            color: var(--tv-gold);
            font-weight: 600;
            font-size: 12px;
            padding: 4px 12px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
        }

        .btn-tv-outline:hover {
            background: var(--tv-gold);
            color: #ffffff;
        }

        /* ── Pagination ───────────────────────────── */
        .pagination { gap: 3px; flex-wrap: wrap; }
        .pagination .page-item .page-link {
            background: #ffffff !important;
            border-color: rgba(189,126,46,0.2) !important;
            color: #4b5563 !important;
            border-radius: 6px !important;
            font-size: 12px !important;
            padding: 4px 9px !important;
        }
        .pagination .page-item.active .page-link {
            background: var(--tv-gold) !important;
            border-color: var(--tv-gold) !important;
            color: #ffffff !important;
            font-weight: 700 !important;
        }
        .pagination .page-item.disabled .page-link {
            opacity: 0.4 !important;
        }

        /* ── Alert Toast ──────────────────────────── */
        .alert-success-tv {
            background: rgba(40,167,69,0.08);
            border: 1px solid rgba(40,167,69,0.24);
            color: #1a7835;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
        }
        .alert-error-tv {
            background: rgba(220,53,69,0.08);
            border: 1px solid rgba(220,53,69,0.24);
            color: #c82333;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
        }

        /* ── Section header ───────────────────────── */
        .section-heading {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 2px;
        }
        .section-sub {
            font-size: 13px;
            color: #4b5563;
            font-weight: 500;
        }

        /* Gold border left accent */
        .border-gold { border-left: 3px solid var(--tv-gold) !important; }

        /* ── tv-table — no DataTable chrome ──────────── */
        .tv-table th { cursor: default !important; }
        .tv-table th::after,
        .tv-table th::before { display: none !important; }

        /* ── Footer ───────────────────────────────── */
        .page-footer {
            background: #ffffff !important;
            border-top: 1px solid rgba(189,126,46,0.1) !important;
            color: #6b7280 !important;
            font-size: 12px !important;
            padding: 12px 24px !important;
        }

        .page-footer p {
            color: #6b7280 !important;
            margin: 0 !important;
        }

        /* ── Reusable form input / label ──────────── */
        .tv-input {
            width: 100%;
            background: #faf8f5;
            border: 1px solid rgba(189,126,46,0.22);
            border-radius: 7px;
            color: #1a1a1a;
            font-size: 13px;
            padding: 8px 12px;
            outline: none;
            transition: border-color 0.2s;
        }

        .tv-input:focus { border-color: rgba(189,126,46,0.55); }

        .tv-label {
            font-size: 11px;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        /* ── Mobile hamburger ─────────────────────── */
        .mobile-toggle-menu i {
            color: #4b5563 !important;
            font-size: 24px !important;
        }

        /* ── Topbar logo on mobile ─────────────────── */
        @media (max-width: 991px) {
            .topbar .navbar { gap: 0 !important; }
        }
    </style>
</head>
