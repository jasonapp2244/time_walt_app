<?php

namespace Tests\Feature\Support;

use App\Jobs\SendSupportAcknowledgement;
use App\Jobs\SendSupportNotification;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubmitSupportTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    public function test_authenticated_user_can_submit_a_support_request(): void
    {
        Queue::fake();

        $user = $this->user(['timezone' => 'UTC']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/support', [
                'subject' => 'Withdrawal not received',
                'message' => 'I requested a withdrawal three days ago and the bank shows nothing.',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Support request submitted successfully.',
                'data' => [
                    'support_request' => [
                        'subject' => 'Withdrawal not received',
                        'message' => 'I requested a withdrawal three days ago and the bank shows nothing.',
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => ['support_request' => ['id', 'subject', 'message', 'created_at']],
            ]);

        $this->assertDatabaseHas('support_requests', [
            'user_id' => $user->id,
            'subject' => 'Withdrawal not received',
            'message' => 'I requested a withdrawal three days ago and the bank shows nothing.',
        ]);
    }

    public function test_it_queues_the_admin_notification(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/support', [
                'subject' => 'Cannot add my bank',
                'message' => 'The add bank screen closes without saving.',
            ])
            ->assertOk();

        $supportRequest = SupportRequest::sole();

        Queue::assertPushed(
            SendSupportNotification::class,
            fn (SendSupportNotification $job) => $job->supportRequest->is($supportRequest)
        );
    }

    public function test_it_queues_the_user_acknowledgement(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/support', [
                'subject' => 'Cannot add my bank',
                'message' => 'The add bank screen closes without saving.',
            ])
            ->assertOk();

        $supportRequest = SupportRequest::sole();

        Queue::assertPushed(
            SendSupportAcknowledgement::class,
            fn (SendSupportAcknowledgement $job) => $job->supportRequest->is($supportRequest)
        );
    }

    public function test_it_trims_surrounding_whitespace(): void
    {
        Queue::fake();

        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/support', [
                'subject' => '   padded subject   ',
                'message' => '   padded message   ',
            ])
            ->assertOk();

        $this->assertDatabaseHas('support_requests', [
            'user_id' => $user->id,
            'subject' => 'padded subject',
            'message' => 'padded message',
        ]);
    }

    public function test_it_rejects_a_missing_subject(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/support', ['message' => 'No subject supplied.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject'])
            ->assertJsonPath('errors.subject.0', 'The subject field is required.');

        $this->assertDatabaseCount('support_requests', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_missing_message(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/support', ['subject' => 'Just a subject'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message'])
            ->assertJsonPath('errors.message.0', 'The message field is required.');

        $this->assertDatabaseCount('support_requests', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_subject_longer_than_150_characters(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/support', [
                'subject' => str_repeat('a', 151),
                'message' => 'Subject too long.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject']);

        $this->assertDatabaseCount('support_requests', 0);
    }

    public function test_it_rejects_a_message_longer_than_2000_characters(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/support', [
                'subject' => 'Message too long',
                'message' => str_repeat('a', 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);

        $this->assertDatabaseCount('support_requests', 0);
    }

    public function test_it_accepts_a_message_at_the_2000_character_limit(): void
    {
        Queue::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/profile/support', [
                'subject' => 'At the limit',
                'message' => str_repeat('a', 2000),
            ])
            ->assertOk();

        $this->assertDatabaseCount('support_requests', 1);
    }

    public function test_guests_cannot_submit_a_support_request(): void
    {
        Queue::fake();

        $this->postJson('/api/profile/support', [
            'subject' => 'Anonymous',
            'message' => 'Sent without a token.',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('support_requests', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_created_at_in_the_users_timezone(): void
    {
        Queue::fake();

        $user = $this->user(['timezone' => 'America/New_York']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/support', [
                'subject' => 'Timezone check',
                'message' => 'Checking the returned timestamp.',
            ])->assertOk();

        $createdAt = $response->json('data.support_request.created_at');

        $this->assertNotNull($createdAt);
        $this->assertSame(
            SupportRequest::sole()->created_at->setTimezone('America/New_York')->toIso8601String(),
            $createdAt
        );
    }
}
