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
        // Add abandoned_at column to payment_holds table
        Schema::table('payment_holds', function (Blueprint $table) {
            $table->timestamp('abandoned_at')->nullable()->after('transferred_at');
        });

        // Add abandoned_at column to transfers table
        Schema::table('transfers', function (Blueprint $table) {
            $table->timestamp('abandoned_at')->nullable()->after('transferred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_holds', function (Blueprint $table) {
            $table->dropColumn('abandoned_at');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('abandoned_at');
        });
    }
};
