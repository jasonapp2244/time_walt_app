<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Seed the admin user into the users table.
     */
    public function run(): void
    {
        $adminEmail = config('app.admin_panel_email');
        $adminPassword = config('app.admin_panel_password');

        // Seeding a default address and password would create a known-credential
        // admin on any environment that forgot the keys. Fail loudly instead.
        if (blank($adminEmail) || blank($adminPassword)) {
            $this->command->error('ADMIN_PANEL_EMAIL and ADMIN_PANEL_PASSWORD must be set in .env before seeding the admin user.');

            return;
        }

        $emailIndex = User::blindIndex(strtolower($adminEmail));

        if (User::where('email_index', $emailIndex)->exists()) {
            $this->command->info('Admin user already exists. Skipping.');

            return;
        }

        User::create([
            'role' => 'admin',
            'full_name' => config('app.admin_panel_name', 'TimeVault Admin'),
            'email' => $adminEmail,
            'email_index' => $emailIndex,
            'phone' => '0000000000',
            'phone_index' => User::blindIndex('0000000000'),
            'password' => Hash::make($adminPassword),
            'profile' => 'default.png',
            'is_verified' => true,
            'status' => 'active',
            'email_verified_at' => now(),
            'two_factor_enabled' => false,
            'timezone' => 'UTC',
            'language' => 'en',
            'last_active_at' => now(),
        ]);

        $this->command->info('Admin user created successfully.');
    }
}
