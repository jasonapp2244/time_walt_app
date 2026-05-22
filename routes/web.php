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

// ─── Admin Auth ───────────────────────────────────────────────────────────────
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminLoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminLoginController::class, 'login'])->name('login.post');
    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('logout');

    // ─── Protected Admin Routes ───────────────────────────────────────────────
    Route::middleware('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');

        // Users
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/stats', [UserController::class, 'stats'])->name('users.stats');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::get('/users/{user}/stats', [UserController::class, 'userStats'])->name('users.stats.show');

        // Payment Holds
        Route::get('/payment-holds', [PaymentHoldController::class, 'index'])->name('payment-holds.index');
        Route::get('/payment-holds/stats', [PaymentHoldController::class, 'stats'])->name('payment-holds.stats');

        // Payments
        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/stats', [PaymentController::class, 'stats'])->name('payments.stats');

        // Transfers
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/stats', [TransferController::class, 'stats'])->name('transfers.stats');
        Route::post('/transfers/{hold}/execute', [TransferController::class, 'execute'])->name('transfers.execute');
        
        // 
        // Privacy Policy
        Route::get('/privacy-policy', [PrivacyPolicyController::class, 'index'])->name('privacy-policy.index');
        Route::post('/privacy-policy', [PrivacyPolicyController::class, 'store'])->name('privacy-policy.store');

        // Admin Profile
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
        Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/change-password', [ProfileController::class, 'changePassword'])->name('profile.change-password');
    });
});
