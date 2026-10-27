<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes tester accounts before go-live. Admins are never touched, and the
 * ids passed with --keep stay. Deleting a user cascades to its payments,
 * holds, transfers, bank and Stripe rows, subscriptions, feedback and
 * support requests.
 */
class RemoveTestAccounts extends Command
{
    protected $signature = 'users:remove-test-accounts
        {--keep=* : Id of a user to keep (repeat for several)}
        {--force : Delete the accounts (without it, they are only listed)}';

    protected $description = 'List or delete every non-admin user account except the ones kept with --keep';

    public function handle(): int
    {
        $keep = array_map('intval', (array) $this->option('keep'));

        $users = User::query()
            ->where(fn ($q) => $q->where('role', '!=', 'admin')->orWhereNull('role'))
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No non-admin accounts.');

            return self::SUCCESS;
        }

        $this->table(['Id', 'Name', 'Email', 'Action'], $users->map(fn (User $user) => [
            $user->id,
            $user->safe('full_name'),
            $user->safe('email'),
            in_array($user->id, $keep, true) ? 'keep' : 'delete',
        ])->all());

        $delete = $users->reject(fn (User $user) => in_array($user->id, $keep, true));
        $this->line("To delete: {$delete->count()}, kept: ".($users->count() - $delete->count()));

        if ($delete->isEmpty()) {
            return self::SUCCESS;
        }
        if (! $this->option('force')) {
            $this->warn('Dry run: nothing deleted. Run again with --force (and --keep=ID for each account to keep).');

            return self::SUCCESS;
        }

        $ids = $delete->pluck('id')->all();
        DB::transaction(fn () => User::whereKey($ids)->delete());

        Log::warning('Test accounts removed', ['user_ids' => $ids, 'kept' => $keep]);
        $this->info('Deleted '.count($ids).' accounts.');

        return self::SUCCESS;
    }
}
