<?php

namespace App\Console\Commands;

use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\Transfer;
use Illuminate\Console\Command;

class FixHoldRemainingAmount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:hold-remaining-amount';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix payment holds with incorrect remaining_amount and status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fixing payment holds with incorrect remaining_amount...');

        $fixedCount = 0;
        $checkedCount = 0;

        // Refunded holds are settled: their money went back to the card, so
        // recomputing them from transfers alone would put it back in the vault.
        $holds = PaymentHold::with('transfer')->where('status', '!=', 'refunded')->get();

        foreach ($holds as $hold) {
            $checkedCount++;

            // Calculate actual remaining amount from transfers (less any part
            // pulled back for a refund) and from refunds of this deposit
            $totalTransferred = (float) Transfer::where('hold_id', $hold->id)
                ->whereIn('status', ['completed', 'pending', 'processing'])
                ->sum(\Illuminate\Support\Facades\DB::raw('amount - reversed_amount'));

            $totalRefunded = (float) Refund::where('payment_hold_id', $hold->id)
                ->whereIn('status', Refund::ACTIVE_STATUSES)
                ->sum('amount');

            $actualRemaining = max(0, round($hold->amount - $totalTransferred - $totalRefunded, 2));

            // Check if there's a data inconsistency
            $needsFix = false;
            $currentRemaining = $hold->remaining_amount ?? $hold->amount;

            // Fix 1: remaining_amount is null or incorrect
            if ($hold->remaining_amount === null || abs($currentRemaining - $actualRemaining) > 0.01) {
                $needsFix = true;
            }

            // Fix 2: status is 'transferred' but remaining_amount > 0
            if ($hold->status === 'transferred' && $actualRemaining > 0) {
                $needsFix = true;
            }

            // Fix 3: status should be 'transferred' but remaining_amount = 0
            if ($hold->status !== 'transferred' && $actualRemaining <= 0 && $totalTransferred > 0) {
                $needsFix = true;
            }

            if ($needsFix) {
                $correctStatus = $actualRemaining <= 0 ? 'transferred' : ($hold->status === 'transferred' ? 'partial_transferred' : $hold->status);

                $hold->update([
                    'remaining_amount' => $actualRemaining,
                    'status' => $correctStatus,
                    'transferred_at' => $actualRemaining <= 0 && $totalTransferred > 0 ? ($hold->transferred_at ?? now()) : $hold->transferred_at,
                ]);

                $this->info("✅ Fixed Hold ID: {$hold->id} - Amount: {$hold->amount}, Remaining: {$actualRemaining}, Status: {$correctStatus}");
                $fixedCount++;
            }
        }

        $this->info("✅ Checked {$checkedCount} holds, fixed {$fixedCount} inconsistencies.");

        return Command::SUCCESS;
    }
}
