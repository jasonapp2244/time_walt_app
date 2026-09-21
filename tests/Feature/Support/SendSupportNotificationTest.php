<?php

namespace Tests\Feature\Support;

use App\Jobs\SendSupportNotification;
use App\Mail\SupportRequestReceivedMail;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendSupportNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_mails_the_configured_support_address(): void
    {
        Mail::fake();
        config(['mail.support_email' => 'support@timevaultapp.co']);

        $supportRequest = SupportRequest::factory()->create();

        (new SendSupportNotification($supportRequest))->handle();

        Mail::assertSent(
            SupportRequestReceivedMail::class,
            fn (SupportRequestReceivedMail $mail) => $mail->hasTo('support@timevaultapp.co')
                && $mail->supportRequest->is($supportRequest)
        );
    }

    public function test_it_mails_the_support_inbox_not_the_admin_inbox(): void
    {
        Mail::fake();
        config([
            'mail.support_email' => 'support@timevaultapp.co',
            'mail.admin_email' => 'admin@timevaultapp.co',
        ]);

        (new SendSupportNotification(SupportRequest::factory()->create()))->handle();

        Mail::assertSent(
            SupportRequestReceivedMail::class,
            fn (SupportRequestReceivedMail $mail) => $mail->hasTo('support@timevaultapp.co')
                && ! $mail->hasTo('admin@timevaultapp.co')
        );
    }

    public function test_the_mail_replies_to_the_user_who_submitted_it(): void
    {
        Mail::fake();
        config(['mail.support_email' => 'support@timevaultapp.co']);

        $user = User::factory()->create([
            'full_name' => 'Dana Holloway',
            'email' => 'dana.holloway@example.test',
        ]);

        $supportRequest = SupportRequest::factory()->create(['user_id' => $user->id]);

        (new SendSupportNotification($supportRequest))->handle();

        Mail::assertSent(
            SupportRequestReceivedMail::class,
            fn (SupportRequestReceivedMail $mail) => $mail->hasReplyTo('dana.holloway@example.test')
        );
    }

    public function test_the_subject_carries_the_users_own_subject(): void
    {
        Mail::fake();
        config(['mail.support_email' => 'support@timevaultapp.co']);

        $supportRequest = SupportRequest::factory()->create(['subject' => 'Card declined twice']);

        (new SendSupportNotification($supportRequest))->handle();

        Mail::assertSent(
            SupportRequestReceivedMail::class,
            fn (SupportRequestReceivedMail $mail) => $mail->envelope()->subject === 'New Support Request: Card declined twice'
        );
    }

    public function test_it_skips_sending_when_no_support_address_is_configured(): void
    {
        Mail::fake();
        Log::spy();
        config(['mail.support_email' => null]);

        (new SendSupportNotification(SupportRequest::factory()->create()))->handle();

        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_logs_and_rethrows_when_sending_fails(): void
    {
        config(['mail.support_email' => 'support@timevaultapp.co']);
        Log::spy();

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection refused'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP connection refused');

        try {
            (new SendSupportNotification(SupportRequest::factory()->create()))->handle();
        } finally {
            Log::shouldHaveReceived('error')->once();
        }
    }

    public function test_the_rendered_mail_contains_the_subject_message_and_user(): void
    {
        $user = User::factory()->create([
            'full_name' => 'Dana Holloway',
            'email' => 'dana.holloway@example.test',
        ]);

        $supportRequest = SupportRequest::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Withdrawal not received',
            'message' => 'The transfer has been pending for three days.',
        ]);

        $rendered = (new SupportRequestReceivedMail($supportRequest))->render();

        $this->assertStringContainsString('Withdrawal not received', $rendered);
        $this->assertStringContainsString('The transfer has been pending for three days.', $rendered);
        $this->assertStringContainsString('Dana Holloway', $rendered);
        $this->assertStringContainsString('dana.holloway@example.test', $rendered);
    }
}
