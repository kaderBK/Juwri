<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * P6-176: the "Send test e-mail" admin form is admin-only, never echoes the
 * raw exception to the panel, rate-limits spam, and gives a useful answer
 * even when the configured mailer is the Log one (which silently accepts
 * everything and would otherwise say "Sent Successfully" while sending
 * nothing).
 */
class SmtpTestMailTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
        app()->setLocale('en'); // the suite default is ar; these tests read the English texts
    }

    protected function tearDown(): void
    {
        // Don't leak a custom transport into the next test in the same suite.
        Mail::purge('array');
        Mail::purge('failing');
        parent::tearDown();
    }

    private ?User $admin = null;

    private function postSmtpTest(array $extra = []): TestResponse
    {
        $this->admin ??= $this->admin();

        return $this->actingAs($this->admin)->panelPost(
            route('admin.settings.update'),
            ['smtp_test' => 1, 'email' => 'to@example.com'] + $extra
        );
    }

    public function test_owner_cannot_post_smtp_test(): void
    {
        Mail::fake();
        // The SettingsController constructor Gate keeps non-admins out; an
        // acting-as plain user is blocked at HTTP 403 (Laravel's Gate
        // middleware returns 403), nothing is sent, no panel answer.
        $response = $this->actingAs($this->plainUser())
            ->panelPost(route('admin.settings.update'), ['smtp_test' => 1, 'email' => 'to@example.com']);
        // The admin route group turns a non-admin away (302 to their own area, or 403): never a panel answer.
        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertStringNotContainsString('"success"', (string) $response->getContent());
        Mail::assertNothingSent();
    }

    public function test_guest_is_redirected_away_from_smtp_test(): void
    {
        Mail::fake();
        auth()->logout();
        $this->panelPost(route('admin.settings.update'), ['smtp_test' => 1, 'email' => 'to@example.com'])
            ->assertRedirect(route('login'));
        Mail::assertNothingSent();
    }

    public function test_auth_failed_gives_a_plain_reason_and_never_shows_the_password(): void
    {
        $sensitivePassword = 'S3cret-Pw-leak-test';

        Mail::extend('failing', fn (): FailingTransport => new FailingTransport(
            new TransportException('Failed to authenticate on SMTP server with username "u" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".')
        ));
        config([
            'mail.default' => 'failing',
            'mail.mailers.failing' => ['transport' => 'failing'],
            // The controller reads these for the panel message — set them so the
            // message can reference the host/port the owner configured.
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => 'smtp.example.com',
                'port' => 587,
                'username' => 'u',
                'password' => $sensitivePassword,
            ],
        ]);
        Mail::purge('failing');

        $response = $this->postSmtpTest();

        $this->assertPanelFails($response);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('user name or password', $message);
        $this->assertStringNotContainsString($sensitivePassword, $message);
        $this->assertStringNotContainsString('535', $message, 'panel must never echo the raw exception text');
    }

    public function test_seventh_request_is_rejected_by_the_rate_limiter(): void
    {
        // The default mailer is 'array' (set in phpunit.xml), which doesn't
        // throw, so the first six succeed (rate-limited but accepted).
        for ($i = 1; $i <= 6; $i++) {
            $this->postSmtpTest()->assertOk();
        }
        // 7th attempt: too many.
        $seventh = $this->postSmtpTest();
        $seventh->assertOk()->assertJson(['success' => false]);
        $this->assertStringContainsString('Too many test e-mails', (string) $seventh->json('message'));
        $this->assertCount(6, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_mailer_log_short_circuits_with_a_friendly_message(): void
    {
        config(['mail.default' => 'log']);

        $response = $this->postSmtpTest();

        $this->assertPanelFails($response, 'Log');
    }
}

/**
 * Test-only mail transport that throws a prebuilt TransportException the moment
 * it is asked to send anything. P6-176 uses this to prove the panel sees the
 * friendly "user name / password" answer instead of the raw exception text.
 */
final class FailingTransport extends AbstractTransport
{
    public function __construct(private readonly TransportException $exception)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        throw $this->exception;
    }

    public function __toString(): string
    {
        return 'failing://';
    }
}