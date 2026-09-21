<?php

namespace Tests\Feature\Mail;

use App\Mail\AccountDeletionConfirmationMail;
use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * The notification recipients live in .env and nowhere else.
 *
 * A hardcoded address is not a cosmetic problem here: it either leaks customer
 * detail to a domain nobody owns, or tells a user to write to a mailbox that
 * does not exist.
 */
class ConfiguredRecipientsTest extends TestCase
{
    /**
     * Re-evaluate config/mail.php against the current environment.
     *
     * @return array<string, mixed>
     */
    private function freshMailConfig(): array
    {
        return require base_path('config/mail.php');
    }

    public function test_support_email_falls_back_to_the_admin_address(): void
    {
        $repository = Env::getRepository();
        $originalAdmin = $repository->get('ADMIN_EMAIL');
        $originalSupport = $repository->get('SUPPORT_EMAIL');

        try {
            $repository->set('ADMIN_EMAIL', 'admin@timevaultapp.co');
            $repository->clear('SUPPORT_EMAIL');

            $config = $this->freshMailConfig();

            $this->assertSame('admin@timevaultapp.co', $config['admin_email']);
            $this->assertSame('admin@timevaultapp.co', $config['support_email']);
        } finally {
            $originalAdmin === null ? $repository->clear('ADMIN_EMAIL') : $repository->set('ADMIN_EMAIL', $originalAdmin);
            $originalSupport === null ? $repository->clear('SUPPORT_EMAIL') : $repository->set('SUPPORT_EMAIL', $originalSupport);
        }
    }

    public function test_a_separate_support_address_overrides_the_fallback(): void
    {
        $repository = Env::getRepository();
        $originalAdmin = $repository->get('ADMIN_EMAIL');
        $originalSupport = $repository->get('SUPPORT_EMAIL');

        try {
            $repository->set('ADMIN_EMAIL', 'admin@timevaultapp.co');
            $repository->set('SUPPORT_EMAIL', 'support@timevaultapp.co');

            $config = $this->freshMailConfig();

            $this->assertSame('admin@timevaultapp.co', $config['admin_email']);
            $this->assertSame('support@timevaultapp.co', $config['support_email']);
        } finally {
            $originalAdmin === null ? $repository->clear('ADMIN_EMAIL') : $repository->set('ADMIN_EMAIL', $originalAdmin);
            $originalSupport === null ? $repository->clear('SUPPORT_EMAIL') : $repository->set('SUPPORT_EMAIL', $originalSupport);
        }
    }

    public function test_neither_address_falls_back_to_a_placeholder_domain(): void
    {
        $repository = Env::getRepository();
        $originalAdmin = $repository->get('ADMIN_EMAIL');
        $originalSupport = $repository->get('SUPPORT_EMAIL');

        try {
            $repository->clear('ADMIN_EMAIL');
            $repository->clear('SUPPORT_EMAIL');

            $config = $this->freshMailConfig();

            $this->assertNull($config['admin_email']);
            $this->assertNull($config['support_email']);
        } finally {
            $originalAdmin === null ? $repository->clear('ADMIN_EMAIL') : $repository->set('ADMIN_EMAIL', $originalAdmin);
            $originalSupport === null ? $repository->clear('SUPPORT_EMAIL') : $repository->set('SUPPORT_EMAIL', $originalSupport);
        }
    }

    public function test_the_deletion_email_shows_the_configured_support_address(): void
    {
        config(['mail.support_email' => 'support@timevaultapp.co']);

        $rendered = $this->deletionMail()->render();

        $this->assertStringContainsString('mailto:support@timevaultapp.co', $rendered);
    }

    public function test_the_deletion_email_drops_the_contact_line_when_no_address_is_configured(): void
    {
        config(['mail.support_email' => null]);

        $rendered = $this->deletionMail()->render();

        $this->assertStringNotContainsString('mailto:', $rendered);
        $this->assertStringContainsString('please contact our support team immediately.', $rendered);
    }

    public function test_no_mail_template_hardcodes_an_address(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/emails/*.blade.php')) as $template) {
            if (preg_match_all('/[\w.+-]+@[\w-]+\.[\w.]{2,}/', file_get_contents($template), $matches)) {
                $offenders[basename($template)] = $matches[0];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Email templates must read addresses from config, not hardcode them: '.json_encode($offenders)
        );
    }

    private function deletionMail(): AccountDeletionConfirmationMail
    {
        return new AccountDeletionConfirmationMail(
            userName: 'Dana Holloway',
            userEmail: 'dana.holloway@example.test',
            balanceTransferred: 0.0,
            deletedAt: now()->toDateTimeString(),
            transactionCount: 0,
        );
    }
}
