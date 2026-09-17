<?php

namespace Tests\Feature\Feedback;

use App\Jobs\SendFeedbackNotification;
use App\Mail\FeedbackReceivedMail;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendFeedbackNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_mails_the_configured_admin_address(): void
    {
        Mail::fake();
        config(['mail.admin_email' => 'ops@timevaultapp.co']);

        $feedback = Feedback::factory()->rating(5)->create();

        (new SendFeedbackNotification($feedback))->handle();

        Mail::assertSent(
            FeedbackReceivedMail::class,
            fn (FeedbackReceivedMail $mail) => $mail->hasTo('ops@timevaultapp.co')
                && $mail->feedback->is($feedback)
        );
    }

    public function test_the_mail_replies_to_the_user_who_submitted_it(): void
    {
        Mail::fake();
        config(['mail.admin_email' => 'ops@timevaultapp.co']);

        $user = User::factory()->create([
            'full_name' => 'Dana Holloway',
            'email' => 'dana.holloway@example.test',
        ]);

        $feedback = Feedback::factory()->rating(3)->create(['user_id' => $user->id]);

        (new SendFeedbackNotification($feedback))->handle();

        Mail::assertSent(
            FeedbackReceivedMail::class,
            fn (FeedbackReceivedMail $mail) => $mail->hasReplyTo('dana.holloway@example.test')
        );
    }

    public function test_it_skips_sending_when_no_admin_address_is_configured(): void
    {
        Mail::fake();
        Log::spy();
        config(['mail.admin_email' => null]);

        (new SendFeedbackNotification(Feedback::factory()->create()))->handle();

        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_logs_and_rethrows_when_sending_fails(): void
    {
        config(['mail.admin_email' => 'ops@timevaultapp.co']);
        Log::spy();

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection refused'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP connection refused');

        try {
            (new SendFeedbackNotification(Feedback::factory()->create()))->handle();
        } finally {
            Log::shouldHaveReceived('error')->once();
        }
    }

    public function test_the_rendered_mail_contains_the_rating_and_message(): void
    {
        $user = User::factory()->create(['full_name' => 'Dana Holloway']);

        $feedback = Feedback::factory()->rating(4)->create([
            'user_id' => $user->id,
            'message' => 'The withdrawal flow is much smoother now.',
        ]);

        $rendered = (new FeedbackReceivedMail($feedback))->render();

        $this->assertStringContainsString('The withdrawal flow is much smoother now.', $rendered);
        $this->assertStringContainsString('Dana Holloway', $rendered);
        $this->assertStringContainsString('(4/5)', $rendered);
    }
}
