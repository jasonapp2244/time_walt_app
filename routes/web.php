<?php

use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeedbackController;
use App\Http\Controllers\Admin\FreeTierSettingsController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentHoldController;
use App\Http\Controllers\Admin\PrivacyPolicyController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SubscriptionPaymentController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\Admin\SupportController;
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
        Route::get('/users/export', [UserController::class, 'export'])->name('users.export');
        Route::get('/users/stats', [UserController::class, 'stats'])->name('users.stats');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::get('/users/{user}/stats', [UserController::class, 'userStats'])->name('users.stats.show');
        Route::post('/users/{user}/reset-free-usage', [UserController::class, 'resetFreeUsage'])->name('users.reset-free-usage');

        // Payment Holds
        Route::get('/payment-holds', [PaymentHoldController::class, 'index'])->name('payment-holds.index');
        Route::get('/payment-holds/export', [PaymentHoldController::class, 'export'])->name('payment-holds.export');
        Route::get('/payment-holds/stats', [PaymentHoldController::class, 'stats'])->name('payment-holds.stats');

        // Payments
        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/export', [PaymentController::class, 'export'])->name('payments.export');
        Route::get('/payments/stats', [PaymentController::class, 'stats'])->name('payments.stats');

        // Transfers
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/export', [TransferController::class, 'export'])->name('transfers.export');
        Route::get('/transfers/stats', [TransferController::class, 'stats'])->name('transfers.stats');
        Route::post('/transfers/{hold}/execute', [TransferController::class, 'execute'])->name('transfers.execute');

        // Feedback
        Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback.index');
        Route::get('/feedback/export', [FeedbackController::class, 'export'])->name('feedback.export');
        Route::get('/feedback/stats', [FeedbackController::class, 'stats'])->name('feedback.stats');

        // Support
        Route::get('/support', [SupportController::class, 'index'])->name('support.index');
        Route::get('/support/export', [SupportController::class, 'export'])->name('support.export');
        Route::get('/support/stats', [SupportController::class, 'stats'])->name('support.stats');

        // Subscriptions
        Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index'])->name('subscription-plans.index');
        Route::get('/subscription-plans/export', [SubscriptionPlanController::class, 'export'])->name('subscription-plans.export');
        Route::get('/subscription-plans/stats', [SubscriptionPlanController::class, 'stats'])->name('subscription-plans.stats');
        Route::get('/subscription-plans/create', [SubscriptionPlanController::class, 'create'])->name('subscription-plans.create');
        Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store'])->name('subscription-plans.store');
        Route::get('/subscription-plans/{subscriptionPlan}/edit', [SubscriptionPlanController::class, 'edit'])->name('subscription-plans.edit');
        Route::put('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'update'])->name('subscription-plans.update');
        Route::post('/subscription-plans/{subscriptionPlan}/toggle', [SubscriptionPlanController::class, 'toggle'])->name('subscription-plans.toggle');
        Route::delete('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'destroy'])->name('subscription-plans.destroy');

        Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('/subscriptions/export', [SubscriptionController::class, 'export'])->name('subscriptions.export');
        Route::get('/subscription-payments', [SubscriptionPaymentController::class, 'index'])->name('subscription-payments.index');
        Route::get('/subscription-payments/stats', [SubscriptionPaymentController::class, 'stats'])->name('subscription-payments.stats');
        Route::get('/subscription-payments/export', [SubscriptionPaymentController::class, 'export'])->name('subscription-payments.export');
        Route::get('/subscriptions/stats', [SubscriptionController::class, 'stats'])->name('subscriptions.stats');
        Route::post('/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancelAtPeriodEnd'])->name('subscriptions.cancel');
        Route::post('/subscriptions/{subscription}/cancel-now', [SubscriptionController::class, 'cancelNow'])->name('subscriptions.cancel-now');

        Route::get('/settings/free-tier', [FreeTierSettingsController::class, 'edit'])->name('settings.free-tier');
        Route::post('/settings/free-tier', [FreeTierSettingsController::class, 'update'])->name('settings.free-tier.update');

        // Privacy Policy
        Route::get('/privacy-policy', [PrivacyPolicyController::class, 'index'])->name('privacy-policy.index');
        Route::post('/privacy-policy', [PrivacyPolicyController::class, 'store'])->name('privacy-policy.store');

        // Admin Profile
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
        Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/change-password', [ProfileController::class, 'changePassword'])->name('profile.change-password');
    });
});
