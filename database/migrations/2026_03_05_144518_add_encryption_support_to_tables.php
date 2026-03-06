<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── USERS ──────────────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            // Drop unique constraints — uniqueness moves to blind-index columns
            $table->dropUnique(['email']);
            $table->dropUnique(['phone']);

            // Widen columns so they can hold encrypted ciphertext (~200–500 chars)
            $table->text('email')->change();
            $table->text('phone')->nullable()->change();
            $table->text('full_name')->change();
            $table->text('otp_code')->nullable()->change();   // was string(4)
            $table->text('token')->nullable()->change();
            $table->text('provider_id')->nullable()->change();
            $table->text('fcm_token')->nullable()->change();
            $table->text('device_id')->nullable()->change();

            // Blind-index columns (HMAC-SHA256, fixed 64-char hex, searchable)
            $table->string('email_index', 64)->nullable()->unique()->after('email');
            $table->string('phone_index', 64)->nullable()->after('phone');
            $table->string('provider_id_index', 64)->nullable()->index()->after('provider_id');
        });

        // phone_index unique index (nullable-safe: multiple NULLs are allowed)
        DB::statement('CREATE UNIQUE INDEX users_phone_index_unique ON users (phone_index)');

        // ── STRIPE CONNECT ACCOUNTS ────────────────────────────────────────────
        Schema::table('stripe_connect_accounts', function (Blueprint $table) {
            $table->dropUnique(['connect_account_id']);

            $table->text('connect_account_id')->change();
            $table->longText('stripe_data')->nullable()->change();

            // Blind-index for connect_account_id (used in WHERE lookups)
            $table->string('connect_account_id_index', 64)->nullable()->unique()->after('connect_account_id');
        });

        // Drop the plain index that was added alongside the unique one
        DB::statement('DROP INDEX IF EXISTS stripe_connect_accounts_connect_account_id_index ON stripe_connect_accounts');

        // ── PAYMENTS ───────────────────────────────────────────────────────────
        Schema::table('payments', function (Blueprint $table) {
            $table->longText('stripe_data')->nullable()->change();
        });

        // ── TRANSFERS ──────────────────────────────────────────────────────────
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
};
