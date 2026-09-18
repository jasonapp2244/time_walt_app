<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The admin panel account is defined by ADMIN_PANEL_EMAIL and
 * ADMIN_PANEL_PASSWORD, not by whatever happens to be in the users table.
 *
 * Restoring a database dump used to leave the panel unreachable, because the
 * imported admin row carried a password nobody knew and PII encrypted under a
 * different APP_KEY. Syncing from config makes the row derived state that can
 * be rebuilt at any time.
 *
 * Writes go through the query builder on purpose. Eloquent's dirty-check
 * decrypts the *stored* value to compare it (HasAttributes::originalIsEquivalent),
 * so save() throws on exactly the damaged rows this needs to repair.
 */
class AdminAccount
{
    /**
     * Whether ADMIN_PANEL_EMAIL and ADMIN_PANEL_PASSWORD are both configured.
     */
    public static function isConfigured(): bool
    {
        return filled(config('app.admin_panel_email'))
            && filled(config('app.admin_panel_password'));
    }

    public static function email(): ?string
    {
        $email = config('app.admin_panel_email');

        return filled($email) ? strtolower(trim($email)) : null;
    }

    /**
     * Do the supplied credentials match what is configured in the environment?
     */
    public static function credentialsMatch(string $email, string $password): bool
    {
        if (! static::isConfigured()) {
            return false;
        }

        return hash_equals((string) static::email(), strtolower(trim($email)))
            && hash_equals((string) config('app.admin_panel_password'), $password);
    }

    /**
     * Create or repair the admin row from the environment and return it.
     *
     * Safe to run repeatedly. Matches an existing admin by blind index first,
     * then by role, so it updates rather than accumulating duplicates.
     */
    public static function sync(): ?User
    {
        if (! static::isConfigured()) {
            return null;
        }

        $email = static::email();
        $index = User::blindIndex($email);

        $id = DB::table('users')->where('email_index', $index)->value('id')
            ?? DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');

        // full_name and phone are rewritten too, so an imported row whose PII
        // was encrypted under a different APP_KEY comes back readable.
        $attributes = [
            'role' => 'admin',
            'email' => Crypt::encryptString($email),
            'email_index' => $index,
            'full_name' => Crypt::encryptString((string) config('app.admin_panel_name', 'TimeVault Admin')),
            'phone' => Crypt::encryptString('0000000000'),
            'phone_index' => User::blindIndex('0000000000'),
            'password' => Hash::make((string) config('app.admin_panel_password')),
            'status' => 'active',
            'is_verified' => true,
            'email_verified_at' => now(),
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        if ($id) {
            DB::table('users')->where('id', $id)->update($attributes);
        } else {
            $id = DB::table('users')->insertGetId($attributes + [
                'profile' => 'default.png',
                'two_factor_enabled' => false,
                'timezone' => 'UTC',
                'language' => 'en',
                'created_at' => now(),
            ]);
        }

        return User::find($id);
    }
}
