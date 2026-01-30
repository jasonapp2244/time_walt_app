<?php

namespace App\Mail\Stripe;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
        public $transfers,
        public float $requestedAmount,
        public float $processedAmount
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $amount = number_format($this->processedAmount, 2);

        return new Envelope(
            subject: "Withdrawal Request - \${$amount}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.withdrawal-summary',
            with: [
                'user' => $this->user,
                'transfers' => $this->transfers,
                'requestedAmount' => $this->requestedAmount,
                'processedAmount' => $this->processedAmount,
                'totalTransfers' => $this->transfers->count(),
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
