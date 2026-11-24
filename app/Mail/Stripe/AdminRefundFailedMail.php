<?php

namespace App\Mail\Stripe;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminRefundFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Refund $refund
    ) {}

    public function envelope(): Envelope
    {
        $amount = '$'.number_format((float) $this->refund->amount, 2);
        $user = $this->refund->user?->displayName('Unknown user') ?? 'Deleted user';

        return new Envelope(
            subject: $this->refund->status === 'pending'
                ? "[Admin] Refund needs review: {$amount} for {$user}"
                : "[Admin] Refund FAILED: {$amount} for {$user}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.admin-refund-failed',
            with: ['refund' => $this->refund],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
