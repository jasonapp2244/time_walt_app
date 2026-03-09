<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Drop existing unique + plain index before altering column type
            $table->dropUnique(['payment_intent_id']);
            $table->dropIndex(['payment_intent_id']);

            // Widen to TEXT so encrypted ciphertext (~360 chars) fits
            $table->text('payment_intent_id')->change();

            // Blind-index preserves the uniqueness guarantee on the searchable hash
            $table->string('payment_intent_id_index', 64)->nullable()->unique()->after('payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['payment_intent_id_index']);
            $table->dropColumn('payment_intent_id_index');

            $table->string('payment_intent_id')->unique()->change();
            $table->index('payment_intent_id');
        });
    }
};
