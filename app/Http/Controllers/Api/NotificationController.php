<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\UpdateNotificationSettingsRequest;
use App\Models\UserNotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get notification settings.
     */
    public function show(Request $request): JsonResponse
    {
        $settings = $request->user()->notificationSettings;

        if (! $settings) {
            $settings = UserNotificationSetting::create([
                'user_id' => $request->user()->id,
                'password_alert' => true,
                'transaction_alert' => true,
                'push_notification_alert' => true,
                'email_alert' => true,
                'lock_alert' => true,
                'unlock_alert' => true,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $this->formatNotificationSettings($settings),
            ],
        ]);
    }

    /**
     * Update notification settings.
     */
    public function update(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->notificationSettings;

        if (! $settings) {
            $settings = UserNotificationSetting::create([
                'user_id' => $user->id,
                'password_alert' => true,
                'transaction_alert' => true,
                'push_notification_alert' => true,
                'email_alert' => true,
                'lock_alert' => true,
                'unlock_alert' => true,
            ]);
        }

        $settings->update($request->only([
            'password_alert',
            'transaction_alert',
            'push_notification_alert',
            'email_alert',
            'lock_alert',
            'unlock_alert',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Notification settings updated successfully.',
            'data' => [
                'settings' => $this->formatNotificationSettings($settings->fresh()),
            ],
        ]);
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
