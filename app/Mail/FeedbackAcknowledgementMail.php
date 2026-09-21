<?php

namespace App\Mail;

use App\Mail\Concerns\SendsToUser;
use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeedbackAcknowledgementMail extends Mailable
{
    use Queueable, SendsToUser, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Feedback $feedback
    ) {}

    /**
     * Get the message envelope.
     *
     * A reply from the user should reach the team that reads feedback, not the
     * app's own From address.
     */
    public function envelope(): Envelope
    {
        $replyTo = [];

        if ($admin = config('mail.admin_email')) {
            $replyTo[] = new Address($admin, config('app.name'));
        }

        return new Envelope(
            subject: 'We received your feedback',
            replyTo: $replyTo,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.feedback-acknowledgement',
            with: [
                'feedback' => $this->feedback,
                'name' => $this->readableName($this->feedback->user),
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
