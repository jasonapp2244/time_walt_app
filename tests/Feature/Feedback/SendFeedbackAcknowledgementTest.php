<?php

namespace Tests\Feature\Feedback;

use App\Jobs\SendFeedbackAcknowledgement;
use App\Mail\FeedbackAcknowledgementMail;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendFeedbackAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private const BOGUS = 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=';

    public function test_it_mails_the_user_who_sent_the_feedback(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'dana.holloway@example.test']);
        $feedback = Feedback::factory()->rating(5)->create(['user_id' => $user->id]);

        (new SendFeedbackAcknowledgement($feedback))->handle();

        Mail::assertSent(
            FeedbackAcknowledgementMail::class,
            fn (FeedbackAcknowledgementMail $mail) => $mail->hasTo('dana.holloway@example.test')
                && $mail->feedback->is($feedback)
        );
    }

    public function test_it_does_not_mail_the_admin_inbox(): void
    {
        Mail::fake();
        config(['mail.admin_email' => 'admin@timevaultapp.co']);

        $user = User::factory()->create(['email' => 'dana.holloway@example.test']);

        (new SendFeedbackAcknowledgement(
            Feedback::factory()->create(['user_id' => $user->id])
        ))->handle();

        Mail::assertSent(
            FeedbackAcknowledgementMail::class,
            fn (FeedbackAcknowledgementMail $mail) => ! $mail->hasTo('admin@timevaultapp.co')
        );
    }

    public function test_replies_go_to_the_admin_inbox(): void
    {
        config(['mail.admin_email' => 'admin@timevaultapp.co']);

        $envelope = (new FeedbackAcknowledgementMail(Feedback::factory()->create()))->envelope();

        $this->assertCount(1, $envelope->replyTo);
        $this->assertSame('admin@timevaultapp.co', $envelope->replyTo[0]->address);
    }

    public function test_the_rendered_mail_repeats_the_rating_and_message(): void
    {
        $user = User::factory()->create(['full_name' => 'Dana Holloway']);

        $feedback = Feedback::factory()->rating(4)->create([
            'user_id' => $user->id,
            'message' => 'The withdrawal flow is much smoother now.',
        ]);

        $rendered = (new FeedbackAcknowledgementMail($feedback))->render();

        $this->assertStringContainsString('Dana Holloway', $rendered);
        $this->assertStringContainsString('The withdrawal flow is much smoother now.', $rendered);
        $this->assertStringContainsString('(4/5)', $rendered);
    }

    public function test_it_skips_a_user_whose_address_cannot_be_decrypted(): void
    {
        Mail::fake();
        Log::spy();

        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'email' => self::BOGUS,
            'full_name' => self::BOGUS,
        ]);

        $feedback = Feedback::factory()->create(['user_id' => $user->id]);

        (new SendFeedbackAcknowledgement($feedback))->handle();

        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_logs_and_rethrows_when_sending_fails(): void
    {
        Log::spy();

        $user = User::factory()->create(['email' => 'dana.holloway@example.test']);
        $feedback = Feedback::factory()->create(['user_id' => $user->id]);

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection refused'));

        $this->expectException(\RuntimeException::class);

        try {
            (new SendFeedbackAcknowledgement($feedback))->handle();
        } finally {
            Log::shouldHaveReceived('error')->once();
        }
    }
}
