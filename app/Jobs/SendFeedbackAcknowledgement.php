<?php

namespace App\Jobs;

use App\Mail\Concerns\SendsToUser;
use App\Mail\FeedbackAcknowledgementMail;
use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Confirms to the user that their feedback arrived.
 *
 * Kept separate from SendFeedbackNotification so a failure here cannot make the
 * queue retry - and re-send - the admin notification.
 */
class SendFeedbackAcknowledgement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsToUser, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Feedback $feedback
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->feedback->load('user');

        $email = $this->readableEmail($this->feedback->user);

        if (! $email) {
            Log::warning('Feedback acknowledgement skipped: no readable user address', [
                'feedback_id' => $this->feedback->id,
                'user_id' => $this->feedback->user_id,
            ]);

            return;
        }

        try {
            Mail::to($email)->send(new FeedbackAcknowledgementMail($this->feedback));

            Log::info('Feedback acknowledgement email sent successfully', [
                'feedback_id' => $this->feedback->id,
                'user_id' => $this->feedback->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Feedback acknowledgement email failed', [
                'feedback_id' => $this->feedback->id,
                'user_id' => $this->feedback->user_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
