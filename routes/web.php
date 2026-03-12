<?php

use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentHoldController;
use App\Http\Controllers\Admin\PrivacyPolicyController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\TransferController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Stripe Connect OAuth Redirect Routes
Route::get('/stripe/return', function () {
    $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

    if ($frontendUrl) {
        return redirect("{$frontendUrl}?onboarding=success");
    }

    return response()->json([
        'success' => true,
        'message' => 'Stripe Connect onboarding completed successfully.',
    ]);
})->name('stripe.return');

Route::get('/stripe/reauth', function () {
    $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

    if ($frontendUrl) {
        return redirect("{$frontendUrl}?onboarding=retry");
    }

    return response()->json([
        'success' => false,
        'message' => 'Stripe Connect onboarding needs to be completed. Please try again.',
    ]);
})->name('stripe.reauth');

// ─── Admin Auth ───────────────────────────────────────────────────────────────
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminLoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminLoginController::class, 'login'])->name('login.post');
    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('logout');

    // ─── Protected Admin Routes ───────────────────────────────────────────────
    Route::middleware('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Users
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

        // Payment Holds
        Route::get('/payment-holds', [PaymentHoldController::class, 'index'])->name('payment-holds.index');

        // Payments
        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');

        // Transfers
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::post('/transfers/{hold}/execute', [TransferController::class, 'execute'])->name('transfers.execute');

        // Privacy Policy
        Route::get('/privacy-policy', [PrivacyPolicyController::class, 'index'])->name('privacy-policy.index');
        Route::post('/privacy-policy', [PrivacyPolicyController::class, 'store'])->name('privacy-policy.store');

        // Admin Profile
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
        Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/change-password', [ProfileController::class, 'changePassword'])->name('profile.change-password');
    });
});
