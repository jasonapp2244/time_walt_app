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
        Schema::create('stripe_connect_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');
            $table->string('connect_account_id')->unique()->comment('Stripe Connect Account ID (acct_xxx)');
            $table->enum('status', ['pending', 'verified', 'restricted'])->default('pending');
            $table->boolean('payouts_enabled')->default(false)->comment('Stripe payouts enabled status');
            $table->text('onboarding_url')->nullable()->comment('Stripe onboarding link if needed');
            $table->json('stripe_data')->nullable()->comment('Additional Stripe account data');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('connect_account_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stripe_connect_accounts');
    }
};
