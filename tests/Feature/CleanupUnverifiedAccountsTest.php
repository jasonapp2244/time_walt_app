<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanupUnverifiedAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function staleUnverifiedUser(): User
    {
        return User::factory()->create([
            'is_verified' => false,
            'status' => 'pending',
            'created_at' => now()->subDays(3),
        ]);
    }

    public function test_it_deletes_a_stale_unverified_account(): void
    {
        $user = $this->staleUnverifiedUser();

        $this->artisan('users:cleanup-unverified')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /**
     * Reproduces the production failure: user 1 could never be cleaned up
     * because its encrypted email was written under a previous APP_KEY, and
     * the command read that attribute before calling delete().
     */
    public function test_it_deletes_an_account_whose_email_cannot_be_decrypted(): void
    {
        $user = $this->staleUnverifiedUser();

        // Corrupt the ciphertext so decryption raises "The MAC is invalid."
        DB::table('users')->where('id', $user->id)->update([
            'email' => 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=',
        ]);

        // Guard the guard: confirm the attribute really does throw on read.
        $threw = false;
        try {
            User::withoutGlobalScopes()->find($user->id)->email;
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'expected the corrupted email to throw on decrypt');

        $this->artisan('users:cleanup-unverified')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_it_leaves_verified_and_active_accounts_alone(): void
    {
        $verified = User::factory()->create([
            'is_verified' => true,
            'status' => 'active',
            'created_at' => now()->subDays(3),
        ]);

        $recent = User::factory()->create([
            'is_verified' => false,
            'status' => 'pending',
            'created_at' => now()->subHour(),
        ]);

        $this->artisan('users:cleanup-unverified')->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $verified->id]);
        $this->assertDatabaseHas('users', ['id' => $recent->id]);
    }
}
