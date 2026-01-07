<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateLanguageRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UpdateTimezoneRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Get user profile.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->formatUser($request->user()),
            ],
        ]);
    }

    /**
     * Update user profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->only(['full_name', 'email', 'phone', 'profile']));

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

