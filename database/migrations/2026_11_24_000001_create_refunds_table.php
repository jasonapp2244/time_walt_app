<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin refunds of a deposit (all of it or part of it) back to the card that
 * paid it. A deposit may be refunded in several parts.
 *
 * One row per refund attempt. The money columns record where the refund was
 * funded from: vault_amount came out of the hold's remaining_amount and
 * reversed_amount was pulled back from the user's Connect account first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('payment_hold_id')->nullable()->constrained('payment_holds')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            // One per opened refund popup: a resubmitted form cannot refund twice.
            $table->uuid('request_token')->nullable()->unique();
            $table->text('stripe_refund_id')->nullable();
            $table->string('stripe_refund_id_index', 64)->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->decimal('vault_amount', 10, 2)->default(0);
            $table->decimal('reversed_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('card_brand')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->string('reason_note', 500)->nullable();
            $table->enum('source', ['admin', 'stripe_dashboard'])->default('admin');
            $table->enum('status', ['pending', 'succeeded', 'failed', 'canceled'])->default('pending');
            $table->string('hold_status_before', 32)->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('reversals')->nullable();
            $table->string('arn')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('source');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('amount');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->decimal('reversed_amount', 10, 2)->default(0)->after('amount');
        });

        DB::statement("ALTER TABLE `payment_holds` MODIFY COLUMN `status` ENUM('holding', 'ready_for_transfer', 'partial_transferred', 'transferred', 'canceled', 'refunded') DEFAULT 'holding'");
    }

    public function down(): void
    {
        DB::statement("UPDATE `payment_holds` SET `status` = 'canceled' WHERE `status` = 'refunded'");
        DB::statement("ALTER TABLE `payment_holds` MODIFY COLUMN `status` ENUM('holding', 'ready_for_transfer', 'partial_transferred', 'transferred', 'canceled') DEFAULT 'holding'");

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('reversed_amount');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['refunded_amount', 'refunded_at']);
        });

        Schema::dropIfExists('refunds');
    }
};
