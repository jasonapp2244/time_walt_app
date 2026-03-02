<?php

namespace App\Jobs;

use App\Mail\Stripe\TransferCompletedSummaryMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendTransferCompletedSummaryNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user,
        public $transfers,
        public float $totalAmount
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Send to user
            Mail::to($this->user->email)
                ->send(new TransferCompletedSummaryMail(
                    $this->user,
                    $this->transfers,
                    $this->totalAmount
                ));

            // Send to admin
            if ($adminEmail = config('mail.admin_email')) {
                Mail::to($adminEmail)
                    ->send(new TransferCompletedSummaryMail(
                        $this->user,
                        $this->transfers,
                        $this->totalAmount
                    ));
            }

            // Mark all transfers as email sent using direct DB update
            $transferIds = collect($this->transfers)->pluck('id')->toArray();
            \Illuminate\Support\Facades\DB::table('transfers')
                ->whereIn('id', $transferIds)
                ->update([
                    'email_sent_at' => now(),
                    'email_status' => 'sent',
                    'updated_at' => now(),
                ]);

            \Illuminate\Support\Facades\Log::info('Transfer summary email sent successfully', [
                'user_id' => $this->user->id,
                'transfers_count' => count($this->transfers),
            ]);
        } catch (\Exception $e) {
            // Log failure
            \Illuminate\Support\Facades\Log::error('Transfer summary email failed', [
                'user_id' => $this->user->id,
                'transfers_count' => count($this->transfers),
                'error' => $e->getMessage(),
            ]);

            // Mark all transfers as email failed using direct DB update
            $transferIds = collect($this->transfers)->pluck('id')->toArray();
            \Illuminate\Support\Facades\DB::table('transfers')
                ->whereIn('id', $transferIds)
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
