<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money moved directly in the Stripe Dashboard, outside the app:
 *  - transfers.manual_reversed_amount: part of a withdrawal someone reversed by
 *    hand (already included in reversed_amount); it went back to the vault.
 *  - subscription_invoices.refunded_amount / refunded_at: a subscription
 *    payment refunded in Stripe (shown on the admin Subscription Payments page).
 *  - disputes.subscription_invoice_id: a chargeback on a subscription payment
 *    (payment_id becomes nullable; deposit disputes keep it).
 * Additive only: existing rows are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->change();
            $table->foreignId('subscription_invoice_id')->nullable()->after('payment_id')
                ->constrained('subscription_invoices')->nullOnDelete();
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->decimal('manual_reversed_amount', 10, 2)->default(0)->after('reversed_amount');
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('amount');
            $table->timestamp('refunded_at')->nullable()->after('refunded_amount');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['refunded_amount', 'refunded_at']);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('manual_reversed_amount');
        });

        // Subscription disputes cannot exist without the column; drop them first.
        \Illuminate\Support\Facades\DB::table('disputes')->whereNull('payment_id')->delete();

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_invoice_id');
            $table->unsignedBigInteger('payment_id')->nullable(false)->change();
        });
    }
};
