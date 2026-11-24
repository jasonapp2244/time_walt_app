<?php

namespace App\Mail\Stripe;

use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminManualReversalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Transfer $transfer,
        public float $amount
    ) {}

    public function envelope(): Envelope
    {
        $user = $this->transfer->user?->displayName('Unknown user') ?? 'Deleted user';

        return new Envelope(
            subject: '[Admin] Withdrawal reversed in Stripe: $'.number_format($this->amount, 2).' back in '.$user."'s vault",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.admin-manual-reversal',
            with: ['transfer' => $this->transfer, 'amount' => $this->amount],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
