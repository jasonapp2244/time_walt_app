<?php

namespace App\Jobs;

use App\Mail\FeedbackReceivedMail;
use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendFeedbackNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $adminEmail = config('mail.admin_email');

        if (! $adminEmail) {
            Log::warning('Feedback notification skipped: mail.admin_email is not configured', [
                'feedback_id' => $this->feedback->id,
            ]);

            return;
        }

        try {
            // The user relation is needed by the mail view and the job may run
            // long after the request that queued it.
            $this->feedback->load('user');

            Mail::to($adminEmail)
                ->send(new FeedbackReceivedMail($this->feedback));

            Log::info('Feedback notification email sent successfully', [
                'feedback_id' => $this->feedback->id,
                'user_id' => $this->feedback->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Feedback notification email failed', [
                'feedback_id' => $this->feedback->id,
                'user_id' => $this->feedback->user_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
