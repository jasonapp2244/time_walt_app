<?php

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeedbackReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Feedback $feedback
    ) {}

    /**
     * Get the message envelope.
     *
     * Replies go to the user who submitted the feedback, so an admin can answer
     * straight from the notification. Falls back to no reply-to if the user row
     * is gone or carries no address.
     */
    public function envelope(): Envelope
    {
        $user = $this->feedback->user;
        $replyTo = [];

        if ($user?->email) {
            $replyTo[] = new Address($user->email, $user->full_name ?? $user->email);
        }

        return new Envelope(
            subject: 'New User Feedback Received',
            replyTo: $replyTo,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.feedback-received',
            with: [
                'feedback' => $this->feedback,
                'user' => $this->feedback->user,
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
