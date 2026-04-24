<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('account_holder_name');
            $table->text('bank_name');
            $table->text('account_number');
            $table->string('account_number_index', 64);
            $table->text('routing_number')->nullable();
            $table->text('iban')->nullable();
            $table->string('swift_code')->nullable();
            $table->enum('account_type', ['savings', 'checking', 'current'])->default('savings');
            $table->string('country', 2);
            $table->string('currency', 3);
            $table->text('stripe_bank_account_id')->nullable();
            $table->string('stripe_bank_account_id_index', 64)->nullable();
            $table->text('dob')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index('account_number_index', 'idx_bank_account_number_index');
            $table->index('stripe_bank_account_id_index', 'idx_stripe_bank_account_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_bank_accounts');
    }
};
