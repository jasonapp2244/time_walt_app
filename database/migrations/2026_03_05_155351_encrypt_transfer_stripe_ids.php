<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            // Drop existing unique + plain index before altering the column type
            $table->dropUnique(['stripe_transfer_id']);
            $table->dropIndex(['stripe_transfer_id']);
            $table->dropIndex(['stripe_connect_account_id']);

            // Widen to TEXT so encrypted ciphertext fits
            $table->text('stripe_transfer_id')->nullable()->change();
            $table->text('stripe_connect_account_id')->change();

            // Blind-index for stripe_transfer_id (preserves uniqueness guarantee)
            $table->string('stripe_transfer_id_index', 64)->nullable()->unique()->after('stripe_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropUnique(['stripe_transfer_id_index']);
            $table->dropColumn('stripe_transfer_id_index');

            $table->string('stripe_transfer_id')->unique()->nullable()->change();
            $table->string('stripe_connect_account_id')->change();

            $table->index('stripe_transfer_id');
            $table->index('stripe_connect_account_id');
        });
    }
};
