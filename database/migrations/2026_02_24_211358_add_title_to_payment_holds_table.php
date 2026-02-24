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
            $table->string('title')->nullable()->after('payment_id')->comment('User-provided title/description for the payment hold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_holds', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
