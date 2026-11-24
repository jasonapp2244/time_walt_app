<?php

namespace App\Mail\Stripe;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminDisputeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Dispute $dispute,
        public string $event
    ) {}

    public function envelope(): Envelope
    {
        $amount = '$'.number_format((float) $this->dispute->amount, 2);
        $user = $this->dispute->user?->displayName('Unknown user') ?? 'Deleted user';

        return new Envelope(
            subject: match ($this->event) {
                'won' => "[Admin] Chargeback WON: {$amount} for {$user}",
                'lost' => "[Admin] Chargeback LOST: {$amount} for {$user}",
                default => "[Admin] URGENT chargeback opened: {$amount} for {$user}",
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.admin-dispute',
            with: ['dispute' => $this->dispute, 'event' => $this->event],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
