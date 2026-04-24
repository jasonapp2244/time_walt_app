<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('stripe_customer_id');
            $table->string('stripe_customer_id_index', 64);
            $table->text('stripe_data')->nullable();
            $table->timestamps();

            $table->index('stripe_customer_id_index', 'idx_customer_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_customers');
    }
};
