<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * users:remove-test-accounts, the go-live cleanup of tester accounts.
 */
class RemoveTestAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tester;

    private User $kept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->tester = User::factory()->create(['full_name' => 'Daniel Brooks']);
        $this->kept = User::factory()->create(['full_name' => 'Sarah Mitchell']);
        Payment::factory()->create(['user_id' => $this->tester->id]);
    }

    public function test_dry_run_lists_accounts_and_deletes_nothing(): void
    {
        $this->artisan('users:remove-test-accounts')
            ->expectsOutputToContain('Daniel Brooks')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 3);
    }

    public function test_force_deletes_non_admins_except_kept_ones_with_their_records(): void
    {
        $this->artisan('users:remove-test-accounts', ['--keep' => [(string) $this->kept->id], '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $this->tester->id]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('users', ['id' => $this->kept->id]);
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_an_unreadable_account_is_listed_and_deleted(): void
    {
        DB::table('users')->where('id', $this->tester->id)->update([
            'email' => 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=',
        ]);

        $this->artisan('users:remove-test-accounts', ['--force' => true])
            ->expectsOutputToContain('[unreadable]')
            ->assertSuccessful();

        $this->assertSame([$this->admin->id], User::pluck('id')->all());
    }

    public function test_keep_since_keeps_recent_accounts_and_removes_tokens_of_deleted_users(): void
    {
        DB::table('users')->where('id', $this->tester->id)->update(['created_at' => '2026-09-15 10:00:00']);
        DB::table('users')->where('id', $this->kept->id)->update(['created_at' => '2026-10-20 10:00:00']);
        $this->tester->createToken('auth_token');
        $this->kept->createToken('auth_token');
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class, 'tokenable_id' => 999999, 'name' => 'auth_token',
            'token' => str_repeat('a', 64), 'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('users:remove-test-accounts', ['--keep-since' => '2026-10-01', '--force' => true])
            ->expectsOutputToContain('Deleted 1 accounts and 2 API tokens.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $this->tester->id]);
        $this->assertDatabaseHas('users', ['id' => $this->kept->id]);
        $this->assertSame(
            [$this->kept->id],
            DB::table('personal_access_tokens')->pluck('tokenable_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_a_bad_keep_since_date_fails_without_deleting(): void
    {
        $this->artisan('users:remove-test-accounts', ['--keep-since' => 'last month', '--force' => true])
            ->assertFailed();

        $this->assertDatabaseCount('users', 3);
    }
}
