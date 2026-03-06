<?php

namespace App\Mail\Stripe;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public bool $isAdmin = false
    ) {}

    public function envelope(): Envelope
    {
        $amount = number_format($this->payment->amount, 2);
        $prefix = $this->isAdmin ? '[Admin] ' : '';

        return new Envelope(
            subject: "{$prefix}Payment Successful - \${$amount} Received",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stripe.payment-success',
            with: [
                'payment' => $this->payment,
                'user' => $this->payment->user,
                'hold' => $this->payment->hold,
                'isAdmin' => $this->isAdmin,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
