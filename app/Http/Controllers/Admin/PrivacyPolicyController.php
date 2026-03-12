<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PrivacyPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrivacyPolicyController extends Controller
{
    /**
     * Show the active privacy policy editor.
     */
    public function index(): View
    {
        $policy = PrivacyPolicy::where('is_active', true)->latest()->first();

        return view('admin.privacy-policy.index', compact('policy'));
    }

    /**
     * Create or update the active privacy policy.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'effective_date' => ['required', 'date'],
        ]);

        PrivacyPolicy::where('is_active', true)->update(['is_active' => false]);

        PrivacyPolicy::create([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'effective_date' => $validated['effective_date'],
            'is_active' => true,
        ]);

        return redirect()->route('admin.privacy-policy.index')
            ->with('success', 'Privacy policy updated successfully.');
    }
}
