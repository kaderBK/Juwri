<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\VoiceConsent;
use Juwri\Modules\Agents\Services\Telephony\VoiceLocale;
use Juwri\Modules\Agents\Services\Telephony\VoicePayLink;
use Juwri\Modules\Messaging\Contracts\Sms;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-09 V-19: a phone agent speaks the language of its LINE (else the store default),
 * never the request locale. These tests pin the greeting, the null-language store
 * fallback and the pay-link SMS to that rule.
 */
class VoiceLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function line(Store $store, ?string $language = null): PhoneLine
    {
        return PhoneLine::create([
            'store_id' => $store->id, 'provider' => 'twilio', 'number' => '+1555'.rand(1000, 9999),
            'active' => true, 'orders_enabled' => true, 'language' => $language,
        ]);
    }

    /** The Sms binding under test: records every send. */
    private function fakeSms(): object
    {
        return new class implements Sms
        {
            /** @var list<array{to: string, message: string, store: int|null, feature: string}> */
            public array $sent = [];

            public bool $result = true;

            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                $this->sent[] = ['to' => $to, 'message' => $message, 'store' => $storeId, 'feature' => $feature];

                return $this->result;
            }

            public function normalizePhone(string $phone): string
            {
                return (string) preg_replace('/\D+/', '', $phone);
            }
        };
    }

    public function test_arabic_line_greeting_uses_the_arabic_translation(): void
    {
        $store = $this->store();
        $line = $this->line($store, 'ar');

        $arHello = trans('Hi! How can I help with your order today?', [], 'ar');
        $expected = trans('This call is handled by an AI assistant.', [], 'ar').' '.$arHello;

        $this->assertSame('ar', VoiceLocale::forLine($line));
        $this->assertSame($expected, VoiceConsent::greeting($line));
        $this->assertNotSame(trans('Hi! How can I help with your order today?', [], 'en'), $arHello);
    }

    public function test_english_line_stays_english_when_the_app_locale_is_arabic(): void
    {
        $store = $this->store();
        $line = $this->line($store, 'en');
        app()->setLocale('ar');

        $this->assertSame('en', VoiceLocale::forLine($line));
        $this->assertStringContainsString(
            trans('Hi! How can I help with your order today?', [], 'en'),
            VoiceConsent::greeting($line)
        );
    }

    public function test_null_language_falls_back_to_the_arabic_store_default(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');
        $line = $this->line($store);

        $this->assertSame('ar', VoiceLocale::forStore((int) $store->id));
        $this->assertSame('ar', VoiceLocale::forLine($line));
        $this->assertStringContainsString(
            trans('Hi! How can I help with your order today?', [], 'ar'),
            VoiceConsent::greeting($line)
        );
    }

    public function test_pay_link_sms_for_an_arabic_line_is_arabic(): void
    {
        config(['voice.require_pay_link' => true]);
        $store = $this->store();
        $line = $this->line($store, 'ar');
        $sms = $this->fakeSms();
        app()->instance(Sms::class, $sms);

        $log = CallLog::create([
            'store_id' => $store->id, 'phone_line_id' => $line->id, 'call_sid' => 'CA-AR1',
            'provider' => 'twilio', 'from_number' => '+15551230000', 'outcome' => 'in_progress', 'transcript' => [],
        ]);
        $order = Order::create([
            'store_id' => $store->id, 'source' => 'phone', 'payment_mode' => 'hosted',
            'status' => 'unpaid', 'is_paid' => 0, 'type' => 'takeaway', 'currency_code' => 'USD',
        ]);

        $result = VoicePayLink::send($store, $log, (int) $order->id);

        $this->assertTrue($result['sent']);
        $this->assertCount(1, $sms->sent);
        $this->assertStringStartsWith('ادفع طلبك', $sms->sent[0]['message']);
    }
}
