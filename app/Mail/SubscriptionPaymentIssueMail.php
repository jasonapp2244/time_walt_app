<?php

namespace App\Mail;

use App\Mail\Concerns\SendsToUser;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionPaymentIssueMail extends Mailable
{
    use Queueable, SendsToUser, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public string $kind,
        public ?string $invoiceUrl = null,
        public bool $isAdmin = false
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = [];

        if ($support = config('mail.support_email')) {
            $replyTo[] = new Address($support, config('app.name').' Support');
        }

        return new Envelope(
            subject: ($this->isAdmin ? '[Admin] ' : '').($this->kind === 'action_required'
                ? 'Confirm your '.config('app.name').' subscription payment'
                : 'Your '.config('app.name').' subscription payment failed'),
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-payment-issue',
            with: [
                'subscription' => $this->subscription,
                'kind' => $this->kind,
                'invoiceUrl' => $this->invoiceUrl,
                'name' => $this->readableName($this->subscription->user),
                'userEmail' => $this->readableEmail($this->subscription->user) ?? '[unreadable]',
                'isAdmin' => $this->isAdmin,
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
