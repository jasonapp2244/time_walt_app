<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-tier usage is counted, not derived from holds and transfers:
     * existing users already have holds and must not be blocked on launch day,
     * and a withdrawal has no single row to count.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('free_locks_used')->default(0);
            $table->unsignedInteger('free_withdrawals_used')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['free_locks_used', 'free_withdrawals_used']);
        });
    }
};
