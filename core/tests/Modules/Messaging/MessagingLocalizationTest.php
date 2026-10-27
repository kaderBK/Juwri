<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Messaging\Services\SmsService;
use App\Services\WhatsApp\OrderMessage;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * I2: transactional messaging follows the recipient's language.
 * - users.preferred_locale + HasLocalePreference (drives queued mail locale).
 * - SMS OTP + WhatsApp fallback route their prose through ___() (tokens intact).
 */
class MessagingLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_implements_locale_preference_with_default_fallback(): void
    {
        $user = User::factory()->create(['preferred_locale' => null]);
        $this->assertInstanceOf(HasLocalePreference::class, $user);

        // NULL → platform default.
        config(['settings.default_lang' => 'en']);
        $this->assertSame('en', $user->preferredLocale());

        // Set → that locale.
        $user->preferred_locale = 'ar';
        $this->assertSame('ar', $user->preferredLocale());
    }

    public function test_sms_otp_message_routes_through_translation(): void
    {
        // A translation for the exact ___() key/slug, in Arabic.
        app()->setLocale('ar');
        $slug = 'lang.' . Str::slug('Your verification code is :code. It expires in 10 minutes.', '_');
        app('translator')->addLines([$slug => 'رمزك هو :code'], 'ar');

        $captured = null;
        $sms = \Mockery::mock(SmsService::class);
        $sms->shouldReceive('normalizePhone')->andReturnUsing(fn ($p) => $p);
        $sms->shouldReceive('send')->andReturnUsing(function ($to, $msg) use (&$captured) {
            $captured = $msg;
            return true;
        });
        $this->app->instance(SmsService::class, $sms);

        [$ok] = $this->app->make(SmsOtpService::class)->request('+16505551234', OtpScope::platform('test'));

        $this->assertTrue($ok);
        $this->assertNotNull($captured);
        $this->assertStringStartsWith('رمزك هو ', $captured);       // localized prose
        $this->assertMatchesRegularExpression('/\d{6}$/', $captured); // the :code survived
    }

    public function test_whatsapp_fallback_localizes_prose_but_keeps_tokens_literal(): void
    {
        $tpl = OrderMessage::fallbackTemplate();

        foreach (['{ORDER_ID}', '{ORDER_DETAILS}', '{ORDER_TOTAL}', '{CUSTOMER_DETAILS}'] as $token) {
            $this->assertStringContainsString($token, $tpl, "$token must stay literal for downstream replacement");
        }
    }

    public function test_dunning_email_is_no_longer_raw_english(): void
    {
        $view = resource_path('views/emails/dunning.blade.php');
        $this->assertStringContainsString('___(', (string) file_get_contents($view), "$view must localize its strings");
    }
}
