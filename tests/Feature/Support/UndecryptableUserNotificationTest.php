<?php

namespace Tests\Feature\Support;

use App\Jobs\SendFeedbackNotification;
use App\Jobs\SendSupportNotification;
use App\Mail\FeedbackReceivedMail;
use App\Mail\SupportRequestReceivedMail;
use App\Models\Feedback;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rows written under a previous APP_KEY throw on read of email / full_name.
 * Both notification mailables used to read those columns unguarded - in the
 * envelope for reply-to, and again in the view - so one damaged user meant the
 * queued job threw and the admin never heard about the submission at all.
 */
class UndecryptableUserNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const BOGUS = 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=';

    private function corrupt(User $user): User
    {
        DB::table('users')->where('id', $user->id)->update([
            'email' => self::BOGUS,
            'full_name' => self::BOGUS,
        ]);

        return $user->fresh();
    }

    public function test_a_support_request_from_an_undecryptable_user_still_mails_the_support_inbox(): void
    {
        Mail::fake();
        config(['mail.support_email' => 'support@timevaultapp.co']);

        $user = $this->corrupt(User::factory()->create());
        $supportRequest = SupportRequest::factory()->create(['user_id' => $user->id]);

        (new SendSupportNotification($supportRequest))->handle();

        Mail::assertSent(
            SupportRequestReceivedMail::class,
            fn (SupportRequestReceivedMail $mail) => $mail->hasTo('support@timevaultapp.co')
        );
    }

    public function test_feedback_from_an_undecryptable_user_still_mails_the_admin(): void
    {
        Mail::fake();
        config(['mail.admin_email' => 'ops@timevaultapp.co']);

        $user = $this->corrupt(User::factory()->create());
        $feedback = Feedback::factory()->create(['user_id' => $user->id]);

        (new SendFeedbackNotification($feedback))->handle();

        Mail::assertSent(
            FeedbackReceivedMail::class,
            fn (FeedbackReceivedMail $mail) => $mail->hasTo('ops@timevaultapp.co')
        );
    }

    public function test_an_undecryptable_user_gets_no_reply_to_instead_of_a_thrown_job(): void
    {
        $user = $this->corrupt(User::factory()->create());
        $supportRequest = SupportRequest::factory()->create(['user_id' => $user->id]);

        $envelope = (new SupportRequestReceivedMail($supportRequest))->envelope();

        $this->assertSame([], $envelope->replyTo);
    }

    public function test_both_mails_render_a_placeholder_for_an_undecryptable_user(): void
    {
        $user = $this->corrupt(User::factory()->create());

        $support = SupportRequest::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Locked out',
            'message' => 'I cannot sign in.',
        ]);

        $rendered = (new SupportRequestReceivedMail($support))->render();

        $this->assertStringContainsString('[unreadable]', $rendered);
        $this->assertStringContainsString('I cannot sign in.', $rendered);

        $feedback = Feedback::factory()->rating(2)->create([
            'user_id' => $user->id,
            'message' => 'Hard to follow.',
        ]);

        $renderedFeedback = (new FeedbackReceivedMail($feedback))->render();

        $this->assertStringContainsString('[unreadable]', $renderedFeedback);
        $this->assertStringContainsString('Hard to follow.', $renderedFeedback);
    }

    public function test_a_readable_user_still_gets_a_reply_to(): void
    {
        $user = User::factory()->create([
            'full_name' => 'Dana Holloway',
            'email' => 'dana.holloway@example.test',
        ]);

        $supportRequest = SupportRequest::factory()->create(['user_id' => $user->id]);

        $replyTo = (new SupportRequestReceivedMail($supportRequest))->envelope()->replyTo;

        $this->assertCount(1, $replyTo);
        $this->assertSame('dana.holloway@example.test', $replyTo[0]->address);
        $this->assertSame('Dana Holloway', $replyTo[0]->name);
    }
}
