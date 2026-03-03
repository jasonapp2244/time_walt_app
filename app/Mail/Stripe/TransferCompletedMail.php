<?php

namespace App\Mail\Stripe;

use App\Models\Transfer;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransferCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Transfer $transfer,
        public User $user
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $amount = number_format($this->transfer->amount, 2);
        $title = $this->transfer->hold?->title;

        if ($title) {
            return new Envelope(
                subject: "Transfer Completed - {$title} \${$amount}",
            );
        }

        return new Envelope(
            subject: "Transfer Completed - \${$amount} Transferred",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.transfer-completed',
            with: [
                'transfer' => $this->transfer,
                'user' => $this->user,
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
