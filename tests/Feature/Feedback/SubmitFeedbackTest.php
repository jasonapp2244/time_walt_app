<?php

namespace Tests\Feature\Feedback;

use App\Jobs\SendFeedbackNotification;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubmitFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    public function test_authenticated_user_can_submit_feedback(): void
    {
        Queue::fake();

        $user = $this->user(['timezone' => 'UTC']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/feedback', [
                'rating' => 4,
                'feedback' => 'The hold timer is clear and the payouts arrive on time.',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Feedback submitted successfully.',
                'data' => [
                    'feedback' => [
                        'rating' => 4,
                        'feedback' => 'The hold timer is clear and the payouts arrive on time.',
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => ['feedback' => ['id', 'rating', 'feedback', 'created_at']],
            ]);

        $this->assertDatabaseHas('feedbacks', [
            'user_id' => $user->id,
            'rating' => 4,
            'message' => 'The hold timer is clear and the payouts arrive on time.',
        ]);
    }

    public function test_it_accepts_the_legacy_ratting_spelling(): void
    {
        Queue::fake();

        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/feedback', [
                'ratting' => 2,
                'feedback' => 'Sent from an older build of the app.',
            ])
            ->assertOk()
            ->assertJsonPath('data.feedback.rating', 2);

        $this->assertDatabaseHas('feedbacks', [
            'user_id' => $user->id,
            'rating' => 2,
        ]);
    }

    public function test_it_queues_the_admin_notification(): void
    {
        Queue::fake();

        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/feedback', [
                'rating' => 5,
                'feedback' => 'Excellent support.',
            ])
            ->assertOk();

        $feedback = Feedback::sole();

        Queue::assertPushed(
            SendFeedbackNotification::class,
            fn (SendFeedbackNotification $job) => $job->feedback->is($feedback)
        );
    }

    public function test_it_trims_surrounding_whitespace_from_the_message(): void
    {
        Queue::fake();

        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/feedback', [
                'rating' => 3,
                'feedback' => '   padded on both sides   ',
            ])
            ->assertOk();

        $this->assertDatabaseHas('feedbacks', [
            'user_id' => $user->id,
            'message' => 'padded on both sides',
        ]);
    }

    public function test_it_rejects_a_missing_rating_with_the_standard_error_shape(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/feedback', [
                'feedback' => 'No stars supplied.',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rating'])
            ->assertJsonPath('errors.rating.0', 'The rating field is required.');

        $this->assertDatabaseCount('feedbacks', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_missing_message(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/feedback', ['rating' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['feedback']);

        $this->assertDatabaseCount('feedbacks', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('outOfRangeRatings')]
    public function test_it_rejects_ratings_outside_one_to_five(mixed $rating): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/feedback', [
                'rating' => $rating,
                'feedback' => 'Out of range.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);

        $this->assertDatabaseCount('feedbacks', 0);
    }

    public static function outOfRangeRatings(): array
    {
        return [
            'zero' => [0],
            'six' => [6],
            'negative' => [-1],
            'non numeric' => ['excellent'],
        ];
    }

    public function test_it_rejects_a_message_longer_than_1000_characters(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/feedback', [
                'rating' => 3,
                'feedback' => str_repeat('a', 1001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['feedback']);

        $this->assertDatabaseCount('feedbacks', 0);
    }

    public function test_guests_cannot_submit_feedback(): void
    {
        Queue::fake();

        $this->postJson('/api/profile/feedback', [
            'rating' => 5,
            'feedback' => 'Anonymous praise.',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('feedbacks', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_created_at_in_the_users_timezone(): void
    {
        Queue::fake();

        $user = $this->user(['timezone' => 'America/New_York']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/feedback', [
                'rating' => 5,
                'feedback' => 'Timezone check.',
            ])->assertOk();

        $createdAt = $response->json('data.feedback.created_at');

        $this->assertNotNull($createdAt);
        $this->assertSame(
            Feedback::sole()->created_at->setTimezone('America/New_York')->toIso8601String(),
            $createdAt
        );
    }
}
