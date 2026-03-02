<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('transferred_at');
            $table->string('email_status')->nullable()->after('email_sent_at'); // 'sent', 'failed', 'pending'
            $table->text('email_failure_reason')->nullable()->after('email_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn(['email_sent_at', 'email_status', 'email_failure_reason']);
        });
    }
};
