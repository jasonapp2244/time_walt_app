<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupUnverifiedAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:cleanup-unverified {--hours=24 : Delete accounts older than this many hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete unverified accounts older than specified hours (default: 24 hours)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = $this->option('hours');

        $this->info("Searching for unverified accounts older than {$hours} hours...");

        // Find old unverified accounts
        $accounts = User::where('is_verified', false)
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHours($hours))
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No unverified accounts found to clean up.');

            return 0;
        }

        $this->info("Found {$accounts->count()} unverified accounts to delete.");

        $bar = $this->output->createProgressBar($accounts->count());
        $bar->start();

        $deletedCount = 0;
        foreach ($accounts as $account) {
            try {
                // Log before deletion
                Log::info('Cleaning up unverified account', [
                    'user_id' => $account->id,
                    'email' => $account->email,
                    'created_at' => $account->created_at,
                    'age_hours' => $account->created_at->diffInHours(now()),
                ]);

                // Delete the account
                $account->delete();
                $deletedCount++;

                $bar->advance();
            } catch (\Exception $e) {
                Log::error('Failed to delete unverified account', [
                    'user_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("\nFailed to delete account ID {$account->id}: {$e->getMessage()}");
            }
        }

        $bar->finish();
        $this->newLine();

        $this->info("✅ Successfully deleted {$deletedCount} unverified accounts.");

        Log::info('Unverified accounts cleanup completed', [
            'deleted_count' => $deletedCount,
            'hours_threshold' => $hours,
        ]);

        return 0;
    }
}
