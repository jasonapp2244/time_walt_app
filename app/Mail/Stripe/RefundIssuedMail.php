<?php

namespace App\Mail\Stripe;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RefundIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Refund $refund
    ) {}

    public function envelope(): Envelope
    {
        $amount = '$'.number_format((float) $this->refund->amount, 2);

        return new Envelope(
            subject: "Your refund of {$amount} is on its way",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.refund-issued',
            with: ['refund' => $this->refund],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
