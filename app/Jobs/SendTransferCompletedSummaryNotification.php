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
    }
}
