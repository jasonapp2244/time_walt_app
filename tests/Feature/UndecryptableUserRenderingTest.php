<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 30 production users carry email/full_name ciphertext written under a previous
 * APP_KEY. Reading those attributes throws, and "??" does not catch a thrown
 * exception - so one damaged row would 500 every admin page that lists it.
 */
class UndecryptableUserRenderingTest extends TestCase
{
    use RefreshDatabase;

    private const BOGUS = 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=';

    private function corrupt(User $user): User
    {
        DB::table('users')->where('id', $user->id)->update([
            'email' => self::BOGUS,
            'full_name' => self::BOGUS,
            'phone' => self::BOGUS,
        ]);

        return $user->fresh();
    }

    /**
     * Bank details are encrypted too, and the transfers page renders them.
     */
    private function corruptBankAccount(UserBankAccount $bank): void
    {
        DB::table('user_bank_accounts')->where('id', $bank->id)->update([
            'bank_name' => self::BOGUS,
            'account_number' => self::BOGUS,
            'account_holder_name' => self::BOGUS,
            'iban' => self::BOGUS,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_the_accessors_degrade_instead_of_throwing(): void
    {
        $user = $this->corrupt(User::factory()->create());

        $this->assertSame('[unreadable]', $user->displayName());
        $this->assertSame('[unreadable]', $user->displayEmail());
    }

    public function test_the_accessors_return_real_values_when_decryptable(): void
    {
        $user = User::factory()->create([
            'full_name' => 'Dana Holloway',
            'email' => 'dana.holloway@example.test',
        ]);

        $this->assertSame('Dana Holloway', $user->displayName());
        $this->assertSame('dana.holloway@example.test', $user->displayEmail());
    }

    public function test_the_users_list_renders_with_a_damaged_row(): void
    {
        $this->corrupt(User::factory()->create());

        $this->actingAs($this->admin())
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('[unreadable]');
    }

    public function test_the_user_detail_page_renders_with_a_damaged_row(): void
    {
        $user = $this->corrupt(User::factory()->create());

        $this->actingAs($this->admin())
            ->get('/admin/users/'.$user->id)
            ->assertOk()
            ->assertSee('[unreadable]');
    }

    public function test_the_feedback_page_renders_feedback_from_a_damaged_user(): void
    {
        $user = User::factory()->create();
        Feedback::factory()->rating(4)->create([
            'user_id' => $user->id,
            'message' => 'Submitted before the key changed.',
        ]);
        $this->corrupt($user);

        $this->actingAs($this->admin())
            ->get('/admin/feedback')
            ->assertOk()
            ->assertSee('Submitted before the key changed.')
            ->assertSee('[unreadable]');
    }

    /**
     * Every admin page that lists users must survive a damaged row - the
     * dashboard was missed on the first pass and 500-ed in production.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('adminPages')]
    public function test_every_admin_listing_page_renders_with_a_damaged_row(string $url): void
    {
        $user = User::factory()->create();

        $payment = Payment::factory()->create(['user_id' => $user->id]);
        $hold = PaymentHold::factory()->create(['user_id' => $user->id, 'payment_id' => $payment->id]);
        $bank = UserBankAccount::factory()->create(['user_id' => $user->id]);
        Transfer::factory()->create([
            'user_id' => $user->id,
            'hold_id' => $hold->id,
            'bank_account_id' => $bank->id,
        ]);
        Feedback::factory()->create(['user_id' => $user->id]);

        $this->corrupt($user);
        $this->corruptBankAccount($bank);

        $this->actingAs($this->admin())->get($url)->assertOk();
    }

    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/admin/dashboard'],
            'users' => ['/admin/users'],
            'payments' => ['/admin/payments'],
            'payment holds' => ['/admin/payment-holds'],
            'transfers' => ['/admin/transfers'],
            'feedback' => ['/admin/feedback'],
            'profile' => ['/admin/profile'],
        ];
    }

    public function test_the_admin_layout_renders_when_the_admin_itself_is_damaged(): void
    {
        $admin = $this->corrupt($this->admin());

        $this->actingAs($admin)
            ->get('/admin/feedback')
            ->assertOk()
            ->assertSee('[unreadable]');
    }
}
