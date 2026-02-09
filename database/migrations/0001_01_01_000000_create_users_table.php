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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('role')->default('user');
            $table->string('full_name');

            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password');
            $table->string('profile')->default('default.png');
            
            $table->string('otp_code', 4)->nullable();
            $table->timestamp('otp_expires_at')->nullable();

            $table->boolean('is_verified')->default(false);
            $table->string('status')->default('active');
            $table->boolean('two_factor_enabled')->default(false);
         
            $table->timestamp('email_verified_at')->nullable();

            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();

            $table->string('timezone')->default('UTC');
            $table->string('language')->default('en');

            $table->string('fcm_token')->nullable();
            $table->string('device_id')->nullable();
            $table->string('device_type')->nullable();
            //reset password
            $table->string('token')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('last_active_at')->nullable();
            $table->rememberToken();
            $table->string('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
