<?php

use App\Http\Controllers\Api\Admin\TransferController;
use App\Http\Controllers\Api\AppConfigController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentSheetController;
use App\Http\Controllers\Api\PrivacyPolicyController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\StripeController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\TransactionHistoryController;
use Illuminate\Support\Facades\Route;

// test comment
// Public Authentication Routes with Rate Limiting
// Rate limits: Signup/Login/Verify/Reset = 5/min, Resend/Forgot = 3/min, Check Email = 10/min
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/check-email', [AuthController::class, 'checkEmail'])->name('check-email')->middleware('throttle:10,1,check-email');
    Route::post('/signup', [AuthController::class, 'signup'])->name('signup')->middleware('throttle:5,1,signup');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->name('verify-otp')->middleware('throttle:5,1,verify-otp');
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->name('resend-otp')->middleware('throttle:3,1,resend-otp');
    Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:5,1,login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password')->middleware('throttle:5,1,forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('reset-password')->middleware('throttle:5,1,reset-password');

});

// Public Privacy Policy Routes (Mobile View - Read-Only)
Route::get('/privacy-policy', [PrivacyPolicyController::class, 'active'])
    ->name('privacy-policy.active')
    ->middleware('throttle:60,1');

// Protected Routes (Require Authentication)
// Rate limit: 1000 requests per 1 minute per user
Route::middleware(['auth:sanctum', 'throttle:1000,1'])->group(function () {

    // Authentication
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/delete-account', [AuthController::class, 'deleteAccount'])
            ->name('delete-account')
            ->middleware('throttle:5,60,delete-account'); // 5 attempts per hour (sensitive)
    });

    // Profile Routes
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        // Use POST for update to support file uploads properly
        Route::post('/update', [ProfileController::class, 'update'])->name('update');
        Route::post('/change-password', [AuthController::class, 'changePassword'])->name('change-password')->middleware('throttle:5,1,change-password');
        Route::post('/language', [ProfileController::class, 'updateLanguage'])->name('language');
        Route::post('/timezone', [ProfileController::class, 'updateTimezone'])->name('timezone');
        Route::post('/feedback', [FeedbackController::class, 'submitFeedback'])->name('feedback')->middleware('throttle:10,1,feedback');
        Route::post('/support', [SupportController::class, 'submitSupport'])->name('support')->middleware('throttle:10,1,support');
    });

    // Notification Settings Routes
    Route::prefix('notification-settings')->name('notification-settings.')->group(function () {
        Route::get('/', [NotificationController::class, 'show'])->name('show');
        Route::post('/update', [NotificationController::class, 'update'])->name('update');
    });

    // App Config Routes
    Route::get('/refer-friend-url', [AppConfigController::class, 'getReferFriendUrl'])->name('refer-friend-url');

    // Stripe Payment Sheet Routes
    Route::prefix('stripe')->name('stripe.')->group(function () {
        Route::post('/create-payment-intent', [PaymentSheetController::class, 'createPaymentIntent'])->name('create-payment-intent')->middleware('quota:lock');
        Route::post('/confirm-payment', [PaymentSheetController::class, 'confirmPayment'])->name('confirm-payment');
    });

    // Subscription Routes (free tier: 2 locks + 2 withdrawals, then a plan is required)
    Route::prefix('subscription')->name('subscription.')->group(function () {
        Route::get('/plans', [SubscriptionController::class, 'plans'])->name('plans');
        Route::get('/status', [SubscriptionController::class, 'status'])->name('status');
        Route::post('/subscribe', [SubscriptionController::class, 'subscribe'])->name('subscribe')->middleware('throttle:10,1,subscribe');
        Route::post('/confirm', [SubscriptionController::class, 'confirm'])->name('confirm')->middleware('throttle:30,1,subscription-confirm');
        Route::post('/cancel', [SubscriptionController::class, 'cancel'])->name('cancel')->middleware('throttle:10,1,subscription-cancel');
        Route::post('/portal', [SubscriptionController::class, 'portal'])->name('portal')->middleware('throttle:10,1,subscription-portal');
    });

    // Bank Account Routes
    Route::prefix('bank-account')->name('bank-account.')->group(function () {
        Route::post('/', [BankAccountController::class, 'store'])->name('store');
        Route::get('/', [BankAccountController::class, 'show'])->name('show');
        Route::delete('/{id}', [BankAccountController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/set-primary', [BankAccountController::class, 'setPrimary'])->name('set-primary');
    });

    // Payment Holds Routes (User)
    Route::prefix('payment-holds')->name('payment-holds.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\PaymentHoldController::class, 'index'])->name('index');
        Route::get('/summary', [\App\Http\Controllers\Api\PaymentHoldController::class, 'summary'])->name('summary');
        Route::post('/{hold_id}/request-payout', [\App\Http\Controllers\Api\PaymentHoldController::class, 'requestPayout'])->name('request-payout')->middleware('quota:withdraw');
        Route::post('/withdraw', [\App\Http\Controllers\Api\PaymentHoldController::class, 'withdraw'])->name('withdraw')->middleware('quota:withdraw');
    });

    // Transaction History Routes
    Route::prefix('transactions')->name('transactions.')->group(function () {
        Route::get('/all', [TransactionHistoryController::class, 'all'])->name('all');
        Route::get('/checkouts', [TransactionHistoryController::class, 'checkouts'])->name('checkouts');
        Route::get('/withdraws', [TransactionHistoryController::class, 'withdraws'])->name('withdraws');
        Route::get('/hold-amounts', [TransactionHistoryController::class, 'holdAmounts'])->name('hold-amounts');
        Route::get('/ready-for-transfer', [TransactionHistoryController::class, 'readyForTransfer'])->name('ready-for-transfer');
    });

    // Admin Routes
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('/transfer/{hold_id}', [TransferController::class, 'transfer'])->name('transfer');
    });
});

// Webhook Route (NO AUTH - Stripe calls this)
Route::post('/stripe/webhook', [StripeController::class, 'handleWebhook'])
    ->name('stripe.webhook')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
