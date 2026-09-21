<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SubmitSupportRequest;
use App\Jobs\SendSupportAcknowledgement;
use App\Jobs\SendSupportNotification;
use App\Models\SupportRequest;
use Illuminate\Http\JsonResponse;

class SupportController extends Controller
{
    /**
     * Open a support request for the authenticated user.
     *
     * The request is stored so the admin panel can list it, mailed to the
     * support inbox, and acknowledged back to the user who wrote in.
     */
    public function submitSupport(SubmitSupportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        $supportRequest = SupportRequest::create([
            'user_id' => $user->id,
            'subject' => trim($validated['subject']),
            'message' => trim($validated['message']),
        ]);

        SendSupportNotification::dispatch($supportRequest);
        SendSupportAcknowledgement::dispatch($supportRequest);

        $tz = $user->timezone ?? 'UTC';

        return response()->json([
            'success' => true,
            'message' => 'Support request submitted successfully.',
            'data' => [
                'support_request' => [
                    'id' => $supportRequest->id,
                    'subject' => $supportRequest->subject,
                    'message' => $supportRequest->message,
                    'created_at' => $supportRequest->created_at?->setTimezone($tz)->toIso8601String(),
                ],
            ],
        ]);
    }
}
