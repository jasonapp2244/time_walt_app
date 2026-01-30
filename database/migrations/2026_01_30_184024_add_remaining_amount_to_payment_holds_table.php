<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_holds', function (Blueprint $table) {
            $table->decimal('remaining_amount', 10, 2)->after('amount')->nullable();
        });

        // Set existing records: remaining_amount = amount - sum of completed/pending transfers
        DB::statement('
            UPDATE payment_holds ph
            SET remaining_amount = ph.amount - COALESCE(
                (SELECT SUM(t.amount) 
                 FROM transfers t 
                 WHERE t.hold_id = ph.id 
                 AND t.status IN ("completed", "pending")), 
                0
            )
            WHERE remaining_amount IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_holds', function (Blueprint $table) {
            $table->dropColumn('remaining_amount');
        });
    }
};
