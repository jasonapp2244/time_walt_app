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
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique()->comment('Stripe Event ID (evt_xxx)');
            $table->string('event_type')->comment('Event type: payment_intent.succeeded, account.updated, etc.');
            $table->enum('status', ['pending', 'processed', 'failed', 'duplicate'])->default('pending');
            $table->json('payload')->comment('Full webhook payload from Stripe');
            $table->timestamp('processed_at')->nullable()->comment('When webhook was processed');
            $table->text('error_message')->nullable()->comment('Error if processing failed');
            $table->integer('retry_count')->default(0)->comment('Number of retry attempts');
            $table->timestamp('last_retry_at')->nullable()->comment('Last retry attempt timestamp');
            $table->timestamps();

            $table->index('stripe_event_id');
            $table->index('event_type');
            $table->index('status');
            $table->index('created_at');
            $table->index(['status', 'created_at'])->comment('For retry queries');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
