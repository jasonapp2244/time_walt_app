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
        DB::statement("ALTER TABLE `payment_holds` MODIFY COLUMN `status` ENUM('holding', 'ready_for_transfer', 'partial_transferred', 'transferred', 'canceled') DEFAULT 'holding'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE `payment_holds` SET `status` = 'holding' WHERE `status` = 'canceled'");
        DB::statement("ALTER TABLE `payment_holds` MODIFY COLUMN `status` ENUM('holding', 'ready_for_transfer', 'partial_transferred', 'transferred') DEFAULT 'holding'");
    }
};
