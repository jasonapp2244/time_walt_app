<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each auth endpoint has its own rate-limit counter. They used to share one
 * per IP, so a normal sign-up flow could lock the user out of logging in.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_earlier_sign_up_steps_do_not_use_up_the_login_limit(): void
    {
        $user = User::factory()->create(['email' => 'limits@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/check-email', ['email' => 'limits@example.com']);
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_login_is_still_limited_on_its_own(): void
    {
        User::factory()->create(['email' => 'limits@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'limits@example.com', 'password' => 'wrong-password']);
        }

        $this->postJson('/api/auth/login', ['email' => 'limits@example.com', 'password' => 'password'])
            ->assertStatus(429);
    }
}
