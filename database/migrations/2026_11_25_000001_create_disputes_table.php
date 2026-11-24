<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chargebacks (Stripe disputes) on deposits.
 *
 * While a dispute is open the deposit is frozen (payment_holds.disputed_at):
 * no withdrawal and no refund can move its money. When the bank decides for
 * the customer (lost), the disputed money is taken out of the vault
 * (vault_amount) and recorded on the payment (payments.disputed_amount), so it
 * can never be paid out a second time. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('payment_hold_id')->nullable()->constrained('payment_holds')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stripe_dispute_id', 100)->unique();
            $table->decimal('amount', 10, 2);
            // Part of a lost dispute that came out of the vault; the rest had already been withdrawn.
            $table->decimal('vault_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('reason', 50)->nullable();
            $table->string('stripe_status', 40);
            $table->enum('outcome', ['open', 'won', 'lost'])->default('open');
            $table->boolean('needs_attention')->default(false);
            $table->text('note')->nullable();
            $table->timestamp('evidence_due_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['outcome', 'created_at']);
        });

        Schema::table('payment_holds', function (Blueprint $table) {
            $table->timestamp('disputed_at')->nullable()->after('status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('disputed_amount', 10, 2)->default(0)->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('disputed_amount');
        });

        Schema::table('payment_holds', function (Blueprint $table) {
            $table->dropColumn('disputed_at');
        });

        Schema::dropIfExists('disputes');
    }
};
