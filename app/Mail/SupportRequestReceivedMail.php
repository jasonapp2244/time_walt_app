<?php

namespace App\Mail;

use App\Mail\Concerns\RepliesToUser;
use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportRequestReceivedMail extends Mailable
{
    use Queueable, RepliesToUser, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public SupportRequest $supportRequest
    ) {}

    /**
     * Get the message envelope.
     *
     * The user's own subject goes in the mail subject so the admin inbox is
     * scannable, and replies go straight back to the user who wrote in.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Support Request: '.$this->supportRequest->subject,
            replyTo: $this->replyToUser($this->supportRequest->user),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.support-received',
            with: [
                'supportRequest' => $this->supportRequest,
                'user' => $this->supportRequest->user,
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
