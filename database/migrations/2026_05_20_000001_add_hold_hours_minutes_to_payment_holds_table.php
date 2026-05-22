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
        Schema::table('payment_holds', function (Blueprint $table) {
            $table->integer('hold_hours')->nullable()->default(0)->after('hold_days')->comment('Hold period hours component');
            $table->integer('hold_minutes')->nullable()->default(0)->after('hold_hours')->comment('Hold period minutes component');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_holds', function (Blueprint $table) {
            $table->dropColumn(['hold_hours', 'hold_minutes']);
        });
    }
};
