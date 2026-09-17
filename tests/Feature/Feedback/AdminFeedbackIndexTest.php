<?php

namespace Tests\Feature\Feedback;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFeedbackIndexTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_it_lists_feedback_with_summary_counts(): void
    {
        $admin = $this->admin();

        Feedback::factory()->count(3)->rating(5)->create();
        Feedback::factory()->count(2)->rating(4)->create();
        Feedback::factory()->rating(1)->create();

        $response = $this->actingAs($admin)->get('/admin/feedback');

        $response->assertOk()
            ->assertViewIs('admin.feedback.index')
            ->assertViewHas('totalCount', 6)
            ->assertViewHas('ratingCounts', fn (array $counts) => $counts === [5 => 3, 4 => 2, 3 => 0, 2 => 0, 1 => 1]);

        // (5*3 + 4*2 + 1*1) / 6
        $this->assertEqualsWithDelta(4.0, $response->viewData('averageRating'), 0.001);
    }

    public function test_the_average_is_zero_when_there_is_no_feedback(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/feedback');

        $response->assertOk()
            ->assertViewHas('totalCount', 0)
            ->assertViewHas('averageRating', 0.0)
            ->assertSee('No feedback found');
    }

    public function test_it_filters_by_rating(): void
    {
        $admin = $this->admin();

        $keep = Feedback::factory()->rating(2)->create(['message' => 'Two star message']);
        Feedback::factory()->rating(5)->create(['message' => 'Five star message']);

        $response = $this->actingAs($admin)->get('/admin/feedback?rating=2');

        $response->assertOk()
            ->assertSee('Two star message')
            ->assertDontSee('Five star message');

        $feedbacks = $response->viewData('feedbacks');
        $this->assertCount(1, $feedbacks);
        $this->assertTrue($feedbacks->first()->is($keep));

        // The summary counts stay global, not filtered.
        $response->assertViewHas('totalCount', 2);
    }

    public function test_it_paginates_at_fifteen_per_page(): void
    {
        Feedback::factory()->count(16)->create();

        $response = $this->actingAs($this->admin())->get('/admin/feedback');

        $response->assertOk();
        $this->assertCount(15, $response->viewData('feedbacks'));
        $this->assertSame(16, $response->viewData('feedbacks')->total());
    }

    public function test_it_shows_the_submitting_users_name_and_email(): void
    {
        $user = User::factory()->create([
            'full_name' => 'Dana Holloway',
            'email' => 'dana.holloway@example.test',
        ]);

        Feedback::factory()->rating(4)->create([
            'user_id' => $user->id,
            'message' => 'Payouts land next day.',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/feedback')
            ->assertOk()
            ->assertSee('Dana Holloway')
            ->assertSee('dana.holloway@example.test')
            ->assertSee('Payouts land next day.');
    }

    public function test_non_admin_users_are_redirected_to_the_admin_login(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get('/admin/feedback')
            ->assertRedirect(route('admin.login'));
    }

    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get('/admin/feedback')->assertRedirect(route('admin.login'));
    }
}
