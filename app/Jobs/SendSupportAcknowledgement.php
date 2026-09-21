<?php

namespace App\Jobs;

use App\Mail\Concerns\SendsToUser;
use App\Mail\SupportAcknowledgementMail;
use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Confirms to the user that their support request arrived.
 *
 * Kept separate from SendSupportNotification so a failure here cannot make the
 * queue retry - and re-send - the support inbox notification.
 */
class SendSupportAcknowledgement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsToUser, SerializesModels;

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
        $this->supportRequest->load('user');

        $email = $this->readableEmail($this->supportRequest->user);

        if (! $email) {
            Log::warning('Support acknowledgement skipped: no readable user address', [
                'support_request_id' => $this->supportRequest->id,
                'user_id' => $this->supportRequest->user_id,
            ]);

            return;
        }

        try {
            Mail::to($email)->send(new SupportAcknowledgementMail($this->supportRequest));

            Log::info('Support acknowledgement email sent successfully', [
                'support_request_id' => $this->supportRequest->id,
                'user_id' => $this->supportRequest->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Support acknowledgement email failed', [
                'support_request_id' => $this->supportRequest->id,
                'user_id' => $this->supportRequest->user_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
