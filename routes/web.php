<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Stripe Connect OAuth Redirect Routes
Route::get('/stripe/return', function () {
    // User successfully completed onboarding
    // Frontend ko JSON response ya redirect karo
    $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

    // Agar frontend URL set hai to redirect karo
    if ($frontendUrl) {
        return redirect("{$frontendUrl}?onboarding=success");
    }

    // Ya simple JSON response
    return response()->json([
        'success' => true,
        'message' => 'Stripe Connect onboarding completed successfully.',
    ]);
})->name('stripe.return');

Route::get('/stripe/reauth', function () {
    // User needs to retry onboarding
    $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

    if ($frontendUrl) {
        return redirect("{$frontendUrl}?onboarding=retry");
    }

    return response()->json([
        'success' => false,
        'message' => 'Stripe Connect onboarding needs to be completed. Please try again.',
    ]);
})->name('stripe.reauth');
