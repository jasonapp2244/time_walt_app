<?php

namespace Tests\Feature\Support;

use App\Jobs\SendSupportAcknowledgement;
use App\Mail\SupportAcknowledgementMail;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendSupportAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private const BOGUS = 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=';

    public function test_it_mails_the_user_who_opened_the_request(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'dana.holloway@example.test']);
        $supportRequest = SupportRequest::factory()->create(['user_id' => $user->id]);

        (new SendSupportAcknowledgement($supportRequest))->handle();

        Mail::assertSent(
            SupportAcknowledgementMail::class,
            fn (SupportAcknowledgementMail $mail) => $mail->hasTo('dana.holloway@example.test')
                && $mail->supportRequest->is($supportRequest)
        );
    }

    public function test_it_does_not_mail_the_support_inbox(): void
    {
        Mail::fake();
        config(['mail.support_email' => 'support@timevaultapp.co']);

        $user = User::factory()->create(['email' => 'dana.holloway@example.test']);

        (new SendSupportAcknowledgement(
            SupportRequest::factory()->create(['user_id' => $user->id])
        ))->handle();

        Mail::assertSent(
            SupportAcknowledgementMail::class,
            fn (SupportAcknowledgementMail $mail) => ! $mail->hasTo('support@timevaultapp.co')
        );
    }

    public function test_replies_go_to_the_support_inbox(): void
    {
        config(['mail.support_email' => 'support@timevaultapp.co']);

        $envelope = (new SupportAcknowledgementMail(SupportRequest::factory()->create()))->envelope();

        $this->assertCount(1, $envelope->replyTo);
        $this->assertSame('support@timevaultapp.co', $envelope->replyTo[0]->address);
    }

    public function test_the_subject_carries_the_subject_the_user_typed(): void
    {
        $supportRequest = SupportRequest::factory()->create(['subject' => 'Card declined twice']);

        $this->assertSame(
            'We received your request: Card declined twice',
            (new SupportAcknowledgementMail($supportRequest))->envelope()->subject
        );
    }

    public function test_the_rendered_mail_repeats_what_the_user_sent(): void
    {
        $user = User::factory()->create(['full_name' => 'Dana Holloway']);

        $supportRequest = SupportRequest::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Withdrawal not received',
            'message' => 'The transfer has been pending for three days.',
        ]);

        $rendered = (new SupportAcknowledgementMail($supportRequest))->render();

        $this->assertStringContainsString('Dana Holloway', $rendered);
        $this->assertStringContainsString('Withdrawal not received', $rendered);
        $this->assertStringContainsString('The transfer has been pending for three days.', $rendered);
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

        $supportRequest = SupportRequest::factory()->create(['user_id' => $user->id]);

        (new SendSupportAcknowledgement($supportRequest))->handle();

        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_logs_and_rethrows_when_sending_fails(): void
    {
        Log::spy();

        $user = User::factory()->create(['email' => 'dana.holloway@example.test']);
        $supportRequest = SupportRequest::factory()->create(['user_id' => $user->id]);

        Mail::shouldReceive('to->send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection refused'));

        $this->expectException(\RuntimeException::class);

        try {
            (new SendSupportAcknowledgement($supportRequest))->handle();
        } finally {
            Log::shouldHaveReceived('error')->once();
        }
    }
}
