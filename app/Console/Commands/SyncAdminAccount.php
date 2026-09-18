<?php

namespace App\Console\Commands;

use App\Support\AdminAccount;
use Illuminate\Console\Command;

class SyncAdminAccount extends Command
{
    protected $signature = 'admin:sync';

    protected $description = 'Create or repair the admin panel account from ADMIN_PANEL_EMAIL and ADMIN_PANEL_PASSWORD';

    public function handle(): int
    {
        if (! AdminAccount::isConfigured()) {
            $this->error('ADMIN_PANEL_EMAIL and ADMIN_PANEL_PASSWORD must both be set in .env.');
            $this->line('Set them, run `php artisan config:cache`, then try again.');

            return self::FAILURE;
        }

        $admin = AdminAccount::sync();

        if (! $admin) {
            $this->error('Could not sync the admin account.');

            return self::FAILURE;
        }

        $this->info('Admin account synced from .env');
        $this->line('  id    : '.$admin->id);
        $this->line('  email : '.AdminAccount::email());
        $this->line('  status: '.$admin->status);

        return self::SUCCESS;
    }
}
