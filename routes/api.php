<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public Authentication Routes with Rate Limiting
// Rate limits: Signup/Login/Verify/Reset = 5/min, Resend/Forgot = 3/min
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup'])->name('signup')->middleware('throttle:5,1');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->name('verify-otp')->middleware('throttle:5,1');
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->name('resend-otp')->middleware('throttle:3,1');
    Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:5,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password')->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('reset-password')->middleware('throttle:5,1');
});

// Protected Routes (Require Authentication)
// Rate limit: 60 requests per minute for authenticated users
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    // Authentication
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });

    // Profile Routes
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        // Use POST for update to support file uploads properly
        Route::post('/update', [ProfileController::class, 'update'])->name('update');
        Route::post('/language', [ProfileController::class, 'updateLanguage'])->name('language');
        Route::post('/timezone', [ProfileController::class, 'updateTimezone'])->name('timezone');
    });

    // Notification Settings Routes
    Route::prefix('notification-settings')->name('notification-settings.')->group(function () {
        Route::get('/', [NotificationController::class, 'show'])->name('show');
        Route::post('/update', [NotificationController::class, 'update'])->name('update');
    });
});
