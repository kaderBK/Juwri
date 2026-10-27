<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\OutputEncoding;

use Juwri\Core\Mail\ResetPassword;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Mail\VerifyEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-73. `emails.default` prints the admin template body unescaped, so the account name a visitor typed at sign-up
 * ("USER_FULLNAME") must be escaped (and link-neutralised) before it is merged into the body of the account mails.
 */
class TemplateMailAccountFieldsTest extends TestCase
{
    /** @return array<string, array{0: class-string}> */
    public static function mails(): array
    {
        return [
            'signup details' => [UserDetails::class],
            'verify email' => [VerifyEmail::class],
            'reset password' => [ResetPassword::class],
        ];
    }

    #[DataProvider('mails')]
    public function test_account_fields_are_escaped_in_the_body_values(string $mail): void
    {
        $payloads = [
            'USERNAME' => '"><img src=x onerror=alert(1)>',
            'USER_FULLNAME' => '<script>alert(1)</script> [Verify](https://evil.example)',
            'EMAIL' => 'a@b.test',
            'SITE_URL' => 'https://juwri.test/',
        ];

        $instance = (new \ReflectionClass($mail))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($instance, 'bodyValues');
        $out = $method->invoke($instance, $payloads);

        $this->assertStringNotContainsString('<script>', $out['USER_FULLNAME']);
        $this->assertStringNotContainsString('<img', $out['USERNAME']);
        $this->assertStringContainsString('&lt;script&gt;', $out['USER_FULLNAME']);
        $this->assertStringNotContainsString('](https://', $out['USER_FULLNAME'], 'Markdown link must not form');
        $this->assertSame('https://juwri.test/', $out['SITE_URL'], 'trusted tags stay as they are');
    }
}
