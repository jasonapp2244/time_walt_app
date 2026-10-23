<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\FreeTierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FreeTierSettingsController extends Controller
{
    public function edit(FreeTierService $freeTier): View
    {
        return view('admin.settings.free-tier', [
            'lockLimit' => $freeTier->limit(FreeTierService::LOCK),
            'withdrawLimit' => (int) AppSetting::read('free_withdraw_limit', config('app.free_withdraw_limit')),
            'withdrawLimitEnabled' => $freeTier->withdrawLimitEnabled(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'free_lock_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'free_withdraw_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'withdraw_limit_enabled' => ['nullable', 'boolean'],
        ]);

        AppSetting::write('free_lock_limit', (int) $validated['free_lock_limit']);
        AppSetting::write('free_withdraw_limit', (int) $validated['free_withdraw_limit']);
        AppSetting::write('withdraw_limit_enabled', $request->boolean('withdraw_limit_enabled'));

        return redirect()->route('admin.settings.free-tier')
            ->with('success', 'Free tier settings saved. They apply to the next request.');
    }
}
