<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fees Stripe kept, one row per Stripe balance transaction with a fee
 * (card fees on deposits and subscriptions, chargeback fees, Connect and
 * other account fees). Filled by stripe:sync-fees and shown on the admin
 * Ledger. A new table only: no existing table or row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_fees', function (Blueprint $table) {
            $table->id();
            $table->text('balance_transaction_id')->comment('Stripe balance transaction (txn_…), encrypted');
            $table->string('balance_transaction_id_index', 64)->unique();
            $table->string('type', 40)->comment('Stripe balance transaction type: charge, payment, adjustment, stripe_fee, …');
            $table->string('category', 20)->comment('deposit, subscription, chargeback, refund, platform, other');
            $table->string('description')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('gross', 10, 2)->default(0);
            $table->decimal('fee', 10, 2)->comment('What Stripe kept; negative when Stripe gave a fee back');
            $table->decimal('net', 10, 2)->default(0);
            $table->json('fee_details')->nullable();
            $table->char('currency', 3)->default('usd');
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_fees');
    }
};
