<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\RegisterDeviceRequest;
use App\Http\Requests\Device\UpdateDeviceTokenRequest;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    /**
     * Register device for user.
     */
    public function register(RegisterDeviceRequest $request): JsonResponse
    {
        $user = $request->user();
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

        // Update timezone and language (profile fields) if provided
        if ($request->filled('timezone')) {
            $updateData['timezone'] = $request->timezone;
        }

        if ($request->filled('language')) {
            $updateData['language'] = $request->language;
        }

        $user->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Device registered successfully.',
            'data' => [
                'user' => $this->formatUser($user->fresh()),
            ],
        ]);
    }

    /**
     * Update device FCM token.
     */
    public function updateToken(UpdateDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->device_id !== $request->device_id) {
            return response()->json([
                'success' => false,
                'message' => 'Device ID mismatch.',
            ], 400);
        }

        $user->update([
            'fcm_token' => $request->fcm_token,
            'last_active_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Device token updated successfully.',
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
