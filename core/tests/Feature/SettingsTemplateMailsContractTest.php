<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Mails\StoreOrderMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Mail\ResetPassword;
use Juwri\Core\Mail\SettingsTemplateMail;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Mail\VerifyEmail;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * WI-09a characterisation: the four admin-editable template mails (sign-up
 * confirmation, forgot password, account details, new order). Each takes its
 * subject and HTML body from settings (`email_sub_*` / `email_message_*`),
 * fills the `{TAG}` merge codes in both, renders through `emails.default`, is
 * queued with 3 tries and a 60/300/900 s backoff.
 */
class SettingsTemplateMailsContractTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create([
            'name' => 'Nia Owner',
            'username' => 'nia',
            'email' => 'nia@example.test',
            'password' => bcrypt('secret-pass'),
        ]);
    }

    private function tags(string $list): string
    {
        return implode('|', array_map(fn ($t) => $t.'={'.$t.'}', explode(',', $list)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://app.example.test',
            'settings.site_title' => 'Juwri Test',
        ]);
    }

    public function test_queue_contract(): void
    {
        $user = $this->owner();
        foreach ([new VerifyEmail($user), new ResetPassword($user, 't'), new UserDetails($user), new StoreOrderMail([])] as $mail) {
            $this->assertInstanceOf(ShouldQueue::class, $mail);
            $this->assertSame(3, $mail->tries);
            $this->assertSame([60, 300, 900], $mail->backoff);
        }
    }

    public function test_verify_email_fills_codes_and_a_signed_confirmation_link(): void
    {
        Carbon::setTestNow('2026-09-27 12:00:00');
        $user = $this->owner();
        $list = 'SITE_TITLE,SITE_URL,USER_ID,USERNAME,USER_FULLNAME,EMAIL,CONFIRMATION_LINK';
        config([
            'settings.email_sub_signup_confirm' => 'Confirm {USERNAME} at {SITE_TITLE}',
            'settings.email_message_signup_confirm' => '<p>'.$this->tags($list).'</p>',
        ]);

        $mail = new VerifyEmail($user);
        $html = $mail->render();

        $link = URL::temporarySignedRoute('verification.verify', Carbon::now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
        $this->assertSame('Confirm nia at Juwri Test', $mail->subject);
        $this->assertStringContainsString('SITE_TITLE=Juwri Test|SITE_URL='.route('home').'|USER_ID='.$user->id
            .'|USERNAME=nia|USER_FULLNAME=Nia Owner|EMAIL=nia@example.test|CONFIRMATION_LINK='.$link, $html);
        $this->assertSame($link, $mail->viewData['short_codes']['{CONFIRMATION_LINK}']);
        Carbon::setTestNow();
    }

    public function test_reset_password_fills_codes_from_app_url_and_expiry(): void
    {
        $user = $this->owner();
        $list = 'SITE_TITLE,SITE_URL,USER_ID,USERNAME,USER_FULLNAME,EMAIL,FORGET_PASSWORD_LINK,EXPIRY_TIME';
        config([
            'settings.email_sub_forgot_pass' => 'Reset for {EMAIL}',
            'settings.email_message_forgot_pass' => '<p>'.$this->tags($list).'</p>',
            'auth.passwords.users.expire' => 45,
        ]);

        $mail = new ResetPassword($user, 'tok-9');
        $html = $mail->render();

        $link = 'https://app.example.test/password/reset/tok-9?email=nia%40example.test';
        $this->assertSame('Reset for nia@example.test', $mail->subject);
        $this->assertStringContainsString('SITE_TITLE=Juwri Test|SITE_URL=https://app.example.test|USER_ID='.$user->id
            .'|USERNAME=nia|USER_FULLNAME=Nia Owner|EMAIL=nia@example.test|FORGET_PASSWORD_LINK='.e($link).'|EXPIRY_TIME=45', $html);
        $this->assertSame($link, $mail->viewData['short_codes']['{FORGET_PASSWORD_LINK}']);
    }

    public function test_user_details_fills_codes(): void
    {
        $user = $this->owner();
        config([
            'settings.email_sub_signup_details' => 'Welcome {USER_FULLNAME}',
            'settings.email_message_signup_details' => '<p>'.$this->tags('SITE_TITLE,SITE_URL,USER_ID,USERNAME,USER_FULLNAME,EMAIL').'</p>',
        ]);

        $mail = new UserDetails($user);
        $html = $mail->render();

        $this->assertSame('Welcome Nia Owner', $mail->subject);
        $this->assertStringContainsString('SITE_TITLE=Juwri Test|SITE_URL='.route('home').'|USER_ID='.$user->id
            .'|USERNAME=nia|USER_FULLNAME=Nia Owner|EMAIL=nia@example.test', $html);
        $this->assertSame($user->id, $mail->user->id);
    }

    public function test_restaurant_order_fills_codes_raw_in_subject_and_neutralised_in_body(): void
    {
        $list = 'SITE_TITLE,SITE_URL,RESTAURANT_NAME,CUSTOMER_NAME,TABLE_NUMBER,PHONE_NUMBER,ADDRESS,ORDER_TYPE,ORDER,MESSAGE';
        config([
            'settings.email_sub_new_order' => 'Order {ORDER_TYPE} from {CUSTOMER_NAME}',
            'settings.email_message_new_order' => '<div>'.$this->tags($list).'</div>',
        ]);

        $mail = new StoreOrderMail([
            'store_name' => 'Cafe <b>',
            'customer_name' => '[x](https://evil.example)',
            'table_number' => '7',
            'phone_number' => '555',
            'address' => 'Main `st`',
            'order_type' => 'delivery',
            'order' => '<ul><li>Tea x2</li></ul>',
            'message' => 'see https://a.example',
        ]);
        $html = $mail->render();

        // Subject is plain text: raw values.
        $this->assertSame('Order delivery from [x](https://evil.example)', $mail->subject);
        $codes = $mail->viewData['short_codes'];
        // Customer-supplied fields: HTML-escaped, URL scheme broken (no Markdown escapes: the mail renders via view()).
        $this->assertSame('Cafe &lt;b&gt;', $codes['{RESTAURANT_NAME}']);
        $this->assertSame('[x](https&#58;//evil.example)', $codes['{CUSTOMER_NAME}']);
        $this->assertSame('Main `st`', $codes['{ADDRESS}']);
        $this->assertSame('see https&#58;//a.example', $codes['{MESSAGE}']);
        $this->assertSame('7', $codes['{TABLE_NUMBER}']);
        $this->assertSame('555', $codes['{PHONE_NUMBER}']);
        // App-built fields stay raw (the order list is HTML built by the checkout).
        $this->assertSame('<ul><li>Tea x2</li></ul>', $codes['{ORDER}']);
        $this->assertSame('delivery', $codes['{ORDER_TYPE}']);
        $this->assertSame('Juwri Test', $codes['{SITE_TITLE}']);
        $this->assertSame(route('home'), $codes['{SITE_URL}']);
        $this->assertStringContainsString('Tea x2</li></ul>', $html);
        $this->assertStringNotContainsString('<a href="https://evil', $html);
    }

    public function test_a_value_with_square_brackets_is_not_shown_with_backslashes(): void
    {
        config([
            'settings.email_sub_new_order' => 'Order',
            'settings.email_message_new_order' => '<div>'.$this->tags('CUSTOMER_NAME').'</div>',
        ]);

        $mail = new StoreOrderMail([
            'store_name' => 'Cafe',
            'customer_name' => 'Ali [x] `y`',
            'table_number' => '7',
            'phone_number' => '555',
            'address' => 'Main',
            'order_type' => 'delivery',
            'order' => '<ul></ul>',
            'message' => 'm',
        ]);
        $html = $mail->render();

        $this->assertStringContainsString('CUSTOMER_NAME=Ali [x] `y`', $html);
        $this->assertStringNotContainsString('\\[', $html);
        $this->assertStringNotContainsString('\\`', $html);
    }

    /** Regression: an unseeded / emptied template body made the CSS inliner throw "loadHTML(): Argument #1 must not be empty". */
    public function test_an_empty_template_body_still_renders_and_carries_the_order(): void
    {
        foreach ([null, '', '   ', '<p> </p>'] as $empty) {
            config([
                'settings.email_sub_new_order' => 'New order',
                'settings.email_message_new_order' => $empty,
            ]);

            $html = (new StoreOrderMail([
                'customer_name' => 'Nia',
                'order_type' => 'delivery',
                'order' => '<ul><li>Tea x2</li></ul>',
            ]))->render();

            $this->assertStringContainsString('Tea x2', $html);
            $this->assertStringContainsString('Nia', $html);
        }
    }

    public function test_an_empty_body_with_no_values_falls_back_to_the_site_title_in_the_default_view(): void
    {
        $html = view('emails.default', ['body' => '', 'short_codes' => []])->render();
        $this->assertStringContainsString('Juwri Test', $html);

        $mail = new class extends SettingsTemplateMail
        {
            protected function template(): string
            {
                return 'unseeded_template_x';
            }

            protected function mergeValues(): array
            {
                return [];
            }
        };
        $this->assertStringContainsString('Juwri Test', $mail->render());
    }
}
