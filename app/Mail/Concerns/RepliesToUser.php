<?php

namespace App\Mail\Concerns;

use App\Models\User;
use Illuminate\Mail\Mailables\Address;

/**
 * Builds the reply-to address for a notification about something a user sent.
 *
 * full_name and email are encrypted at rest, and a row written under a previous
 * APP_KEY throws on read. Reading them unguarded inside a queued mailable fails
 * the whole job, so the admin never learns about the submission at all. A user
 * we cannot read simply gets no reply-to.
 */
trait RepliesToUser
{
    /**
     * @return array<int, Address>
     */
    protected function replyToUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $email = rescue(fn () => $user->email, null, false);

        if (! $email) {
            return [];
        }

        $name = rescue(fn () => $user->full_name, null, false) ?: $email;

        return [new Address($email, $name)];
    }
}
