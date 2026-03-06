<?php

namespace App\Mail\Stripe;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransferCompletedSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public $transfers,
        public float $totalAmount,
        public bool $isAdmin = false
    ) {}

    public function envelope(): Envelope
    {
        $amount = number_format($this->totalAmount, 2);
        $prefix = $this->isAdmin ? '[Admin] ' : '';

        return new Envelope(subject: "{$prefix}Transfer Completed - \${$amount} Transferred");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.transfer-completed-summary',
            with: [
                'user' => $this->user,
                'transfers' => $this->transfers,
                'totalAmount' => $this->totalAmount,
                'totalTransfers' => $this->transfers->count(),
                'isAdmin' => $this->isAdmin,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
