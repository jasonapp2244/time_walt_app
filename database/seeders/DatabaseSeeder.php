<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create test user for testing
        User::factory()->create([
            'full_name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '1234567890',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'is_verified' => true,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
