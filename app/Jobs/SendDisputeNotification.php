<?php

namespace App\Jobs;

use App\Mail\Stripe\AdminDisputeMail;
use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the admin a chargeback was opened, won or lost. The user is not
 * emailed: the dispute is between them and their bank.
 */
class SendDisputeNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Dispute $dispute,
        public string $event
    ) {}

    public function handle(): void
    {
        if ($adminEmail = config('mail.admin_email')) {
            $this->dispute->loadMissing(['user', 'payment', 'hold']);

            Mail::to($adminEmail)->send(new AdminDisputeMail($this->dispute, $this->event));
        }
    }
}
