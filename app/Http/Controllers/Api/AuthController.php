<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Mail\AccountDeletionConfirmationMail;
use App\Mail\AdminAccountDeletionNotificationMail;
use App\Mail\OtpMail;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * User signup with OTP generation.
     */
    public function signup(SignupRequest $request): JsonResponse
    {
        $otpCode = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

        $user = User::create([
            'full_name' => $request->full_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'otp_code' => $otpCode,
            'otp_expires_at' => now()->addMinutes(5),
            'is_verified' => false,
            'status' => 'pending',
            'provider' => $request->provider,
            'provider_id' => $request->provider_id,
        ]);

        // Send OTP via email
        Mail::to($user->email)->send(new OtpMail($otpCode, 'verification'));

        // Create default notification settings
        UserNotificationSetting::create([
            'user_id' => $user->id,
            'password_alert' => true,
            'transaction_alert' => true,
            'push_notification_alert' => true,
            'email_alert' => true,
            'lock_alert' => true,
            'unlock_alert' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Please verify your OTP.',
            'data' => [
                'user' => $this->formatUser($user),
                'otp_sent' => true,
            ],
        ], 201);
    }

    /**
     * Verify OTP code.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = User::where(function ($query) use ($request) {
            if ($request->email) {
                $query->where('email', $request->email);
            } else {
                $query->where('phone', $request->phone);
            }
        })->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        // Check if account is deleted
        if ($user->status === 'deleted') {
            return response()->json([
                'success' => false,
                'message' => 'This account has been deleted. Please sign up again to create a new account.',
                'can_signup' => true,
            ], 403);
        }

        if ($user->otp_code !== $request->otp_code) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP code.',
            ], 400);
        }

        if ($user->otp_expires_at && $user->otp_expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'OTP code has expired.',
            ], 400);
        }

        $user->update([
            'is_verified' => true,
            'status' => 'active',
            'email_verified_at' => now(),
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully.',
            'data' => [
                'user' => $this->formatUser($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Resend OTP code.
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $user = User::where(function ($query) use ($request) {
            if ($request->email) {
                $query->where('email', $request->email);
            } else {
                $query->where('phone', $request->phone);
            }
        })->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        // Only allow resend OTP for unverified users
        if ($user->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is already verified.',
            ], 400);
        }

        $otpCode = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

        $user->update([
            'otp_code' => $otpCode,
            'is_verified' => false,
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        Mail::to($user->email)->send(new OtpMail($otpCode, 'verification'));

        return response()->json([
            'success' => true,
            'message' => 'OTP code has been resent.',
            'data' => [
                'otp_sent' => true,
            ],
        ]);
    }

    /**
     * User login with email/phone and password, optional OTP 2FA, and social login support.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Handle social login
        if ($request->provider && $request->provider_token && $request->provider_id) {
            return $this->handleSocialLogin($request);
        }

        // Step 1: Check if user exists
        $user = User::where(function ($query) use ($request) {
            if ($request->email) {
                $query->where('email', $request->email);
            } else {
                $query->where('phone', $request->phone);
            }
        })->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // Step 2: Check if account is deleted
        if ($user->status === 'deleted') {
            return response()->json([
                'success' => false,
                'message' => 'This account has been deleted. Please sign up again to create a new account.',
                'can_signup' => true,
            ], 403);
        }

        // Step 3: Verify password
        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // Step 4: Check if user is verified (OTP must be verified)
        if (! $user->is_verified && $user->otp_code !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your account with OTP first.',
                'requires_verification' => true,
            ], 403);
        }

        // Step 5: Check account status
        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not active.',
            ], 403);
        }

        // Check if 2FA is enabled and OTP is provided
        if ($user->two_factor_enabled) {
            if (! $request->otp_code) {
                // Generate and send OTP for 2FA
                $otpCode = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                $user->update([
                    'otp_code' => $otpCode,
                    'otp_expires_at' => now()->addMinutes(5),
                ]);

                Mail::to($user->email)->send(new OtpMail($otpCode, 'login'));

                return response()->json([
                    'success' => false,
                    'message' => '2FA enabled. Please enter OTP code.',
                    'requires_otp' => true,
                ], 200);
            }

            // Verify OTP for 2FA
            if ($user->otp_code !== $request->otp_code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid OTP code.',
                ], 400);
            }

            if ($user->otp_expires_at && $user->otp_expires_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'OTP code has expired.',
                ], 400);
            }

            $user->update([
                'otp_code' => null,
                'otp_expires_at' => null,
            ]);
        }

        // Update device info and last active (store device info on every login)
        $updateData = [
            'last_active_at' => now(),
        ];

        // Update device fields if provided
        if ($request->filled('device_id')) {
            $updateData['device_id'] = $request->device_id;
        }

        if ($request->filled('device_type')) {
            $updateData['device_type'] = $request->device_type;
        }

        if ($request->filled('fcm_token')) {
            $updateData['fcm_token'] = $request->fcm_token;
        }

        // Update timezone and language if provided
        if ($request->filled('timezone')) {
            $updateData['timezone'] = $request->timezone;
        }

        if ($request->filled('language')) {
            $updateData['language'] = $request->language;
        }

        $user->update($updateData);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => $this->formatUser($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Handle social login.
     */
    protected function handleSocialLogin(LoginRequest $request): JsonResponse
    {
        // Validate required social login fields
        if (! $request->provider || ! $request->provider_id || ! $request->email) {
            return response()->json([
                'success' => false,
                'message' => 'Provider, provider_id, and email are required for social login.',
            ], 400);
        }

        // Step 1: Check if user exists with this social provider
        $user = User::where('provider', $request->provider)
            ->where('provider_id', $request->provider_id)
            ->first();

        if ($user) {
            // Check if account is deleted
            if ($user->status === 'deleted') {
                return response()->json([
                    'success' => false,
                    'message' => 'This account has been deleted. Please sign up again to create a new account.',
                    'can_signup' => true,
                ], 403);
            }

            // Existing social login user - verify account status
            if ($user->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is not active.',
                ], 403);
            }

            // Update device info
            $user->update([
                'device_id' => $request->device_id ?? $user->device_id,
                'device_type' => $request->device_type ?? $user->device_type,
                'fcm_token' => $request->fcm_token ?? $user->fcm_token,
                'timezone' => $request->timezone ?? $user->timezone,
                'language' => $request->language ?? $user->language,
                'last_active_at' => now(),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Social login successful.',
                'data' => [
                    'user' => $this->formatUser($user),
                    'token' => $token,
                ],
            ]);
        }

        // Step 2: Check if user exists by email (link social account)
        $user = User::where('email', $request->email)->first();

        if ($user) {
            // User exists but not with this social provider
            // Check if account is deleted
            if ($user->status === 'deleted') {
                return response()->json([
                    'success' => false,
                    'message' => 'This account has been deleted. Please sign up again to create a new account.',
                    'can_signup' => true,
                ], 403);
            }

            // Check if user is verified
            if (! $user->is_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please verify your account with OTP first before linking social account.',
                    'requires_verification' => true,
                ], 403);
            }

            // Link social account to existing verified user
            $user->update([
                'provider' => $request->provider,
                'provider_id' => $request->provider_id,
                'device_id' => $request->device_id ?? $user->device_id,
                'device_type' => $request->device_type ?? $user->device_type,
                'fcm_token' => $request->fcm_token ?? $user->fcm_token,
                'timezone' => $request->timezone ?? $user->timezone,
                'language' => $request->language ?? $user->language,
                'last_active_at' => now(),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Social account linked successfully.',
                'data' => [
                    'user' => $this->formatUser($user),
                    'token' => $token,
                ],
            ]);
        }

        // Step 3: Create new user from social login (auto-verified for trusted providers)
        $user = User::create([
            'full_name' => $request->name ?? 'User',
            'email' => $request->email,
            'phone' => $this->generateUniquePhone(),
            'password' => Hash::make(Str::random(32)),
            'is_verified' => true, // Social login users are auto-verified
            'email_verified_at' => now(),
            'status' => 'active',
            'provider' => $request->provider,
            'provider_id' => $request->provider_id,
            'device_id' => $request->device_id,
            'device_type' => $request->device_type,
            'fcm_token' => $request->fcm_token,
            'timezone' => $request->timezone ?? 'UTC',
            'language' => $request->language ?? 'en',
            'last_active_at' => now(),
        ]);

        // Create default notification settings
        UserNotificationSetting::create([
            'user_id' => $user->id,
            'password_alert' => true,
            'transaction_alert' => true,
            'push_notification_alert' => true,
            'email_alert' => true,
            'lock_alert' => true,
            'unlock_alert' => true,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Social login successful.',
            'data' => [
                'user' => $this->formatUser($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Generate unique phone number.
     */
    protected function generateUniquePhone(): string
    {
        do {
            $phone = '1' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (User::where('phone', $phone)->exists());

        return $phone;
    }

    /**
     * Forgot password - send OTP.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where(function ($query) use ($request) {
            if ($request->email) {
                $query->where('email', $request->email);
            } else {
                $query->where('phone', $request->phone);
            }
        })->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        // Check if account is deleted
        if ($user->status === 'deleted') {
            return response()->json([
                'success' => false,
                'message' => 'This account has been deleted. Please sign up again to restore your account.',
                'can_signup' => true,
            ], 403);
        }

        $otpCode = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $token = Str::random(64);

        $user->update([
            'otp_code' => $otpCode,
            'otp_expires_at' => now()->addMinutes(5),
            'token' => $token,
            'is_verified' => false,
            'status' => 'pending',
            'expires_at' => now()->addHours(1),
        ]);

        Mail::to($user->email)->send(new OtpMail($otpCode, 'password_reset'));

        return response()->json([
            'success' => true,
            'message' => 'OTP code has been sent to your email.',
            'data' => [
                'otp_sent' => true,
            ],
        ]);
    }

    /**
     * Reset password with OTP verification.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::where(function ($query) use ($request) {
            if ($request->email) {
                $query->where('email', $request->email);
            } else {
                $query->where('phone', $request->phone);
            }
        })->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        if ($user->otp_code !== null) {
            return response()->json([
                'success' => false,
                'message' => 'please first otp verify your account',
            ], 400);
        }

        if ($user->otp_expires_at && $user->otp_expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'OTP code has expired.',
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'otp_code' => null,
            'otp_expires_at' => null,
            'token' => null,
            'expires_at' => null,
            'status' => 'active',
            'is_verified' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully.',
        ]);
    }

    /**
     * User logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Delete user account permanently (App Store & Play Store Compliant).
     *
     * Complies with:
     * - Apple App Store Guidelines 5.1.1(v)
     * - Google Play Data Safety
     * - GDPR Article 17 (Right to Erasure)
     *
     * Process:
     * 1. Transfer remaining balance to admin
     * 2. Mark all transactions as "abandoned"
     * 3. Anonymize user account (frees email/phone)
     * 4. Revoke all tokens (logout all devices)
     * 5. Set status to 'deleted'
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Optional: Verify password for extra security
            // if ($request->has('password')) {
            //     if (! Hash::check($request->password, $user->password)) {
            //         return response()->json([
            //             'success' => false,
            //             'message' => 'Invalid password confirmation.',
            //         ], 401);
            //     }
            // }

            // Store original user data before anonymization (for emails)
            $originalEmail = $user->email;
            $originalName = $user->full_name;
            $originalPhone = $user->phone;
            $userId = $user->id;

            DB::beginTransaction();

            try {
                // Step 1: Calculate and transfer remaining balance to admin
                $totalBalance = $this->calculateUserBalance($user->id);

                if ($totalBalance > 0) {
                    $this->transferBalanceToAdmin($user->id, $totalBalance);
                }

                // Step 2: Get transaction counts (before marking as abandoned)
                $paymentHoldsCount = PaymentHold::where('user_id', $user->id)->count();
                $transfersCount = Transfer::where('user_id', $user->id)->count();
                $totalTransactionCount = $paymentHoldsCount + $transfersCount;

                // Step 3: Mark all payment holds as abandoned (keep original status, just set abandoned_at)
                PaymentHold::where('user_id', $user->id)
                    ->update([
                        'abandoned_at' => now(),
                    ]);

                // Step 4: Mark all transfers as abandoned (keep original status, just set abandoned_at)
                Transfer::where('user_id', $user->id)
                    ->update([
                        'abandoned_at' => now(),
                    ]);

                // Step 5: Revoke all tokens (logout from all devices)
                $user->tokens()->delete();

                // Step 6: Anonymize user account (GDPR compliant)
                $timestamp = time();
                $deletedAt = now();
                $user->update([
                    // Anonymize PII (frees email/phone for reuse)
                    'email' => "deleted_{$user->id}_{$timestamp}@deleted.local",
                    'phone' => "deleted_{$user->id}_{$timestamp}",
                    'full_name' => 'Deleted User',
                    'profile' => 'deleted.png',

                    // Make account inaccessible
                    'password' => Hash::make(Str::random(64)),
                    'status' => 'deleted',
                    'is_verified' => false,

                    // Clear sensitive data
                    'provider' => null,
                    'provider_id' => null,
                    'fcm_token' => null,
                    'device_id' => null,
                    'device_type' => null,
                    'otp_code' => null,
                    'otp_expires_at' => null,
                    'token' => null,
                    'expires_at' => null,

                    // Mark deletion timestamp
                    'deleted_at' => $deletedAt,
                ]);

                DB::commit();

                // Step 7: Send email notifications (after successful deletion)
                try {
                    // Send confirmation email to user
                    Mail::to($originalEmail)->send(new AccountDeletionConfirmationMail(
                        userName: $originalName,
                        userEmail: $originalEmail,
                        balanceTransferred: $totalBalance,
                        deletedAt: $deletedAt->format('F d, Y \a\t g:i A'),
                        transactionCount: $totalTransactionCount
                    ));

                    // Send notification email to admin
                    $admin = User::where('role', 'admin')->first();
                    if ($admin && $admin->email) {
                        Mail::to($admin->email)->send(new AdminAccountDeletionNotificationMail(
                            userId: $userId,
                            userName: $originalName,
                            userEmail: $originalEmail,
                            userPhone: $originalPhone,
                            forfeitedAmount: $totalBalance,
                            deletedAt: $deletedAt->format('F d, Y \a\t g:i A'),
                            paymentHoldsCount: $paymentHoldsCount,
                            transfersCount: $transfersCount
                        ));
                    }
                } catch (\Exception $emailError) {
                    // Log email error but don't fail the deletion
                    Log::warning('Failed to send account deletion emails', [
                        'user_id' => $userId,
                        'error' => $emailError->getMessage(),
                    ]);
                }

                Log::info('User account deleted successfully', [
                    'user_id' => $userId,
                    'original_email' => $originalEmail,
                    'balance_transferred' => $totalBalance,
                    'deleted_at' => $deletedAt,
                    'emails_sent' => true,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Your account has been permanently deleted. A confirmation email has been sent to your registered email address.',
                    'data' => [
                        'deleted_at' => $deletedAt->toIso8601String(),
                        'balance_transferred' => (float) $totalBalance,
                        'transactions_affected' => $totalTransactionCount,
                        'data_retention_notice' => 'Transaction records are retained for 7 years as required by financial regulations.',
                        'transaction_status' => 'All your transactions have been marked with abandonment timestamp.',
                        'can_recreate_account' => true,
                        'email_available' => true,
                        'confirmation_email_sent' => true,
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Account deletion failed', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete account. Please try again.',
            ], 500);
        }
    }

    /**
     * Calculate user's total available balance (includes ALL amounts - ready and holding).
     */
    protected function calculateUserBalance(int $userId): float
    {
        // Get ALL payment holds (including those in holding period)
        $allHolds = PaymentHold::where('user_id', $userId)
            ->whereIn('status', ['holding', 'ready_for_transfer', 'partial_transferred'])
            ->get();

        $totalBalance = 0;
        foreach ($allHolds as $hold) {
            $remaining = $hold->remaining_amount ?? $hold->amount;
            if ($remaining > 0) {
                $totalBalance += $remaining;
            }
        }

        return $totalBalance;
    }

    /**
     * Transfer user's balance to admin account (virtual transfer - no actual Stripe transfer).
     *
     * This method:
     * 1. Creates Transfer records showing admin received the funds
     * 2. Updates PaymentHold status to 'transferred'
     * 3. Does NOT make actual Stripe API calls (no fees, instant, reliable)
     * 4. Admin can track all forfeited funds from deleted accounts
     */
    protected function transferBalanceToAdmin(int $userId, float $amount): void
    {
        // Get admin user (assuming admin role exists)
        $admin = User::where('role', 'admin')->first();

        if (! $admin) {
            Log::warning('Admin user not found for balance transfer', [
                'user_id' => $userId,
                'amount' => $amount,
            ]);

            return;
        }

        // Get ALL holds with remaining balance (including those still in holding period)
        $holdsWithBalance = PaymentHold::where('user_id', $userId)
            ->whereIn('status', ['holding', 'ready_for_transfer', 'partial_transferred'])
            ->get();

        $totalTransferred = 0;

        // Create virtual transfer record for each hold (no actual Stripe transfer)
        foreach ($holdsWithBalance as $hold) {
            $remainingAmount = $hold->remaining_amount ?? $hold->amount;

            if ($remainingAmount > 0) {
                // Create transfer record (virtual - for record keeping only)
                Transfer::create([
                    'hold_id' => $hold->id,
                    'user_id' => $userId,
                    'admin_id' => $admin->id,
                    'amount' => $remainingAmount,
                    'currency' => 'usd',
                    'status' => 'completed', // Marked as completed (no actual transfer needed)
                    'transfer_type' => 'account_deletion_forfeited', // Clear type for admin tracking
                    'transferred_at' => now(),
                    'stripe_transfer_id' => null, // No actual Stripe transfer
                    'stripe_connect_account_id' => $admin->stripe_connect_account_id ?? 'ADMIN_FORFEITED', // Use admin's or placeholder
                    'failure_reason' => null,
                ]);

                // Update hold status to transferred (funds forfeited to admin)
                $hold->update([
                    'status' => 'transferred',
                    'remaining_amount' => 0,
                    'transferred_at' => now(),
                ]);

                $totalTransferred += $remainingAmount;
            }
        }

        Log::info('Balance forfeited to admin due to account deletion', [
            'user_id' => $userId,
            'admin_id' => $admin->id,
            'total_amount' => $totalTransferred,
            'holds_count' => $holdsWithBalance->count(),
            'transfer_type' => 'virtual_transfer', // No actual Stripe API call
        ]);
    }

    /**
     * Format user data for response.
     */
    protected function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'role' => $user->role,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'profile' => $user->profile,
            'is_verified' => $user->is_verified,
            'status' => $user->status,
            'two_factor_enabled' => $user->two_factor_enabled,
            'timezone' => $user->timezone,
            'language' => $user->language,
            'device_id' => $user->device_id,
            'device_type' => $user->device_type,
            'email_verified_at' => $user->email_verified_at,
            'last_active_at' => $user->last_active_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}

