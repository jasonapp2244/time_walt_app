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
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hold_id')
                ->constrained('payment_holds')
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');
            $table->string('stripe_transfer_id')->unique()->nullable()->comment('Stripe Transfer ID (tr_xxx)');
            $table->string('stripe_connect_account_id')->comment('Destination Stripe Connect Account');
            $table->decimal('amount', 10, 2)->comment('Transfer amount (exact from hold, no fees)');
            $table->string('currency', 3)->default('usd');
            $table->enum('status', ['pending', 'completed', 'failed', 'canceled'])->default('pending');
            $table->timestamp('transferred_at')->nullable()->comment('When transfer was completed');
            $table->text('failure_reason')->nullable()->comment('Reason if transfer failed');
            $table->json('stripe_data')->nullable()->comment('Full Stripe Transfer response');
            $table->foreignId('admin_id')->nullable()->comment('Admin who triggered the transfer');
            $table->string('transfer_type')->default('manual')->comment('manual or cron');
            $table->timestamps();

            $table->index('hold_id');
            $table->index('user_id');
            $table->index('stripe_transfer_id');
            $table->index('stripe_connect_account_id');
            $table->index('status');
            $table->index('transferred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
