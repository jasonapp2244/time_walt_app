<?php

namespace App\Jobs;

use App\Mail\Stripe\TransferCompletedMail;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendTransferCompletedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Transfer $transfer
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Refresh the transfer to get latest data
            $this->transfer->refresh();

            // Send to user
            Mail::to($this->transfer->user->email)
                ->send(new TransferCompletedMail($this->transfer, $this->transfer->user));

            // Send to admin
            if ($adminEmail = config('mail.admin_email')) {
                Mail::to($adminEmail)
                    ->send(new TransferCompletedMail($this->transfer, $this->transfer->user));
            }

            // Mark email as sent using direct DB update to ensure it persists
            \Illuminate\Support\Facades\DB::table('transfers')
                ->where('id', $this->transfer->id)
                ->update([
                    'email_sent_at' => now(),
                    'email_status' => 'sent',
                    'updated_at' => now(),
                ]);

            \Illuminate\Support\Facades\Log::info('Transfer completion email sent successfully', [
                'transfer_id' => $this->transfer->id,
                'user_id' => $this->transfer->user_id,
            ]);
        } catch (\Exception $e) {
            // Log failure
            \Illuminate\Support\Facades\Log::error('Transfer completion email failed', [
                'transfer_id' => $this->transfer->id,
                'error' => $e->getMessage(),
            ]);

            // Mark email as failed using direct DB update
            \Illuminate\Support\Facades\DB::table('transfers')
                ->where('id', $this->transfer->id)
                ->update([
                    'email_status' => 'failed',
                    'email_failure_reason' => substr($e->getMessage(), 0, 255),
                    'updated_at' => now(),
                ]);

            // Rethrow to allow Laravel's queue retry logic
            throw $e;
        }
    }
}
