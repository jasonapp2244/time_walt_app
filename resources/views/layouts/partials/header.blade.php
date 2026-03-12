<!--start header -->
<header>
    <div class="topbar d-flex align-items-center">
        <nav class="navbar navbar-expand gap-3">
            <div class="mobile-toggle-menu"><i class='bx bx-menu'></i></div>

            {{-- Logo: visible on mobile only (sidebar hidden on mobile) --}}
            <div class="d-flex d-lg-none align-items-center gap-2 ms-2">
                <img src="{{ asset('admin/images/logo-bg_remove.png') }}"
                     alt="Time Vault"
                     style="height:34px; width:auto; object-fit:contain;"
                     onerror="this.style.display='none'">
                <span style="font-size:14px; font-weight:700; color:#92621a; letter-spacing:0.5px;">Time Vault</span>
            </div>

            <div class="ms-auto d-flex align-items-center gap-2">
            </div>

            <div class="user-box dropdown px-3">
                <a class="d-flex align-items-center nav-link dropdown-toggle gap-3 dropdown-toggle-nocaret"
                    href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div style="width:36px; height:36px; border-radius:50%; background:rgba(189,126,46,0.12);
                                border:2px solid rgba(189,126,46,0.35); display:flex; align-items:center;
                                justify-content:center; font-weight:700; color:#bd7e2e; font-size:16px;">
                        {{ strtoupper(substr(auth()->user()->full_name ?? 'A', 0, 1)) }}
                    </div>
                    <div class="user-info">
                        <p class="user-name mb-0">{{ auth()->user()->full_name ?? 'Admin' }}</p>
                        <p class="designattion mb-0">Administrator</p>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" style="background:#ffffff; border:1px solid rgba(189,126,46,0.18); box-shadow:0 6px 20px rgba(0,0,0,0.08);">
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2"
                           href="#" style="color:rgba(0,0,0,0.45); font-size:12px; pointer-events:none;">
                            <i class="bx bx-envelope" style="color:#bd7e2e;"></i>
                            <span>{{ auth()->user()->email ?? '' }}</span>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2"
                           href="{{ route('admin.profile') }}" style="color:rgba(0,0,0,0.7); font-size:13px;">
                            <i class="bx bxs-user-circle" style="color:#bd7e2e;"></i>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider" style="border-color:rgba(189,126,46,0.12);"></li>
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2"
                           href="#"
                           onclick="event.preventDefault(); document.getElementById('admin-logout-form').submit();"
                           style="color:#c82333; font-size:13px;">
                            <i class="bx bx-log-out-circle"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</header>
<!--end header -->
