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

    public function __construct(
        public Transfer $transfer,
        public User $user,
        public bool $isAdmin = false
    ) {}

    public function envelope(): Envelope
    {
        $amount = number_format($this->transfer->amount, 2);
        $title = $this->transfer->hold?->title;
        $prefix = $this->isAdmin ? '[Admin] ' : '';

        if ($title) {
            return new Envelope(subject: "{$prefix}Transfer Completed - {$title} \${$amount}");
        }

        return new Envelope(subject: "{$prefix}Transfer Completed - \${$amount} Transferred");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.transfer-completed',
            with: [
                'transfer' => $this->transfer,
                'user' => $this->user,
                'isAdmin' => $this->isAdmin,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
