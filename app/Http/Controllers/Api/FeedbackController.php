<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendFeedbackAcknowledgement;
use App\Jobs\SendFeedbackNotification;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    /**
     * Submit feedback for the authenticated user.
     */
    public function submitFeedback(Request $request): JsonResponse
    {
        // The app sends "rating"; older builds send the legacy "ratting" spelling.
        // Requiring each one only when the other is absent keeps every failure in
        // Laravel's standard {message, errors} shape, under a single "rating" key.
        $validated = $request->validate([
            'rating' => 'required_without:ratting|nullable|integer|min:1|max:5',
            'ratting' => 'required_without:rating|nullable|integer|min:1|max:5',
            'feedback' => 'required|string|max:1000',
        ], [
            'rating.required_without' => 'The rating field is required.',
            'ratting.required_without' => 'The rating field is required.',
        ]);

        $user = $request->user();

        $feedback = Feedback::create([
            'user_id' => $user->id,
            'rating' => $validated['rating'] ?? $validated['ratting'],
            'message' => trim($validated['feedback']),
        ]);

        SendFeedbackNotification::dispatch($feedback);
        SendFeedbackAcknowledgement::dispatch($feedback);

        $tz = $user->timezone ?? 'UTC';

        return response()->json([
            'success' => true,
            'message' => 'Feedback submitted successfully.',
            'data' => [
                'feedback' => [
                    'id' => $feedback->id,
                    'rating' => $feedback->rating,
                    'feedback' => $feedback->message,
                    'created_at' => $feedback->created_at?->setTimezone($tz)->toIso8601String(),
                ],
            ],
        ]);
    }
}
