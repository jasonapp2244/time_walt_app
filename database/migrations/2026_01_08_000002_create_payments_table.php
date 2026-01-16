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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');
            $table->string('payment_intent_id')->unique()->comment('Stripe PaymentIntent ID (pi_xxx)');
            $table->decimal('amount', 10, 2)->comment('Payment amount in smallest currency unit');
            $table->string('currency', 3)->default('usd')->comment('Currency code (USD, EUR, etc.)');
            $table->enum('status', ['pending', 'succeeded', 'failed', 'canceled'])->default('pending');
            $table->timestamp('paid_at')->nullable()->comment('When payment was successfully completed');
            $table->json('stripe_data')->nullable()->comment('Full Stripe PaymentIntent response');
            $table->text('failure_reason')->nullable()->comment('Reason if payment failed');
            $table->timestamps();

            $table->index('user_id');
            $table->index('payment_intent_id');
            $table->index('status');
            $table->index('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
