<?php

namespace App\Console\Commands;

use App\Models\StripeConnectAccount;
use App\Models\StripeCustomer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off cleanup for the switch to a new Stripe account or mode (test → live).
 *
 * Every Stripe id the app saved (customers, Connect accounts, bank accounts,
 * payments, subscriptions) belongs to the old account and fails under the new
 * key ("No such customer"), and the money behind old holds can't be paid out
 * from the new account. This removes that money/Stripe history and resets the
 * free-tier counters. Users, plans, settings, feedback and support requests are
 * kept; the plan seeder relinks prices to the new account.
 *
 * Run it AFTER the new keys are in .env: it refuses while any saved customer or
 * Connect account still exists under the current key, so it can't wipe data
 * that belongs to the account the app now uses.
 */
class ResetStripeTestData extends Command
{
    protected $signature = 'stripe:reset-test-data
        {--force : Delete the rows (without it, only the counts are shown)}';

    protected $description = 'Remove Stripe data left from the previous Stripe account/mode (run once after switching keys)';

    /** Child tables before parents, so foreign keys never block a delete. */
    private const TABLES = [
        'transfers',
        'payment_holds',
        'payments',
        'subscription_invoices',
        'subscriptions',
        'stripe_customers',
        'stripe_connect_accounts',
        'user_bank_accounts',
        'stripe_webhook_events',
    ];

    public function handle(): int
    {
        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            $this->error('STRIPE_SECRET is not set.');

            return self::FAILURE;
        }
        $this->line('Current Stripe key mode: '.(str_starts_with($secret, 'sk_live_') ? 'LIVE' : 'test'));

        $counts = collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
        $usersWithUsage = DB::table('users')->where(fn ($q) => $q->where('free_locks_used', '>', 0)->orWhere('free_withdrawals_used', '>', 0))->count();

        $this->table(['Table', 'Rows to delete'], $counts->map(fn ($n, $table) => [$table, $n])->values()->all());
        $this->line("Users whose free-tier counters reset to 0: {$usersWithUsage}");

        if ($counts->sum() === 0 && $usersWithUsage === 0) {
            $this->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        \Stripe\Stripe::setApiKey($secret);
        try {
            $current = $this->idsInCurrentAccount();
        } catch (\Throwable $e) {
            $this->error('Could not check the saved ids with Stripe, nothing deleted: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($current !== []) {
            $this->error('These saved ids exist in the Stripe account the current key belongs to, so this data is not stale. Nothing deleted:');
            foreach ($current as $line) {
                $this->line("  {$line}");
            }
            $this->line('Switch STRIPE_SECRET to the new account first (and run config:cache on the server).');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('Dry run: nothing deleted. Run again with --force to delete.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }
            DB::table('users')->update(['free_locks_used' => 0, 'free_withdrawals_used' => 0]);
        });

        Log::warning('Stripe data from the previous account/mode removed', ['rows' => $counts->all(), 'users_reset' => $usersWithUsage]);
        $this->info('Done: '.$counts->sum()." rows deleted, {$usersWithUsage} users' free-tier counters reset.");

        return self::SUCCESS;
    }

    /**
     * Saved customers and Connect accounts that the current key can still see.
     * Ids from another account or mode come back as "resource_missing";
     * a customer deleted in Stripe comes back with deleted=true.
     *
     * @return list<string>
     */
    private function idsInCurrentAccount(): array
    {
        $found = [];
        $lookups = [
            [StripeCustomer::query(), 'stripe_customer_id', fn (string $id) => \Stripe\Customer::retrieve($id)],
            [StripeConnectAccount::query(), 'connect_account_id', fn (string $id) => \Stripe\Account::retrieve($id)],
        ];

        foreach ($lookups as [$query, $column, $retrieve]) {
            foreach ($query->get() as $row) {
                try {
                    $id = $row->{$column};
                } catch (\Throwable) {
                    continue; // written under a previous APP_KEY; can't be a usable id either way
                }
                if (! $id) {
                    continue;
                }
                try {
                    $object = $retrieve($id);
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    if ($e->getStripeCode() === 'resource_missing') {
                        continue;
                    }
                    throw $e;
                } catch (\Stripe\Exception\PermissionException) {
                    continue; // a Connect account that belongs to another platform
                }
                if (! ($object->deleted ?? false)) {
                    $found[] = "{$id} (user #{$row->user_id})";
                }
            }
        }

        return $found;
    }
}
