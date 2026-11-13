<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Models\StoreOption;
use App\Services\WhatsApp\OrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * WA-C2: the order-message template and its shortcodes are core-owned.
 *
 * The critical property is that nothing about the FORMAT changed: a template a
 * merchant saved while the QuickOrder plugin owned this must expand identically
 * now that core does.
 */
class WhatsAppOrderMessageTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Message Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    private function tokens(): array
    {
        return [
            '{ORDER_ID}' => '4242',
            '{ORDER_DETAILS}' => 'Hawaiian X 1',
            '{CUSTOMER_DETAILS}' => 'Ada · 5551234567',
            '{ORDER_TYPE}' => 'Takeaway',
            '{ORDER_TOTAL}' => 'USD 17.25',
        ];
    }

    public function test_a_template_saved_under_the_plugin_expands_identically(): void
    {
        $store = $this->store();
        // Verbatim the shipped QuickOrder default.
        $legacy = "*New Order* (#{ORDER_ID})\n\n{ORDER_DETAILS}\n\nPayable: *{ORDER_TOTAL}*\n\n*Customer details*\n{CUSTOMER_DETAILS}\n-----------------------------\nThanks for the order.";
        StoreOption::updateStoreOption($store->id, 'whatsapp_message', $legacy);

        $out = OrderMessage::build($store->id, $this->tokens());

        $this->assertStringContainsString('*New Order* (#4242)', $out);
        $this->assertStringContainsString('Hawaiian X 1', $out);
        $this->assertStringContainsString('Payable: *USD 17.25*', $out);
        $this->assertStringContainsString('Ada · 5551234567', $out);
        $this->assertStringNotContainsString('{', $out, 'every token should have been expanded');
    }

    public function test_store_template_wins_over_the_platform_default(): void
    {
        $store = $this->store();
        PlatformSettings::set('whatsapp_order_message', 'PLATFORM {ORDER_ID}');
        config(['settings.whatsapp_order_message' => 'PLATFORM {ORDER_ID}']);
        StoreOption::updateStoreOption($store->id, 'whatsapp_message', 'STORE {ORDER_ID}');

        $this->assertSame('STORE 4242', OrderMessage::build($store->id, $this->tokens()));
    }

    public function test_a_store_with_no_template_uses_the_platform_default(): void
    {
        $store = $this->store();
        config(['settings.whatsapp_order_message' => 'PLATFORM {ORDER_ID}']);

        $this->assertSame('PLATFORM 4242', OrderMessage::build($store->id, $this->tokens()));
    }

    public function test_the_legacy_platform_key_is_still_honoured(): void
    {
        $store = $this->store();
        // Platform configured its default under the plugin and never re-saved it.
        config(['settings.whatsapp_order_message' => null]);
        config(['settings.store_order_whatsapp_message' => 'LEGACY {ORDER_ID}']);

        $this->assertSame('LEGACY 4242', OrderMessage::build($store->id, $this->tokens()));
    }

    public function test_falls_back_to_the_shipped_default_when_nothing_is_configured(): void
    {
        $store = $this->store();
        config(['settings.whatsapp_order_message' => null, 'settings.store_order_whatsapp_message' => null]);

        $out = OrderMessage::build($store->id, $this->tokens());

        // I2: the shipped fallback now localizes its prose (the store owner's
        // language), so assert on the token structure — not the English words:
        // the order id is expanded and no {TOKEN} is left unreplaced.
        $this->assertStringContainsString('(#4242)', $out);
        $this->assertStringContainsString('USD 17.25', $out);
        $this->assertStringNotContainsString('{', $out, 'every token should have been expanded');
    }

    public function test_an_unknown_token_is_left_alone_rather_than_blanked(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_message', 'Hi {ORDER_ID} {NOT_A_TOKEN}');

        // A typo should stay visible instead of silently eating the merchant's text.
        $this->assertSame('Hi 4242 {NOT_A_TOKEN}', OrderMessage::build($store->id, $this->tokens()));
    }

    public function test_the_shortcode_set_is_unchanged(): void
    {
        // These are the exact tokens merchants' saved templates reference.
        $this->assertSame(
            ['{ORDER_ID}', '{ORDER_DETAILS}', '{CUSTOMER_DETAILS}', '{ORDER_TYPE}', '{ORDER_TOTAL}'],
            array_keys(OrderMessage::SHORTCODES)
        );
    }
}
