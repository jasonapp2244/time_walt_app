<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrivacyPolicyResource;
use App\Models\PrivacyPolicy;
use Illuminate\Http\JsonResponse;

class PrivacyPolicyController extends Controller
{
    /**
     * Display the active privacy policy for mobile view.
     */
    public function active(): JsonResponse
    {
        $policy = PrivacyPolicy::query()
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $policy) {
            return response()->json([
                'success' => false,
                'message' => 'No active privacy policy found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Privacy policy retrieved successfully.',
            'data' => new PrivacyPolicyResource($policy),
        ]);
    }
}
