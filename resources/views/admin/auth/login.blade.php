<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Time Vault</title>
    <link href="{{ asset('admin/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/css/icons.css') }}" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold: #bd7e2e;
            --gold-light: #d4963e;
            --gold-pale: rgba(189,126,46,0.1);
            --bg: #f0ece4;
            --surface: #ffffff;
            --text: #1a1a1a;
            --text-muted: #6b7280;
            --border: rgba(189,126,46,0.18);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* ── Animated canvas ──────────────────────────── */
        #bg-canvas {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
        }

        /* ── Layout ───────────────────────────────────── */
        .split-left {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 56px 64px;
            position: relative;
            z-index: 1;
        }

        .split-right {
            width: 460px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 44px;
            background: #ffffff;
            border-left: 1px solid rgba(189,126,46,0.14);
            box-shadow: -4px 0 32px rgba(0,0,0,0.06);
            position: relative;
            z-index: 1;
        }

        /* ── Left branding ────────────────────────────── */
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 48px;
        }

        .brand-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: #fff;
            border: 1.5px solid rgba(189,126,46,0.22);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 900;
            color: var(--gold);
            box-shadow: 0 4px 14px rgba(189,126,46,0.15);
            overflow: hidden;
        }

        .brand-name {
            font-size: 19px;
            font-weight: 800;
            color: var(--text);
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .brand-sub {
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 1px;
        }

        .hero-heading {
            font-size: 44px;
            font-weight: 800;
            color: var(--text);
            line-height: 1.18;
            margin-bottom: 16px;
        }

        .hero-heading span { color: var(--gold); }

        .hero-sub {
            font-size: 15px;
            color: var(--text-muted);
            max-width: 440px;
            line-height: 1.7;
            margin-bottom: 48px;
        }

        /* ── Metric cards ─────────────────────────────── */
        .metrics { display: flex; gap: 14px; }

        .metric-card {
            background: #ffffff;
            border: 1px solid rgba(189,126,46,0.16);
            border-radius: 12px;
            padding: 16px 20px;
            min-width: 130px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            animation: floatUp 6s ease-in-out infinite;
        }

        .metric-card:nth-child(2) { animation-delay: 1.8s; }
        .metric-card:nth-child(3) { animation-delay: 3.6s; }

        @keyframes floatUp {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-7px); }
        }

        .metric-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 6px;
            font-weight: 500;
        }

        .metric-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
        }

        .metric-value.gold  { color: var(--gold); }
        .metric-value.green { color: #1e9c42; }

        .metric-trend {
            font-size: 11px;
            color: #1e9c42;
            margin-top: 4px;
            font-weight: 500;
        }

        /* ── Pulse live dot ───────────────────────────── */
        .live-dot {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 30px;
            font-weight: 500;
        }

        .live-dot::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #1e9c42;
            animation: pulse 2s infinite;
            flex-shrink: 0;
        }

        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgba(30,156,66,0.5); }
            70%  { box-shadow: 0 0 0 8px rgba(30,156,66,0); }
            100% { box-shadow: 0 0 0 0 rgba(30,156,66,0); }
        }

        /* ── Floating shapes (decorative) ─────────────── */
        .shape {
            position: fixed;
            border-radius: 50%;
            opacity: 0.07;
            background: var(--gold);
            pointer-events: none;
            z-index: 0;
        }

        .shape-1 { width: 320px; height: 320px; top: -80px; left: -60px; animation: drift1 14s ease-in-out infinite; }
        .shape-2 { width: 200px; height: 200px; bottom: 40px; left: 200px; animation: drift2 18s ease-in-out infinite; }
        .shape-3 { width: 100px; height: 100px; top: 40%; left: 38%; animation: drift1 10s ease-in-out infinite reverse; }

        @keyframes drift1 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50%       { transform: translate(20px, 24px) scale(1.06); }
        }
        @keyframes drift2 {
            0%, 100% { transform: translate(0, 0); }
            50%       { transform: translate(-18px, -20px); }
        }

        /* ── Right panel form ─────────────────────────── */
        .login-panel { width: 100%; }

        .panel-heading {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 3px;
        }

        .panel-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .gold-bar {
            width: 32px;
            height: 3px;
            border-radius: 2px;
            background: linear-gradient(90deg, var(--gold), var(--gold-light));
            margin-bottom: 24px;
        }

        /* Labels */
        .field-label {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 6px;
        }

        .field-wrap { margin-bottom: 16px; }

        .input-wrap { position: relative; }

        .field-input {
            width: 100%;
            background: #faf8f5;
            border: 1px solid rgba(189,126,46,0.2);
            border-radius: 8px;
            color: #1a1a1a;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            padding: 10px 14px 10px 40px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .field-input.has-toggle { padding-right: 40px; }
        .field-input::placeholder { color: rgba(0,0,0,0.25); }

        .field-input:focus {
            border-color: var(--gold);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(189,126,46,0.1);
        }

        .field-input.error { border-color: rgba(220,53,69,0.5); }

        .field-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gold);
            font-size: 16px;
            pointer-events: none;
        }

        .field-icon-right {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(0,0,0,0.25);
            font-size: 16px;
            cursor: pointer;
            transition: color 0.2s;
        }

        .field-icon-right:hover { color: var(--gold); }

        /* Error alert */
        .alert-err {
            background: rgba(220,53,69,0.07);
            border: 1px solid rgba(220,53,69,0.22);
            border-radius: 8px;
            color: #c82333;
            font-size: 12px;
            padding: 10px 13px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        /* Remember */
        .remember-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        .remember-row input[type="checkbox"] {
            width: 15px;
            height: 15px;
            accent-color: var(--gold);
            cursor: pointer;
        }

        .remember-row label {
            font-size: 12px;
            color: var(--text-muted);
            cursor: pointer;
        }

        /* Sign-in button */
        .btn-signin {
            width: 100%;
            padding: 11px;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            border: none;
            border-radius: 8px;
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.22s;
            box-shadow: 0 4px 18px rgba(189,126,46,0.28);
        }

        .btn-signin:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(189,126,46,0.38);
        }

        .btn-signin:active { transform: translateY(0); }

        /* Divider */
        .panel-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0 0;
        }

        .panel-divider::before,
        .panel-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(189,126,46,0.12);
        }

        .panel-divider span {
            font-size: 11px;
            color: rgba(0,0,0,0.25);
        }

        /* Footer */
        .panel-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 11px;
            color: rgba(0,0,0,0.25);
        }

        .panel-footer b { color: var(--gold); font-weight: 600; }

        /* ── Mobile logo block ────────────────────────── */
        .mobile-brand {
            display: none;
            align-items: center;
            gap: 14px;
            margin-bottom: 32px;
        }

        .mobile-brand img {
            width: 52px;
            height: 52px;
            object-fit: contain;
            border-radius: 12px;
            background: #fff;
            border: 1.5px solid rgba(189,126,46,0.22);
            padding: 4px;
            box-shadow: 0 3px 10px rgba(189,126,46,0.12);
        }

        .mobile-brand-name {
            font-size: 18px;
            font-weight: 800;
            color: var(--text);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .mobile-brand-sub {
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 2px;
        }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 768px) {
            body { flex-direction: column; overflow: auto; }
            .split-left { display: none; }
            .split-right {
                width: 100%;
                min-height: 100vh;
                border-left: none;
                box-shadow: none;
                padding: 40px 24px;
                justify-content: flex-start;
                padding-top: 48px;
            }
            .mobile-brand { display: flex; }
        }
    </style>
</head>
<body>

    {{-- Decorative floating shapes --}}
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
    <div class="shape shape-3"></div>

    {{-- Animated particle canvas --}}
    <canvas id="bg-canvas"></canvas>

    {{-- LEFT — Brand + Info --}}
    <div class="split-left">

        <div class="brand-logo">
            <div class="brand-icon">
                <img src="{{ asset('admin/images/logo-bg_remove.png') }}"
                     alt="Time Vault"
                     style="width:36px; height:36px; object-fit:contain;"
                     onerror="this.style.display='none'; this.parentElement.innerHTML='V';">
            </div>
            <div>
                <div class="brand-name">Time Vault</div>
                <div class="brand-sub">Fintech Platform</div>
            </div>
        </div>

        <h1 class="hero-heading">
            Secure &amp;<br>
            <span>Intelligent</span><br>
            Admin Control
        </h1>

        <p class="hero-sub">
            Full visibility into users, payments, vaults and transfers —
            all from one powerful command centre.
        </p>

        <div class="metrics">
            <div class="metric-card">
                <div class="metric-label">Total Vaults</div>
                <div class="metric-value gold">—</div>
                <div class="metric-trend">↑ Active</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Revenue</div>
                <div class="metric-value green">$—</div>
                <div class="metric-trend">↑ Processed</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Users</div>
                <div class="metric-value">—</div>
                <div class="metric-trend">↑ Registered</div>
            </div>
        </div>

        <div class="live-dot">System Online — All services operational</div>

    </div>

    {{-- RIGHT — Login form --}}
    <div class="split-right">

        {{-- Mobile-only logo (split-left is hidden on mobile) --}}
        <div class="mobile-brand">
            <img src="{{ asset('admin/images/logo-bg_remove.png') }}"
                 alt="Time Vault"
                 onerror="this.style.display='none'">
            <div>
                <div class="mobile-brand-name">Time Vault</div>
                <div class="mobile-brand-sub">Fintech Platform</div>
            </div>
        </div>

        <div class="login-panel">

            <div class="panel-heading">Welcome back</div>
            <div class="panel-sub">Sign in to your admin account</div>
            <div class="gold-bar"></div>

            {{-- Errors --}}
            @if($errors->any())
                <div class="alert-err">
                    <i class='bx bx-error-circle'></i>
                    {{ $errors->first() }}
                </div>
            @endif

            @if(session('error'))
                <div class="alert-err">
                    <i class='bx bx-error-circle'></i>
                    {{ session('error') }}
                </div>
            @endif

            <form method="POST" action="{{ route('admin.login.post') }}" novalidate>
                @csrf

                {{-- Email --}}
                <div class="field-wrap">
                    <label class="field-label" for="email">Email Address</label>
                    <div class="input-wrap">
                        <i class='bx bx-envelope field-icon'></i>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="field-input {{ $errors->has('email') ? 'error' : '' }}"
                            value="{{ old('email') }}"
                            placeholder="admin@timevault.com"
                            autocomplete="email"
                            autofocus
                            required
                        >
                    </div>
                </div>

                {{-- Password --}}
                <div class="field-wrap">
                    <label class="field-label" for="password">Password</label>
                    <div class="input-wrap">
                        <i class='bx bx-lock-alt field-icon'></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="field-input has-toggle {{ $errors->has('password') ? 'error' : '' }}"
                            placeholder="••••••••••"
                            autocomplete="current-password"
                            required
                        >
                        <i class='bx bx-hide field-icon-right' id="pwd-toggle" onclick="togglePwd()"></i>
                    </div>
                </div>

                {{-- Remember --}}
                <div class="remember-row">
                    <input type="checkbox" name="remember" id="remember">
                    <label for="remember">Keep me signed in</label>
                </div>

                <button type="submit" class="btn-signin">
                    <i class='bx bx-log-in me-2'></i>Sign In
                </button>
            </form>

            <div class="panel-divider"><span>Time Vault Admin Portal</span></div>

            <div class="panel-footer">
                &copy; {{ date('Y') }} <b>Time Vault</b>. All rights reserved.
            </div>

        </div>
    </div>

    <script>
        /* ── Light particle network on warm background ── */
        (function () {
            const canvas = document.getElementById('bg-canvas');
            const ctx    = canvas.getContext('2d');
            const W_FRAC = 0.62;

            let W, H, particles;

            function resize() {
                W = canvas.width  = window.innerWidth;
                H = canvas.height = window.innerHeight;
            }

            function Particle() { this.reset(); }

            Particle.prototype.reset = function () {
                this.x  = Math.random() * W * W_FRAC;
                this.y  = Math.random() * H;
                this.r  = Math.random() * 1.6 + 0.5;
                this.vx = (Math.random() - 0.5) * 0.35;
                this.vy = (Math.random() - 0.5) * 0.35;
                this.a  = Math.random() * 0.35 + 0.08;
            };

            Particle.prototype.update = function () {
                this.x += this.vx;
                this.y += this.vy;
                if (this.x < 0 || this.x > W * W_FRAC || this.y < 0 || this.y > H) {
                    this.reset();
                }
            };

            function init() {
                resize();
                const count = Math.floor((W * H) / 13000);
                particles = Array.from({ length: count }, () => new Particle());
            }

            function draw() {
                ctx.clearRect(0, 0, W, H);

                /* Connections */
                for (let i = 0; i < particles.length; i++) {
                    for (let j = i + 1; j < particles.length; j++) {
                        const dx   = particles[i].x - particles[j].x;
                        const dy   = particles[i].y - particles[j].y;
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 120) {
                            const alpha = (1 - dist / 120) * 0.12;
                            ctx.beginPath();
                            ctx.strokeStyle = `rgba(189,126,46,${alpha})`;
                            ctx.lineWidth   = 0.7;
                            ctx.moveTo(particles[i].x, particles[i].y);
                            ctx.lineTo(particles[j].x, particles[j].y);
                            ctx.stroke();
                        }
                    }
                }

                /* Dots */
                particles.forEach(p => {
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(189,126,46,${p.a})`;
                    ctx.fill();
                    p.update();
                });

                requestAnimationFrame(draw);
            }

            window.addEventListener('resize', () => { resize(); init(); });
            init();
            draw();
        })();

        /* ── Password toggle ──────────────────────────── */
        function togglePwd() {
            const inp  = document.getElementById('password');
            const icon = document.getElementById('pwd-toggle');
            if (inp.type === 'password') {
                inp.type       = 'text';
                icon.className = 'bx bx-show field-icon-right';
            } else {
                inp.type       = 'password';
                icon.className = 'bx bx-hide field-icon-right';
            }
        }
    </script>

</body>
</html>
