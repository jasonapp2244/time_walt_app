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
        Schema::create('user_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()
                ->constrained()
                ->onDelete('cascade');
            $table->boolean('password_alert')->default(true);
            $table->boolean('transaction_alert')->default(true);
            $table->boolean('push_notification_alert')->default(true);
            $table->boolean('email_alert')->default(true);
            $table->boolean('lock_alert')->default(true);
            $table->boolean('unlock_alert')->default(true);
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_notification_settings');
    }
};
