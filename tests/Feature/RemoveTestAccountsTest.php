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
}
