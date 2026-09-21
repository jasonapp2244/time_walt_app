<?php

namespace App\Jobs;

use App\Mail\SupportRequestReceivedMail;
use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSupportNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SupportRequest $supportRequest
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Support requests go to the support inbox; config falls back to the
        // admin address when SUPPORT_EMAIL is not set.
        $supportEmail = config('mail.support_email');

        if (! $supportEmail) {
            Log::warning('Support notification skipped: mail.support_email is not configured', [
                'support_request_id' => $this->supportRequest->id,
            ]);

            return;
        }

        try {
            // The user relation is needed by the mail view and the job may run
            // long after the request that queued it.
            $this->supportRequest->load('user');

            Mail::to($supportEmail)
                ->send(new SupportRequestReceivedMail($this->supportRequest));

            Log::info('Support notification email sent successfully', [
                'support_request_id' => $this->supportRequest->id,
                'user_id' => $this->supportRequest->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Support notification email failed', [
                'support_request_id' => $this->supportRequest->id,
                'user_id' => $this->supportRequest->user_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
