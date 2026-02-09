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
        Schema::create('payment_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');
            $table->decimal('amount', 10, 2)->comment('Held amount');
            $table->timestamp('hold_start_at')->nullable()->comment('When hold period started (user custom or payment time)');
            $table->timestamp('hold_end_at')->nullable()->comment('When hold period ends (user custom)');
            $table->integer('hold_days')->nullable()->comment('Hold period in days (user custom, no minimum restriction)');
            $table->string('hold_period_type')->nullable()->comment('User selected: 1_month, 2_months, 6_months, 1_year, custom');
            $table->enum('status', ['holding', 'ready_for_transfer', 'transferred'])->default('holding');
            $table->timestamp('ready_at')->nullable()->comment('When hold became ready for transfer');
            $table->timestamp('transferred_at')->nullable()->comment('When transfer was completed');
            $table->timestamps();

            $table->index('payment_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('hold_end_at');
            $table->index(['status', 'hold_end_at'])->comment('For cron job queries');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_holds');
    }
};
