<?php

namespace Tests\Feature\Support;

use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSupportIndexTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_it_lists_support_requests_with_summary_counts(): void
    {
        $adminTimezone = config('app.admin_timezone', 'UTC');

        SupportRequest::factory()->count(2)->create();
        SupportRequest::factory()->create([
            'created_at' => now($adminTimezone)->subDays(3)->utc(),
        ]);
        SupportRequest::factory()->create([
            'created_at' => now($adminTimezone)->subDays(20)->utc(),
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/support');

        $response->assertOk()
            ->assertViewIs('admin.support.index')
            ->assertViewHas('totalCount', 4)
            ->assertViewHas('todayCount', 2)
            ->assertViewHas('weekCount', 3);
    }

    public function test_the_counts_are_zero_when_there_are_no_requests(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/support')
            ->assertOk()
            ->assertViewHas('totalCount', 0)
            ->assertViewHas('todayCount', 0)
            ->assertViewHas('weekCount', 0)
            ->assertSee('No support requests found');
    }

    public function test_it_searches_by_subject_and_message(): void
    {
        SupportRequest::factory()->create([
            'subject' => 'Withdrawal stuck',
            'message' => 'Nothing arrived in my account.',
        ]);
        SupportRequest::factory()->create([
            'subject' => 'Card declined',
            'message' => 'My card will not go through.',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/support?search=Withdrawal')
            ->assertOk()
            ->assertSee('Withdrawal stuck')
            ->assertDontSee('Card declined');

        $this->actingAs($this->admin())
            ->get('/admin/support?search=will not go through')
            ->assertOk()
            ->assertSee('Card declined')
            ->assertDontSee('Withdrawal stuck');
    }

    public function test_it_searches_by_the_users_full_email_through_the_blind_index(): void
    {
        $user = User::factory()->create(['email' => 'dana.holloway@example.test']);

        SupportRequest::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Danas request',
            'message' => 'Opened by Dana.',
        ]);
        SupportRequest::factory()->create([
            'subject' => 'Someone elses request',
            'message' => 'Opened by another user.',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/support?search=dana.holloway@example.test')
            ->assertOk()
            ->assertSee('Danas request')
            ->assertDontSee('Someone elses request');
    }

    public function test_the_summary_counts_stay_global_when_filtered(): void
    {
        SupportRequest::factory()->create(['subject' => 'Withdrawal stuck']);
        SupportRequest::factory()->create(['subject' => 'Card declined']);

        $response = $this->actingAs($this->admin())->get('/admin/support?search=Withdrawal');

        $response->assertOk()
            ->assertViewHas('totalCount', 2);

        $this->assertCount(1, $response->viewData('supportRequests'));
    }

    public function test_it_paginates_at_fifteen_per_page(): void
    {
        SupportRequest::factory()->count(16)->create();

        $response = $this->actingAs($this->admin())->get('/admin/support');

        $response->assertOk();
        $this->assertCount(15, $response->viewData('supportRequests'));
        $this->assertSame(16, $response->viewData('supportRequests')->total());
    }

    public function test_it_shows_the_submitting_users_name_and_email(): void
    {
        $user = User::factory()->create([
            'full_name' => 'Dana Holloway',
            'email' => 'dana.holloway@example.test',
        ]);

        SupportRequest::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Withdrawal not received',
            'message' => 'The transfer has been pending for three days.',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/support')
            ->assertOk()
            ->assertSee('Dana Holloway')
            ->assertSee('dana.holloway@example.test')
            ->assertSee('Withdrawal not received')
            ->assertSee('The transfer has been pending for three days.');
    }

    public function test_it_renders_a_request_from_an_undecryptable_user(): void
    {
        $user = User::factory()->create();

        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update([
            'email' => 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=',
            'full_name' => 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=',
        ]);

        SupportRequest::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Locked out',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/support')
            ->assertOk()
            ->assertSee('[unreadable]')
            ->assertSee('Locked out');
    }

    public function test_non_admin_users_are_redirected_to_the_admin_login(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get('/admin/support')
            ->assertRedirect(route('admin.login'));
    }

    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get('/admin/support')->assertRedirect(route('admin.login'));
    }
}
