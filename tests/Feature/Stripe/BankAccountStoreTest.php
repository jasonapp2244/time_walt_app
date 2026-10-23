<?php

namespace Tests\Feature\Stripe;

use App\Models\StripeConnectAccount;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * POST /api/bank-account — adds a payout bank and, on the first one, creates
 * the user's Stripe Custom Connect account. Stripe is replaced at the HTTP
 * layer, so the controller's real SDK calls run.
 */
class BankAccountStoreTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake']);
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);

        $this->user = User::factory()->create([
            'full_name' => 'Jane Ann Doe',
            'email' => 'tauseefchoohan0401@gmail.com',
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function usPayload(array $overrides = []): array
    {
        return array_merge([
            'dob' => '1990-04-15',
            'account_number' => '000123456789',
            'bank_name' => 'Test Bank',
            'routing_number' => '110000000',
            'account_type' => 'checking',
            'country' => 'US',
            'currency' => 'usd',
            'address_line1' => '1 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '73301',
            'phone' => '+15125550100',
            'ssn_last_4' => '0000',
        ], $overrides);
    }

    private function gbPayload(array $overrides = []): array
    {
        return array_merge([
            'dob' => '1985-11-02',
            'account_number' => '00012345',
            'bank_name' => 'UK Test Bank',
            'iban' => 'GB82WEST12345698765432',
            'account_type' => 'current',
            'country' => 'GB',
            'currency' => 'gbp',
            'address_line1' => '10 High St',
            'city' => 'London',
            'state' => 'London',
            'postal_code' => 'EC1A 1BB',
            'phone' => '+442071234567',
            'id_number' => 'QQ123456C',
        ], $overrides);
    }

    private function existingConnectAccount(string $id = 'acct_existing'): StripeConnectAccount
    {
        return StripeConnectAccount::create([
            'user_id' => $this->user->id,
            'connect_account_id' => $id,
            'status' => 'verified',
        ]);
    }

    /** Stripe creates the Connect account. */
    private function stripeCreatesAccount(string $id = 'acct_new', bool $detailsSubmitted = false): void
    {
        $this->stripe->respond('POST /v1/accounts', [
            'id' => $id,
            'object' => 'account',
            'details_submitted' => $detailsSubmitted,
            'payouts_enabled' => false,
        ]);
    }

    /** Stripe attaches the external bank. Must be registered before the /v1/accounts prefix. */
    private function stripeAddsBank(string $accountId, string $bankId): void
    {
        $this->stripe->respond("POST /v1/accounts/{$accountId}/external_accounts", [
            'id' => $bankId,
            'object' => 'bank_account',
        ]);
    }

    private function store(array $payload)
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/bank-account', $payload);
    }

    public function test_first_us_bank_creates_a_connect_account_and_becomes_primary(): void
    {
        $this->stripeAddsBank('acct_new', 'ba_first');
        $this->stripeCreatesAccount('acct_new');

        $this->store($this->usPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_number', '****6789')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.country', 'US')
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonMissingPath('data.routing_number');

        $connect = StripeConnectAccount::sole();
        $this->assertSame($this->user->id, $connect->user_id);
        $this->assertSame('acct_new', $connect->connect_account_id);
        $this->assertSame('pending', $connect->status);
        $this->assertNull($connect->verified_at);

        $bank = UserBankAccount::sole();
        $this->assertTrue($bank->is_primary);
        $this->assertSame('ba_first', $bank->stripe_bank_account_id);
        $this->assertSame('Jane Ann Doe', $bank->account_holder_name);
        $this->assertSame('000123456789', $bank->account_number);

        $raw = DB::table('user_bank_accounts')->where('id', $bank->id)->value('account_number');
        $this->assertStringNotContainsString('000123456789', $raw, 'account number must be encrypted at rest');

        $sent = $this->stripe->requestsTo('POST', '/v1/accounts')[0]['params'];
        $this->assertSame('custom', $sent['type']);
        $this->assertSame('US', $sent['country']);
        $this->assertSame('individual', $sent['business_type']);
        $this->assertSame('Jane', $sent['individual']['first_name']);
        $this->assertSame('Ann Doe', $sent['individual']['last_name']);
        $this->assertEquals(['day' => 15, 'month' => 4, 'year' => 1990], $sent['individual']['dob']);
        $this->assertSame('0000', $sent['individual']['ssn_last_4']);
        $this->assertArrayNotHasKey('id_number', $sent['individual']);
        $this->assertSame((string) $this->user->id, $sent['metadata']['user_id']);

        $bankSent = $this->stripe->requestsTo('POST', '/v1/accounts/acct_new/external_accounts')[0]['params'];
        $this->assertSame('usd', $bankSent['external_account']['currency']);
        $this->assertSame('110000000', $bankSent['external_account']['routing_number']);
        $this->assertSame('000123456789', $bankSent['external_account']['account_number']);
    }

    public function test_connect_account_with_submitted_details_is_stored_as_verified(): void
    {
        $this->stripeAddsBank('acct_ready', 'ba_ready');
        $this->stripeCreatesAccount('acct_ready', detailsSubmitted: true);

        $this->store($this->usPayload())->assertCreated();

        $connect = StripeConnectAccount::sole();
        $this->assertSame('verified', $connect->status);
        $this->assertNotNull($connect->verified_at);
    }

    public function test_non_us_bank_sends_the_national_id_and_no_ssn(): void
    {
        $this->stripeAddsBank('acct_gb', 'ba_gb');
        $this->stripeCreatesAccount('acct_gb');

        $this->store($this->gbPayload(['ssn_last_4' => '1234']))
            ->assertCreated()
            ->assertJsonPath('data.currency', 'GBP');

        $sent = $this->stripe->requestsTo('POST', '/v1/accounts')[0]['params'];
        $this->assertSame('GB', $sent['country']);
        $this->assertSame('QQ123456C', $sent['individual']['id_number']);
        $this->assertArrayNotHasKey('ssn_last_4', $sent['individual']);

        $bankSent = $this->stripe->requestsTo('POST', '/v1/accounts/acct_gb/external_accounts')[0]['params'];
        $this->assertArrayNotHasKey('routing_number', $bankSent['external_account']);
        $this->assertSame('gbp', $bankSent['external_account']['currency']);

        $this->assertSame('GB82WEST12345698765432', UserBankAccount::sole()->iban);
    }

    public function test_second_bank_reuses_the_connect_account_and_is_not_primary(): void
    {
        $this->existingConnectAccount('acct_existing');
        UserBankAccount::create([
            'user_id' => $this->user->id,
            'account_holder_name' => 'Jane Ann Doe',
            'bank_name' => 'First Bank',
            'account_number' => '111122223333',
            'account_type' => 'checking',
            'country' => 'US',
            'currency' => 'usd',
            'stripe_bank_account_id' => 'ba_old',
            'is_primary' => true,
        ]);

        $this->stripe->respond('GET /v1/accounts/acct_existing', ['id' => 'acct_existing', 'object' => 'account']);
        $this->stripeAddsBank('acct_existing', 'ba_second');

        $this->store($this->usPayload())
            ->assertCreated()
            ->assertJsonPath('data.is_primary', false);

        $this->assertSame(1, StripeConnectAccount::count());
        $this->assertSame(2, UserBankAccount::count());
        $this->assertTrue(UserBankAccount::where('stripe_bank_account_id_index', UserBankAccount::blindIndex('ba_old'))->sole()->is_primary);

        $creates = array_filter(
            $this->stripe->requestsTo('POST', '/v1/accounts'),
            fn (array $r) => $r['path'] === '/v1/accounts'
        );
        $this->assertSame([], $creates, 'an existing Connect account must not be recreated');
    }

    public function test_stale_connect_account_is_replaced_and_its_banks_discarded(): void
    {
        $this->existingConnectAccount('acct_stale');
        UserBankAccount::create([
            'user_id' => $this->user->id,
            'account_holder_name' => 'Jane Ann Doe',
            'bank_name' => 'Old Bank',
            'account_number' => '999988887777',
            'account_type' => 'checking',
            'country' => 'US',
            'currency' => 'usd',
            'stripe_bank_account_id' => 'ba_stale',
            'is_primary' => false,
        ]);

        $this->stripe->respond('GET /v1/accounts/acct_stale', ['error' => [
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
            'message' => 'No such account: acct_stale',
        ]], 404);
        $this->stripeAddsBank('acct_fresh', 'ba_fresh');
        $this->stripeCreatesAccount('acct_fresh');

        $this->store($this->usPayload())
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true);

        $this->assertSame('acct_fresh', StripeConnectAccount::sole()->connect_account_id);
        $this->assertSame('ba_fresh', UserBankAccount::sole()->stripe_bank_account_id);
    }

    public function test_connect_account_from_another_stripe_key_is_treated_as_stale(): void
    {
        $this->existingConnectAccount('acct_other_platform');

        $this->stripe->respond('GET /v1/accounts/acct_other_platform', ['error' => [
            'type' => 'invalid_request_error',
            'message' => 'The provided key does not have access to account acct_other_platform.',
        ]], 403);
        $this->stripeAddsBank('acct_mine', 'ba_mine');
        $this->stripeCreatesAccount('acct_mine');

        $this->store($this->usPayload())->assertCreated();

        $this->assertSame('acct_mine', StripeConnectAccount::sole()->connect_account_id);
    }

    public function test_stripe_rejecting_the_bank_rolls_everything_back(): void
    {
        $this->stripe->respond('POST /v1/accounts/acct_new/external_accounts', ['error' => [
            'type' => 'invalid_request_error',
            'code' => 'routing_number_invalid',
            'message' => 'Routing number must have 9 digits',
        ]], 400);
        $this->stripeCreatesAccount('acct_new');

        $this->store($this->usPayload())
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to setup bank account. Please try again.')
            ->assertDontSee('000123456789')
            ->assertDontSee('Routing number must have 9 digits');

        $this->assertSame(0, StripeConnectAccount::count());
        $this->assertSame(0, UserBankAccount::count());
        $this->assertCount(1, $this->stripe->requestsTo('DELETE', '/v1/accounts/acct_new'), 'the Connect account created by this request must not be left orphaned on Stripe');
    }

    public function test_bank_rejected_after_retry_leaves_no_orphan_and_a_corrected_retry_succeeds(): void
    {
        $this->stripe->respond('POST /v1/accounts/acct_wrong/external_accounts', ['error' => [
            'type' => 'invalid_request_error',
            'message' => 'Bank country does not match account country',
        ]], 400);
        $this->stripeCreatesAccount('acct_wrong');
        $this->stripe->respond('DELETE /v1/accounts/acct_wrong', ['id' => 'acct_wrong', 'object' => 'account', 'deleted' => true]);

        $this->store($this->usPayload(['country' => 'GB', 'iban' => 'GB82WEST12345698765432', 'id_number' => 'QQ123456C']))
            ->assertStatus(500);

        $this->stripeAddsBank('acct_right', 'ba_right');
        $this->stripeCreatesAccount('acct_right');

        $this->store($this->usPayload())->assertCreated();

        $this->assertSame('acct_right', StripeConnectAccount::sole()->connect_account_id);
        $creates = array_values(array_filter(
            $this->stripe->requestsTo('POST', '/v1/accounts'),
            fn (array $r) => $r['path'] === '/v1/accounts'
        ));
        $this->assertSame(['GB', 'US'], array_column(array_column($creates, 'params'), 'country'));
    }

    public function test_failed_cleanup_still_returns_the_generic_error_and_logs_the_orphan(): void
    {
        Log::spy();

        $this->stripe->respond('POST /v1/accounts/acct_new/external_accounts', ['error' => [
            'type' => 'invalid_request_error',
            'message' => 'Routing number must have 9 digits',
        ]], 400);
        $this->stripeCreatesAccount('acct_new');
        $this->stripe->respond('DELETE /v1/accounts/acct_new', ['error' => [
            'type' => 'api_error',
            'message' => 'Stripe is down',
        ]], 500);

        $this->store($this->usPayload())
            ->assertStatus(500)
            ->assertJsonPath('message', 'Failed to setup bank account. Please try again.');

        $this->assertSame(0, StripeConnectAccount::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'orphaned')
                && $context['user_id'] === $this->user->id
                && $context['account_id_suffix'] === substr('acct_new', -6))
            ->once();
    }

    public function test_bank_rejected_on_an_existing_connect_account_does_not_delete_it(): void
    {
        $this->existingConnectAccount('acct_existing');

        $this->stripe->respond('GET /v1/accounts/acct_existing', ['id' => 'acct_existing', 'object' => 'account']);
        $this->stripe->respond('POST /v1/accounts/acct_existing/external_accounts', ['error' => [
            'type' => 'invalid_request_error',
            'message' => 'Routing number must have 9 digits',
        ]], 400);

        $this->store($this->usPayload())->assertStatus(500);

        $this->assertSame('acct_existing', StripeConnectAccount::sole()->connect_account_id);
        $this->assertSame([], $this->stripe->requestsTo('DELETE', '/v1/accounts'));
    }

    public function test_stripe_rejecting_the_connect_account_saves_nothing(): void
    {
        $this->stripe->respond('POST /v1/accounts', ['error' => [
            'type' => 'invalid_request_error',
            'message' => 'Invalid date of birth',
        ]], 400);

        $this->store($this->usPayload())->assertStatus(500);

        $this->assertSame(0, StripeConnectAccount::count());
        $this->assertSame(0, UserBankAccount::count());
        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/accounts/'));
    }

    public function test_us_bank_requires_routing_number_and_ssn_last_4(): void
    {
        $this->store($this->usPayload(['routing_number' => null, 'ssn_last_4' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['routing_number', 'ssn_last_4']);

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_non_us_bank_requires_an_iban(): void
    {
        $this->store($this->gbPayload(['iban' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['iban']);
    }

    public function test_unsupported_country_and_future_dob_are_rejected(): void
    {
        $this->store($this->usPayload(['country' => 'PK', 'dob' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['country', 'dob']);

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_guest_cannot_add_a_bank_account(): void
    {
        $this->postJson('/api/bank-account', $this->usPayload())->assertUnauthorized();

        $this->assertSame([], $this->stripe->requests);
    }
}
