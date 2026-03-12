<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Show the admin profile page.
     */
    public function show(): View
    {
        return view('admin.profile', ['admin' => Auth::user()]);
    }

    /**
     * Update the admin's name and email.
     */
    public function update(Request $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::user();

        $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
        ]);

        // Check if another user already owns the new email (via blind index)
        $emailIndex = User::blindIndex(strtolower($request->email));

        $conflict = User::where('email_index', $emailIndex)
            ->where('id', '!=', $admin->id)
            ->exists();

        if ($conflict) {
            return back()->withErrors(['email' => 'That email address is already in use.'])->withInput();
        }

        $admin->full_name = $request->full_name;
        $admin->email = $request->email;
        $admin->save();

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Change the admin's password.
     */
    public function changePassword(Request $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'new_password.confirmed' => 'The confirm password does not match.',
            'new_password.min' => 'New password must be at least 8 characters.',
        ]);

        if (! Hash::check($request->current_password, $admin->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.'])->withInput();
        }

        $admin->password = Hash::make($request->new_password);
        $admin->save();

        return back()->with('password_success', 'Password changed successfully.');
    }
}
