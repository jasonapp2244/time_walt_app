<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Convert all timestamp columns from America/New_York to UTC.
     * Uses MySQL CONVERT_TZ if timezone tables are loaded, otherwise falls back to Carbon.
     */
    public function up(): void
    {
        $tables = [
            'users' => ['otp_expires_at', 'email_verified_at', 'expires_at', 'last_active_at', 'deleted_at', 'created_at', 'updated_at'],
            'payments' => ['paid_at', 'created_at', 'updated_at'],
            'payment_holds' => ['hold_start_at', 'hold_end_at', 'ready_at', 'transferred_at', 'abandoned_at', 'created_at', 'updated_at'],
            'transfers' => ['transferred_at', 'abandoned_at', 'email_sent_at', 'created_at', 'updated_at'],
            'stripe_connect_accounts' => ['verified_at', 'created_at', 'updated_at'],
            'stripe_customers' => ['created_at', 'updated_at'],
            'user_bank_accounts' => ['created_at', 'updated_at'],
            'user_notification_settings' => ['created_at', 'updated_at'],
            'personal_access_tokens' => ['last_used_at', 'expires_at', 'created_at', 'updated_at'],
            'privacy_policies' => ['effective_date', 'created_at', 'updated_at'],
        ];

        // Check if MySQL timezone tables are loaded
        $tzCheck = DB::select("SELECT CONVERT_TZ('2026-01-01 12:00:00', 'America/New_York', 'UTC') as result");
        $hasTzTables = $tzCheck[0]->result !== null;

        foreach ($tables as $table => $columns) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                    continue;
                }

                if ($hasTzTables) {
                    // Use MySQL CONVERT_TZ (handles DST automatically)
                    DB::statement("UPDATE `{$table}` SET `{$column}` = CONVERT_TZ(`{$column}`, 'America/New_York', 'UTC') WHERE `{$column}` IS NOT NULL");
                } else {
                    // Fallback: use raw offset. May 2026 = EDT = UTC-4, so add 4 hours
                    DB::statement("UPDATE `{$table}` SET `{$column}` = DATE_ADD(`{$column}`, INTERVAL 4 HOUR) WHERE `{$column}` IS NOT NULL");
                }
            }
        }

        Log::info('Converted all timestamps from America/New_York to UTC', [
            'method' => $hasTzTables ? 'CONVERT_TZ' : 'fixed_offset_4h',
        ]);
    }

    /**
     * Convert back from UTC to America/New_York.
     */
    public function down(): void
    {
        // Reverse is not recommended — data loss risk with DST ambiguity
    }
};
