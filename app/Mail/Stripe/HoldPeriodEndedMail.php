<?php

namespace App\Mail\Stripe;

use App\Models\PaymentHold;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HoldPeriodEndedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PaymentHold $hold,
        public User $user,
        public bool $isAdmin = false
    ) {}

    public function envelope(): Envelope
    {
        $amount = number_format($this->hold->amount, 2);
        $prefix = $this->isAdmin ? '[Admin] ' : '';

        return new Envelope(
            subject: "{$prefix}Hold Period Ended - \${$amount} Ready for Transfer",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.hold-ended',
            with: [
                'hold' => $this->hold,
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
