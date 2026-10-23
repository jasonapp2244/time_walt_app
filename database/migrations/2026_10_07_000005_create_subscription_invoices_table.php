<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per paid subscription invoice (first payment and every renewal).
     * The unique invoice index is what makes the payment emails go out once,
     * however many times Stripe delivers the webhook.
     */
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('stripe_invoice_id');
            $table->string('stripe_invoice_id_index', 64)->unique();
            $table->string('invoice_number', 100)->nullable();
            $table->string('billing_reason', 40);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->timestamp('period_end')->nullable();
            $table->text('hosted_invoice_url')->nullable();
            $table->text('invoice_pdf')->nullable();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'paid_at']);
            $table->index('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
