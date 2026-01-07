<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Mail\OtpMail;
use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        // Step 2: Verify password
        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // Step 3: Check if user is verified (OTP must be verified)
        if (! $user->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your account with OTP first.',
                'requires_verification' => true,
            ], 403);
        }

        // Step 4: Check account status
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

        // Update device info and last active
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

        $otpCode = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $token = Str::random(64);

        $user->update([
            'otp_code' => $otpCode,
            'otp_expires_at' => now()->addMinutes(5),
            'token' => $token,
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
            'password' => Hash::make($request->password),
            'otp_code' => null,
            'otp_expires_at' => null,
            'token' => null,
            'expires_at' => null,
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

