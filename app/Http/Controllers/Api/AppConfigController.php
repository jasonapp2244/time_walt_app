<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    /**
     * Get app URLs (refer friend, Android, iOS).
     */
    public function getReferFriendUrl(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'refer_friend_url' => config('app.refer_friend_url'),
                'android_app_url' => config('app.android_app_url'),
                'ios_app_url' => config('app.ios_app_url'),
            ],
        ]);
    }
}
