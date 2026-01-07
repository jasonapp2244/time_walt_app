<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Protected Routes (Require Authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Authentication
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Profile Routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/update', [ProfileController::class, 'update']);
        Route::put('/language', [ProfileController::class, 'updateLanguage']);
        Route::put('/timezone', [ProfileController::class, 'updateTimezone']);
    });

    // Device Routes
    Route::prefix('device')->group(function () {
        Route::post('/register', [DeviceController::class, 'register']);
        Route::put('/update-token', [DeviceController::class, 'updateToken']);
    });

    // Notification Settings Routes
    Route::prefix('notification-settings')->group(function () {
        Route::get('/', [NotificationController::class, 'show']);
        Route::put('/update', [NotificationController::class, 'update']);
    });
});
