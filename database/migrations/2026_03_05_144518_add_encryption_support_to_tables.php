<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // â”€â”€ USERS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Drop unique constraints only if they still exist (safe on re-run)
        $this->dropIndexIfExists('users', 'users_email_unique');
        $this->dropIndexIfExists('users', 'users_phone_unique');

        Schema::table('users', function (Blueprint $table) {
            // Widen columns to hold encrypted ciphertext (~360 chars)
            $table->text('email')->change();
            $table->text('phone')->nullable()->change();
            $table->text('full_name')->change();
            $table->text('otp_code')->nullable()->change();
            $table->text('token')->nullable()->change();
            $table->text('provider_id')->nullable()->change();
            $table->text('fcm_token')->nullable()->change();
            $table->text('device_id')->nullable()->change();
        });

        // Add blind-index columns only if they do not yet exist
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'email_index')) {
                $table->string('email_index', 64)->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'phone_index')) {
                $table->string('phone_index', 64)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'provider_id_index')) {
                $table->string('provider_id_index', 64)->nullable()->index()->after('provider_id');
            }
        });

        // phone_index unique index (nullable-safe: multiple NULLs allowed)
        if (! $this->indexExists('users', 'users_phone_index_unique')) {
            DB::statement('CREATE UNIQUE INDEX users_phone_index_unique ON users (phone_index)');
        }

        // â”€â”€ STRIPE CONNECT ACCOUNTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // STEP 1: Drop ALL indexes on connect_account_id before changing column type.
        // MySQL does not allow TEXT columns in key specs without a prefix length.
        $this->dropIndexIfExists('stripe_connect_accounts', 'stripe_connect_accounts_connect_account_id_unique');
        $this->dropIndexIfExists('stripe_connect_accounts', 'stripe_connect_accounts_connect_account_id_index');

        // STEP 2: Change column type (TEXT) now that no indexes remain on it.
        Schema::table('stripe_connect_accounts', function (Blueprint $table) {
            $table->text('connect_account_id')->change();
            $table->longText('stripe_data')->nullable()->change();
        });

        // STEP 3: Add blind-index column only if not yet present.
        if (! Schema::hasColumn('stripe_connect_accounts', 'connect_account_id_index')) {
            Schema::table('stripe_connect_accounts', function (Blueprint $table) {
                $table->string('connect_account_id_index', 64)->nullable()->unique()->after('connect_account_id');
            });
        }

        // â”€â”€ PAYMENTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        Schema::table('payments', function (Blueprint $table) {
            $table->longText('stripe_data')->nullable()->change();
        });

        // â”€â”€ TRANSFERS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        Schema::table('transfers', function (Blueprint $table) {
            $table->longText('stripe_data')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_phone_index_unique');
            $table->dropUnique(['email_index']);
            $table->dropIndex(['provider_id_index']);
            $table->dropColumn(['email_index', 'phone_index', 'provider_id_index']);

            $table->string('email')->unique()->change();
            $table->string('phone')->unique()->change();
            $table->string('full_name')->change();
            $table->string('otp_code', 4)->nullable()->change();
            $table->string('token')->nullable()->change();
            $table->string('provider_id')->nullable()->change();
            $table->string('fcm_token')->nullable()->change();
            $table->string('device_id')->nullable()->change();
        });

        Schema::table('stripe_connect_accounts', function (Blueprint $table) {
            $table->dropUnique(['connect_account_id_index']);
            $table->dropColumn('connect_account_id_index');
            $table->string('connect_account_id')->unique()->change();
            $table->json('stripe_data')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->json('stripe_data')->nullable()->change();
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->json('stripe_data')->nullable()->change();
        });
    }

    /**
     * Drop an index only if it exists (cross-version MySQL safe).
     */
    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $exists = DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name   = ?
               AND index_name   = ?
             LIMIT 1',
            [$table, $indexName]
        );

        if (! empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }

    /**
     * Check whether a named index exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return ! empty(DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name   = ?
               AND index_name   = ?
             LIMIT 1',
            [$table, $indexName]
        ));
    }
};
