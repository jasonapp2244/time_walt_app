<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateLanguageRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UpdateTimezoneRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Get user profile with complete details.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        // Load notification settings if they exist
        $notificationSettings = null;
        if ($user->notificationSettings) {
            $notificationSettings = $this->formatNotificationSettings($user->notificationSettings);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => [
                'user' => $this->formatUser($user),
                'notification_settings' => $notificationSettings,
            ],
        ]);
    }

    /**
     * Update user profile (name, phone, profile image).
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $updateData = [];

        // Update full name if provided
        if ($request->filled('full_name')) {
            $updateData['full_name'] = trim($request->input('full_name'));
        }

        // Update phone if provided
        if ($request->filled('phone')) {
            $updateData['phone'] = trim($request->input('phone'));
        }

        // Handle profile image upload
        if ($request->hasFile('profile_image')) {
            $file = $request->file('profile_image');

            if ($file->isValid()) {
                // Delete old profile image if exists
                if ($user->profile) {
                    Storage::disk('public')->delete($user->profile);
                }

                // Store new image
                $imagePath = $file->store('profiles', 'public');
                $updateData['profile'] = $imagePath;
            }
        }

        // Update timezone and language if provided
        if ($request->filled('timezone')) {
            $updateData['timezone'] = $request->input('timezone');
        }

        if ($request->filled('language')) {
            $updateData['language'] = $request->input('language');
        }

        // Check if any data to update
        if (empty($updateData)) {
            return response()->json([
                'success' => false,
                'message' => 'No data provided to update. Please provide at least one field: full_name, phone, profile_image, timezone, or language.',
            ], 400);
        }

        // Update user
        $user->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => [
                'user' => $this->formatUser($user->fresh()),
            ],
        ]);
    }

    /**
     * Update user language.
     */
    public function updateLanguage(UpdateLanguageRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['language' => $request->language]);

        return response()->json([
            'success' => true,
            'message' => 'Language updated successfully.',
            'data' => [
                'user' => $this->formatUser($user->fresh()),
            ],
        ]);
    }

    /**
     * Update user timezone.
     */
    public function updateTimezone(UpdateTimezoneRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['timezone' => $request->timezone]);

        return response()->json([
            'success' => true,
            'message' => 'Timezone updated successfully.',
            'data' => [
                'user' => $this->formatUser($user->fresh()),
            ],
        ]);
    }

    /**
     * Format user data for response.
     */
    protected function formatUser($user): array
    {
        $profileUrl = null;
        if ($user->profile) {
            // Generate full URL for profile image
            $profileUrl = asset('storage/'.$user->profile);
        }

        return [
            'id' => $user->id,
            'role' => $user->role,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'profile' => $profileUrl,
            'profile_path' => $user->profile,
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

    /**
     * Format notification settings data for response.
     */
    protected function formatNotificationSettings($settings): array
    {
        return [
            'id' => $settings->id,
            'user_id' => $settings->user_id,
            'password_alert' => $settings->password_alert,
            'transaction_alert' => $settings->transaction_alert,
            'push_notification_alert' => $settings->push_notification_alert,
            'email_alert' => $settings->email_alert,
            'lock_alert' => $settings->lock_alert,
            'unlock_alert' => $settings->unlock_alert,
            'created_at' => $settings->created_at,
            'updated_at' => $settings->updated_at,
        ];
    }
}
