<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create first test user for testing
        // email_index and phone_index must be set explicitly because WithoutModelEvents
        // disables the saving observer that normally auto-computes them.
        $user = User::create([
            'role' => 'user',
            'full_name' => 'Test User',
            'email' => 'test@example.com',
            'email_index' => User::blindIndex('test@example.com'),
            'phone' => '1234567890',
            'phone_index' => User::blindIndex('1234567890'),
            'password' => Hash::make('Test@123'),
            'profile' => 'default.png',
            'is_verified' => true,
            'status' => 'active',
            'email_verified_at' => now(),
            'two_factor_enabled' => false,
            'timezone' => 'UTC',
            'language' => 'en',
            'last_active_at' => now(),
        ]);

        // Create notification settings for the test user
        UserNotificationSetting::create([
            'user_id' => $user->id,
            'password_alert' => true,
            'transaction_alert' => true,
            'push_notification_alert' => true,
            'email_alert' => true,
            'lock_alert' => true,
            'unlock_alert' => true,
        ]);

        // Seed Privacy Policy
        $this->call(PrivacyPolicySeeder::class);

        // Seed Admin user
        $this->call(AdminSeeder::class);
    }
}
