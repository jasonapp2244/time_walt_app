<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminAccountDeletionNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public int $userId,
        public string $userName,
        public string $userEmail,
        public string $userPhone,
        public float $forfeitedAmount,
        public string $deletedAt,
        public int $paymentHoldsCount,
        public int $transfersCount
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'User Account Deleted - Balance Forfeited',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-account-deletion-notification',
            with: [
                'userId' => $this->userId,
                'userName' => $this->userName,
                'userEmail' => $this->userEmail,
                'userPhone' => $this->userPhone,
                'forfeitedAmount' => $this->forfeitedAmount,
                'deletedAt' => $this->deletedAt,
                'paymentHoldsCount' => $this->paymentHoldsCount,
                'transfersCount' => $this->transfersCount,
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
