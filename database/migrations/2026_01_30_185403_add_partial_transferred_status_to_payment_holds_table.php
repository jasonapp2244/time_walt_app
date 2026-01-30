<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the ENUM to include 'partial_transferred'
        // MySQL requires altering the entire ENUM definition
        DB::statement("ALTER TABLE `payment_holds` MODIFY COLUMN `status` ENUM('holding', 'ready_for_transfer', 'partial_transferred', 'transferred') DEFAULT 'holding'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'partial_transferred' from ENUM
        // First, update any records with 'partial_transferred' status to 'ready_for_transfer'
        DB::statement("UPDATE `payment_holds` SET `status` = 'ready_for_transfer' WHERE `status` = 'partial_transferred'");

        // Then modify the ENUM back to original values
        DB::statement("ALTER TABLE `payment_holds` MODIFY COLUMN `status` ENUM('holding', 'ready_for_transfer', 'transferred') DEFAULT 'holding'");
    }
};
