<?php

namespace App\Mail;

use App\Mail\Concerns\SendsToUser;
use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportAcknowledgementMail extends Mailable
{
    use Queueable, SendsToUser, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public SupportRequest $supportRequest
    ) {}

    /**
     * Get the message envelope.
     *
     * Carries the user's own subject so the acknowledgement threads with the
     * request they just sent, and replies land in the support inbox.
     */
    public function envelope(): Envelope
    {
        $replyTo = [];

        if ($support = config('mail.support_email')) {
            $replyTo[] = new Address($support, config('app.name').' Support');
        }

        return new Envelope(
            subject: 'We received your request: '.$this->supportRequest->subject,
            replyTo: $replyTo,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.support-acknowledgement',
            with: [
                'supportRequest' => $this->supportRequest,
                'name' => $this->readableName($this->supportRequest->user),
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
