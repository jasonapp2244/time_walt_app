<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes tester accounts before go-live. Admins are never touched, and the
 * ids passed with --keep (or accounts created on/after --keep-since) stay.
 * Deleting a user cascades to its payments, holds, transfers, bank and Stripe
 * rows, subscriptions, feedback and support requests. API tokens and sessions
 * have no foreign key, so they are removed here, along with tokens already
 * left behind by accounts deleted earlier.
 */
class RemoveTestAccounts extends Command
{
    protected $signature = 'users:remove-test-accounts
        {--keep=* : Id of a user to keep (repeat for several)}
        {--keep-since= : Also keep every account created on or after this date (YYYY-MM-DD)}
        {--force : Delete the accounts (without it, they are only listed)}';

    protected $description = 'List or delete every non-admin user account except the ones kept with --keep or --keep-since';

    public function handle(): int
    {
        $keep = array_map('intval', (array) $this->option('keep'));

        $since = null;
        if ($this->option('keep-since') !== null) {
            $since = Carbon::canBeCreatedFromFormat((string) $this->option('keep-since'), 'Y-m-d')
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('keep-since'))
                : null;
            if (! $since) {
                $this->error('--keep-since must be a date like 2026-10-01.');

                return self::FAILURE;
            }
        }

        $isKept = fn (User $user) => in_array($user->id, $keep, true)
            || ($since && $user->created_at && $user->created_at->gte($since));

        $users = User::query()
            ->where(fn ($q) => $q->where('role', '!=', 'admin')->orWhereNull('role'))
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No non-admin accounts.');

            return self::SUCCESS;
        }

        $this->table(['Id', 'Name', 'Email', 'Created', 'Action'], $users->map(fn (User $user) => [
            $user->id,
            $user->safe('full_name'),
            $user->safe('email'),
            $user->created_at?->toDateString(),
            $isKept($user) ? 'keep' : 'delete',
        ])->all());

        $delete = $users->reject($isKept);
        $this->line("To delete: {$delete->count()}, kept: ".($users->count() - $delete->count()));

        if ($delete->isEmpty()) {
            return self::SUCCESS;
        }
        if (! $this->option('force')) {
            $this->warn('Dry run: nothing deleted. Run again with --force.');

            return self::SUCCESS;
        }

        $ids = $delete->pluck('id')->all();
        $tokens = DB::transaction(function () use ($ids) {
            User::whereKey($ids)->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();

            return DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereNotIn('tokenable_id', User::query()->select('id'))
                ->delete();
        });

        Log::warning('Test accounts removed', [
            'user_ids' => $ids,
            'kept' => $keep,
            'kept_since' => $since?->toDateString(),
            'tokens_removed' => $tokens,
        ]);
        $this->info('Deleted '.count($ids)." accounts and {$tokens} API tokens.");

        return self::SUCCESS;
    }
}
