<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Mails;

use App\Mails\StoreOrderMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Juwri\Core\Mail\BackupReadyMail;
use Juwri\Core\Mail\ResetPassword;
use Juwri\Core\Mail\StoreTransferOfferMail;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Mail\VerifyEmail;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use Juwri\Modules\Auth\Mail\NewDeviceLogin;
use Juwri\Modules\Integrations\Mail\ApiTokensReviewMail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-314: every mail that goes out through `emails.default` wears the shared mail frame, so Outlook.com dark mode
 * gets the dark palette (color-scheme + data-ogsc rules) and an Arabic recipient gets dir="rtl".
 */
class MailFrameCoverageTest extends TestCase
{
    use RefreshDatabase;

    private ?User $cached = null;

    private function user(): User
    {
        return $this->cached ??= User::create(['name' => 'Nia', 'username' => 'nia', 'email' => 'nia@example.test', 'password' => bcrypt('secret-pass')]);
    }

    /** @return array<string, array{0: string}> */
    public static function mails(): array
    {
        return array_combine($k = ['order', 'reset', 'verify', 'details', 'backup', 'device', 'transfer', 'tokens'], array_map(fn ($x) => [$x], $k));
    }

    private function make(string $which): Mailable
    {
        $u = $this->user();
        config(['settings.site_title' => 'Juwri Test']);
        foreach (['new_order', 'forgot_pass', 'signup_confirm', 'signup_details'] as $t) {
            config(["settings.email_sub_$t" => 'S', "settings.email_message_$t" => '<p>Hello body</p>']);
        }

        return match ($which) {
            'order' => new StoreOrderMail([]),
            'reset' => new ResetPassword($u, 'tok'),
            'verify' => new VerifyEmail($u),
            'details' => new UserDetails($u),
            'backup' => new BackupReadyMail(new StoreBackup(['size_bytes' => 2048]), 'Cafe'),
            'device' => new NewDeviceLogin($u, '1.2.3.4', 'UA', 'now'),
            'transfer' => new StoreTransferOfferMail(new StoreTransfer, 'Cafe'),
            'tokens' => new ApiTokensReviewMail($u, CredentialsChanged::PASSWORD_RESET, ['t1']),
        };
    }

    #[DataProvider('mails')]
    public function test_the_mail_is_framed_and_follows_the_recipient_direction(string $which): void
    {
        $html = $this->make($which)->locale('ar')->render();

        $this->assertStringContainsString('name="color-scheme"', $html);
        $this->assertStringContainsString('data-ogsc', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertSame(1, substr_count(strtolower($html), '<html'), 'one document, not a nested fragment');

        $en = $this->make($which)->locale('en')->render();
        $this->assertStringContainsString('dir="ltr"', $en);
    }

    public function test_template_mails_keep_a_plain_text_part(): void
    {
        $mail = $this->make('verify');
        $mail->render();
        $this->assertSame('emails.default-text', $mail->textView);
        $text = view('emails.default-text', ['body' => '<p>Hi <b>there</b></p><p>Next</p>', 'short_codes' => []])->render();
        $this->assertStringContainsString("Hi there\nNext", $text);
        $this->assertStringNotContainsString('<', $text);
    }
}
