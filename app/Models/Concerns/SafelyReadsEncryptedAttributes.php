<?php

namespace App\Models\Concerns;

/**
 * Rows written under a previous APP_KEY throw on read of any encrypted column.
 * PHP's "??" only suppresses undefined-property warnings - it does nothing
 * about a thrown DecryptException - so a single damaged row would take down
 * every admin page that renders it.
 *
 * Reading through safe() degrades to a placeholder instead of throwing.
 */
trait SafelyReadsEncryptedAttributes
{
    /**
     * Read an attribute that may be undecryptable.
     *
     * @param  string  $fallback  returned when the value is null or empty
     * @param  string  $onError  returned when the value cannot be decrypted
     */
    public function safe(string $attribute, string $fallback = '—', string $onError = '[unreadable]'): string
    {
        try {
            $value = $this->{$attribute};
        } catch (\Throwable) {
            return $onError;
        }

        return ($value === null || $value === '') ? $fallback : (string) $value;
    }
}
