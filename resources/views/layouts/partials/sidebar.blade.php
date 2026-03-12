<!--sidebar wrapper -->
<div class="sidebar-wrapper" data-simplebar="true">
    <div class="sidebar-header">
        <div>
            <img src="{{ asset('admin/images/logo-bg_remove.png') }}"
                 class="logo-tv-img"
                 onerror="this.style.display='none'"
                 alt="Time Vault">
        </div>
        <div>
            <h4 class="logo-text">Time Vault</h4>
        </div>
        <div class="toggle-icon ms-auto">
            <i class='bx bx-arrow-back'></i>
        </div>
    </div>

    <!--navigation-->
    <ul class="metismenu" id="menu">

        <li class="{{ request()->routeIs('admin.dashboard') ? 'mm-active' : '' }}">
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                <div class="parent-icon"><i class='bx bxs-dashboard'></i></div>
                <div class="menu-title">Dashboard</div>
            </a>
        </li>

        <li class="{{ request()->routeIs('admin.users.*') ? 'mm-active' : '' }}">
            <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                <div class="parent-icon"><i class='bx bxs-user-account'></i></div>
                <div class="menu-title">Users</div>
            </a>
        </li>

        <li class="{{ request()->routeIs('admin.payment-holds.*') ? 'mm-active' : '' }}">
            <a href="{{ route('admin.payment-holds.index') }}" class="{{ request()->routeIs('admin.payment-holds.*') ? 'active' : '' }}">
                <div class="parent-icon"><i class='bx bxs-lock-alt'></i></div>
                <div class="menu-title">Payment Holds</div>
            </a>
        </li>

        <li class="{{ request()->routeIs('admin.payments.*') ? 'mm-active' : '' }}">
            <a href="{{ route('admin.payments.index') }}" class="{{ request()->routeIs('admin.payments.*') ? 'active' : '' }}">
                <div class="parent-icon"><i class='bx bxs-credit-card'></i></div>
                <div class="menu-title">Payments</div>
            </a>
        </li>

        <li class="{{ request()->routeIs('admin.transfers.*') ? 'mm-active' : '' }}">
            <a href="{{ route('admin.transfers.index') }}" class="{{ request()->routeIs('admin.transfers.*') ? 'active' : '' }}">
                <div class="parent-icon"><i class='bx bxs-send'></i></div>
                <div class="menu-title">Transfers</div>
            </a>
        </li>

        <li class="{{ request()->routeIs('admin.privacy-policy.*') ? 'mm-active' : '' }}">
            <a href="{{ route('admin.privacy-policy.index') }}" class="{{ request()->routeIs('admin.privacy-policy.*') ? 'active' : '' }}">
                <div class="parent-icon"><i class='bx bxs-shield-alt-2'></i></div>
                <div class="menu-title">Privacy Policy</div>
            </a>
        </li>

        <li class="{{ request()->routeIs('admin.profile*') ? 'mm-active' : '' }}"
            style="border-top: 1px solid rgba(189,126,46,0.12); margin-top: 12px; padding-top: 8px;">
            <a href="{{ route('admin.profile') }}" class="{{ request()->routeIs('admin.profile*') ? 'active' : '' }}">
                <div class="parent-icon"><i class='bx bxs-user-circle'></i></div>
                <div class="menu-title">My Profile</div>
            </a>
        </li>

        <li>
            <a href="{{ route('admin.logout') }}"
               onclick="event.preventDefault(); document.getElementById('admin-logout-form').submit();">
                <div class="parent-icon"><i class='bx bx-log-out-circle' style="color: #c82333 !important;"></i></div>
                <div class="menu-title" style="color: #c82333 !important;">Logout</div>
            </a>
        </li>

    </ul>
    <!--end navigation-->
</div>
<!--end sidebar wrapper -->

<form id="admin-logout-form" action="{{ route('admin.logout') }}" method="POST" style="display:none;">
    @csrf
</form>
