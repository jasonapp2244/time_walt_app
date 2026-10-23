<?php

namespace App\Mail\Stripe;

use App\Mail\Concerns\SendsToUser;
use App\Models\SubscriptionInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionPaymentMail extends Mailable
{
    use Queueable, SendsToUser, SerializesModels;

    public function __construct(
        public SubscriptionInvoice $invoice,
        public bool $isAdmin = false
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->isAdmin ? '[Admin] ' : '';
        $amount = strtoupper($this->invoice->currency).' '.number_format((float) $this->invoice->amount, 2);
        $plan = $this->invoice->subscription?->plan?->name ?? 'subscription';

        $subject = $this->invoice->isRenewal()
            ? ($this->isAdmin ? "Subscription renewed - {$plan} ({$amount})" : "Your {$plan} subscription has renewed")
            : ($this->isAdmin ? "New subscriber - {$plan} ({$amount})" : "Welcome to {$plan} - payment received");

        return new Envelope(subject: $prefix.$subject);
    }

    public function content(): Content
    {
        $user = $this->invoice->user;

        return new Content(
            view: 'emails.stripe.subscription-payment',
            with: [
                'invoice' => $this->invoice,
                'plan' => $this->invoice->subscription?->plan,
                'isAdmin' => $this->isAdmin,
                'isRenewal' => $this->invoice->isRenewal(),
                'name' => $this->readableName($user),
                'userEmail' => $this->readableEmail($user) ?? '[unreadable]',
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
