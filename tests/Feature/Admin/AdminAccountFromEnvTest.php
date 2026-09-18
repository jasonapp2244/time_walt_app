<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\AdminAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The panel account is defined by .env, not by the users table, so restoring a
 * database dump cannot lock the admin out.
 */
class AdminAccountFromEnvTest extends TestCase
{
    use RefreshDatabase;

    private const BOGUS = 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=';

    private const EMAIL = 'panel.admin@timevaultapp.co';

    private const PASSWORD = 'env-defined-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.admin_panel_email' => self::EMAIL,
            'app.admin_panel_password' => self::PASSWORD,
        ]);
    }

    public function test_it_creates_the_admin_when_the_table_is_empty(): void
    {
        $this->assertDatabaseCount('users', 0);

        $admin = AdminAccount::sync();

        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertSame(self::EMAIL, $admin->email);
        $this->assertSame('active', $admin->status);
    }

    public function test_syncing_twice_does_not_create_a_second_admin(): void
    {
        AdminAccount::sync();
        AdminAccount::sync();

        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_it_repairs_an_imported_admin_with_unreadable_pii(): void
    {
        $existing = User::factory()->create(['role' => 'admin']);

        // Simulate a restored dump: PII encrypted under a different APP_KEY
        // and a password nobody knows.
        DB::table('users')->where('id', $existing->id)->update([
            'email' => self::BOGUS,
            'full_name' => self::BOGUS,
            'phone' => self::BOGUS,
            'email_index' => 'stale-index',
            'password' => bcrypt('nobody-knows-this'),
        ]);

        $admin = AdminAccount::sync();

        $this->assertSame($existing->id, $admin->id, 'should repair in place, not duplicate');
        $this->assertSame(self::EMAIL, $admin->email);
        $this->assertSame('TimeVault Admin', $admin->full_name);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_login_succeeds_with_env_credentials_against_an_empty_table(): void
    {
        $this->assertDatabaseCount('users', 0);

        $this->post('/admin/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_login_succeeds_after_a_dump_restore_broke_the_admin_row(): void
    {
        $existing = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $existing->id)->update([
            'email' => self::BOGUS,
            'full_name' => self::BOGUS,
            'email_index' => 'stale-index',
            'password' => bcrypt('nobody-knows-this'),
        ]);

        $this->post('/admin/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_the_env_password_is_case_and_content_sensitive(): void
    {
        $this->post('/admin/login', [
            'email' => self::EMAIL,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_env_email_is_matched_case_insensitively(): void
    {
        $this->post('/admin/login', [
            'email' => strtoupper(self::EMAIL),
            'password' => self::PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_nothing_is_configured_when_the_env_values_are_missing(): void
    {
        config(['app.admin_panel_email' => null, 'app.admin_panel_password' => null]);

        $this->assertFalse(AdminAccount::isConfigured());
        $this->assertNull(AdminAccount::sync());
        $this->assertFalse(AdminAccount::credentialsMatch('', ''));
    }

    public function test_a_database_admin_can_still_log_in_normally(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'email' => 'other.admin@timevaultapp.co',
            'password' => bcrypt('their-own-password'),
            'status' => 'active',
        ]);

        $this->post('/admin/login', [
            'email' => 'other.admin@timevaultapp.co',
            'password' => 'their-own-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_artisan_command_syncs_the_account(): void
    {
        $this->artisan('admin:sync')
            ->expectsOutputToContain('Admin account synced from .env')
            ->assertExitCode(0);

        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_the_artisan_command_fails_when_env_is_not_configured(): void
    {
        config(['app.admin_panel_email' => null, 'app.admin_panel_password' => null]);

        $this->artisan('admin:sync')->assertExitCode(1);
    }
}
