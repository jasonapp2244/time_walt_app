<?php

namespace App\Mail\Concerns;

use App\Models\User;

/**
 * Resolves the address an acknowledgement should be sent to.
 *
 * The email column is encrypted, and a row written under a previous APP_KEY
 * throws on read. A queued job that reads it unguarded fails outright, so the
 * admin notification dispatched alongside it is the only thing that survives.
 * An unreadable user simply gets no acknowledgement.
 */
trait SendsToUser
{
    protected function readableEmail(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $email = rescue(fn () => $user->email, null, false);

        return $email ?: null;
    }

    protected function readableName(?User $user, string $fallback = 'there'): string
    {
        if ($user === null) {
            return $fallback;
        }

        return rescue(fn () => $user->full_name, null, false) ?: $fallback;
    }
}
