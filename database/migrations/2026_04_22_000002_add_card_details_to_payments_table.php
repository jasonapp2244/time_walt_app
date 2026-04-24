<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('card_brand')->nullable()->after('stripe_data');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->unsignedTinyInteger('card_exp_month')->nullable()->after('card_last4');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');
            $table->string('card_funding')->nullable()->after('card_exp_year');
            $table->string('card_country', 2)->nullable()->after('card_funding');
            $table->string('payment_method_type')->nullable()->after('card_country');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'card_brand',
                'card_last4',
                'card_exp_month',
                'card_exp_year',
                'card_funding',
                'card_country',
                'payment_method_type',
            ]);
        });
    }
};
