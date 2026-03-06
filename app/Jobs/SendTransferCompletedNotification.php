<?php

namespace App\Jobs;

use App\Mail\Stripe\TransferCompletedMail;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendTransferCompletedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Transfer $transfer
    ) {}

    public function handle(): void
    {
        try {
            $this->transfer->refresh();
            $this->transfer->load(['user', 'hold']);

            Mail::to($this->transfer->user->email)
                ->send(new TransferCompletedMail($this->transfer, $this->transfer->user));

            if ($adminEmail = config('mail.admin_email')) {
                Mail::to($adminEmail)
                    ->send(new TransferCompletedMail($this->transfer, $this->transfer->user, isAdmin: true));
            }

            DB::table('transfers')
                ->where('id', $this->transfer->id)
                ->update(['email_sent_at' => now(), 'email_status' => 'sent', 'updated_at' => now()]);

            Log::info('Transfer completion email sent successfully', [
                'transfer_id' => $this->transfer->id,
                'user_id' => $this->transfer->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Transfer completion email failed', [
                'transfer_id' => $this->transfer->id,
                'error' => $e->getMessage(),
            ]);

            DB::table('transfers')
                ->where('id', $this->transfer->id)
                ->update([
                    'email_status' => 'failed',
                    'email_failure_reason' => substr($e->getMessage(), 0, 255),
                    'updated_at' => now(),
                ]);

            throw $e;
        }
    }
}
