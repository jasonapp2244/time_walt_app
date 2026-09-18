<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminLoginController extends Controller
{
    /**
     * Show the admin login form.
     */
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check() && Auth::user()?->role === 'admin') {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    /**
     * Attempt admin login.
     *
     * Cannot use Auth::attempt() because the email column is encrypted.
     * We look up the user via the HMAC blind index, then verify password
     * and role manually before calling Auth::login().
     */
    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // ADMIN_PANEL_EMAIL / ADMIN_PANEL_PASSWORD are the source of truth for
        // the panel account. When they match, the users row is rebuilt from
        // them before logging in - so restoring a database dump, or importing
        // rows encrypted under a different APP_KEY, can never lock the panel.
        if (AdminAccount::credentialsMatch($request->email, $request->password)) {
            if ($admin = AdminAccount::sync()) {
                Auth::login($admin, $request->boolean('remember'));
                $request->session()->regenerate();

                return redirect()->intended(route('admin.dashboard'));
            }
        }

        $user = User::where('email_index', User::blindIndex(strtolower($request->email)))->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->withInput($request->only('email'));
        }

        if ($user->role !== 'admin') {
            return back()->withErrors([
                'email' => 'You do not have admin access.',
            ])->withInput($request->only('email'));
        }

        if ($user->status !== 'active') {
            return back()->withErrors([
                'email' => 'This admin account is not active.',
            ])->withInput($request->only('email'));
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Log the admin out and redirect to login.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
