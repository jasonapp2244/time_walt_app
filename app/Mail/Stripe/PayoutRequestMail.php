<?php

namespace App\Mail\Stripe;

use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayoutRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Transfer $transfer
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $amount = number_format($this->transfer->amount, 2);

        return new Envelope(
            subject: "Payout Request Received - \${$amount}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.stripe.payout-request',
            with: [
                'transfer' => $this->transfer,
                'user' => $this->transfer->user,
                'hold' => $this->transfer->hold,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
