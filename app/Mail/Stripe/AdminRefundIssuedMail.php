<?php

namespace App\Mail\Stripe;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminRefundIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Refund $refund
    ) {}

    public function envelope(): Envelope
    {
        $amount = '$'.number_format((float) $this->refund->amount, 2);
        $user = $this->refund->user?->displayName('Unknown user') ?? 'Deleted user';
        $card = $this->refund->cardLabel();

        return new Envelope(
            subject: "[Admin] Refund issued: {$amount} to {$user} ({$card})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.admin-refund-issued',
            with: ['refund' => $this->refund],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
