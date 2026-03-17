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
        $adminEmail = config('app.admin_panel_email', 'admin@timevault.com');

        $emailIndex = User::blindIndex(strtolower($adminEmail));

        if (User::where('email_index', $emailIndex)->exists()) {
            $this->command->info('Admin user already exists. Skipping.');

            return;
        }

        User::create([
            'role' => 'admin',
            'full_name' => 'Time Vault Admin',
            'email' => $adminEmail,
            'email_index' => $emailIndex,
            'phone' => '0000000000',
            'phone_index' => User::blindIndex('0000000000'),
            'password' => Hash::make(config('app.admin_panel_password', 'admin@timevault.com')),
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
