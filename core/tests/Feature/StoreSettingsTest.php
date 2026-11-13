<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettingKeys;
use Juwri\Core\Settings\StoreSettings;
use Tests\TestCase;

/**
 * P1-07 slice 3: `stores.settings` (typed) behind StoreSettings, with StoreOption / store_options()
 * as the unchanged compatibility API.
 */
class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'S', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function column(Store $store): array
    {
        return (array) json_decode((string) DB::table('stores')->where('id', $store->id)->value('settings'), true);
    }

    public function test_registry_covers_the_233_mapped_store_keys(): void
    {
        $this->assertCount(233, StoreSettingKeys::KEYS); // +6 fiscal_* seller national address (P6-176) +2 tax_zero_vatex, tax_exempt_vatex (P6-175) +5 J32 tax keys (P6-116: delivery/tray/service taxable, levies, rate preset) + 3 more since the P6 batches (see StoreSettingKeys) + restaurant_free_delivery_over + restaurant_free_delivery_covers_branch_fee (P5-34) + 186 + 2 offline-queue (P3-02/03) + 17 booking_* (P3-10) + 3 P3-07/P3-06b + 3 P3-08 retail + 3 grocery (P3-09) + laundry_turnaround_hours (P3-11)
        $this->assertSame(['whatsapp.provider', 'string'], StoreSettingKeys::lookup('whatsapp_provider'));
    }

    public function test_typed_values_are_stored_in_the_json_column_and_read_back_typed(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'allow_tips', '1');
        StoreOption::updateStoreOption($store->id, 'qr_padding', '12');
        StoreOption::updateStoreOption($store->id, 'whatsapp_provider', 'twilio');

        $col = $this->column($store);
        $this->assertTrue($col['allow']['tips']);
        $this->assertSame(12, $col['qr']['padding']);
        $this->assertSame('twilio', $col['whatsapp']['provider']);

        $this->assertTrue(StoreSettings::bool($store->id, 'allow_tips'));
        $this->assertSame(12, StoreSettings::int($store->id, 'qr_padding'));
        $this->assertSame('twilio', StoreSettings::string($store->id, 'whatsapp_provider'));
        $this->assertFalse(StoreSettings::bool($store->id, 'allow_coupons'));
        $this->assertSame('dflt', StoreSettings::string($store->id, 'birthday_channel', 'dflt'));
    }

    public function test_compat_api_returns_the_legacy_shapes(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'allow_tips', '1');
        StoreOption::updateStoreOption($store->id, 'allow_coupons', '0');
        StoreOption::updateStoreOption($store->id, 'menu_languages', '["en","ar"]');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');

        // a flag is int 1 / int 0 exactly as the store_options text form decoded before
        $this->assertSame(1, StoreOption::getStoreOption($store->id, 'allow_tips'));
        $this->assertSame(0, StoreOption::getStoreOption($store->id, 'allow_coupons'));
        $this->assertSame(['en', 'ar'], StoreOption::getStoreOption($store->id, 'menu_languages'));
        $this->assertSame('SAR', store_options($store->id)->currency_code);
        $this->assertSame(1, store_options($store->id)->allow_tips);
        $this->assertFalse(StoreOption::getStoreOption($store->id, 'birthday_channel'));
        $this->assertSame('dflt', StoreOption::getStoreOption($store->id, 'birthday_channel', 'dflt'));
    }

    public function test_lossless_text_is_kept_when_it_does_not_fit_the_type(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'kiosk_exit_pin', '0042');       // int key, leading zero
        StoreOption::updateStoreOption($store->id, 'allow_tips', 'on');             // flag key, word
        StoreOption::updateStoreOption($store->id, 'menu_languages', '{}');         // empty object
        StoreOption::updateStoreOption($store->id, 'hotel_room_pres', '{"0":"a","1":"b"}');

        $this->assertSame('0042', StoreSettings::legacy($store->id, 'kiosk_exit_pin'));
        $this->assertSame('on', StoreSettings::legacy($store->id, 'allow_tips'));
        $this->assertSame('{}', StoreSettings::legacy($store->id, 'menu_languages'));
        $this->assertSame('{"0":"a","1":"b"}', StoreSettings::legacy($store->id, 'hotel_room_pres'));
        $this->assertEquals((object) [], StoreOption::getStoreOption($store->id, 'menu_languages'));
    }

    public function test_unknown_names_are_kept_under_custom(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'some_dynamic_key_7', 'x');

        $this->assertSame('x', $this->column($store)['custom']['some_dynamic_key_7']);
        $this->assertSame('x', StoreOption::getStoreOption($store->id, 'some_dynamic_key_7'));
        $this->assertSame('x', StoreSettings::legacyMap($store->id)['some_dynamic_key_7']);
    }

    public function test_one_stores_settings_never_leak_to_another(): void
    {
        $a = $this->store();
        $b = $this->store();
        StoreOption::updateStoreOption($a->id, 'whatsapp_provider', 'only-a');
        StoreOption::updateStoreOption($b->id, 'whatsapp_provider', 'only-b');

        $this->assertSame('only-a', StoreOption::getStoreOption($a->id, 'whatsapp_provider'));
        $this->assertSame('only-b', StoreOption::getStoreOption($b->id, 'whatsapp_provider'));
        $this->assertSame(['whatsapp_provider' => 'only-a'], StoreSettings::legacyMap($a->id));

        StoreSettings::forget($a->id, 'whatsapp_provider');
        $this->assertSame('only-b', StoreOption::getStoreOption($b->id, 'whatsapp_provider'));
        $this->assertFalse(StoreOption::getStoreOption($a->id, 'whatsapp_provider'));
    }

    public function test_the_store_options_table_and_mirror_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('store_options'));

        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'AED');
        $this->assertSame('AED', StoreSettings::legacy((int) $store->id, 'currency_code'));

        StoreSettings::forget($store->id, 'currency_code');
        $this->assertFalse(StoreOption::getStoreOption($store->id, 'currency_code'));
        $this->assertArrayNotHasKey('currency', $this->column($store));
    }

    public function test_increment_adds_under_lock_and_creates_the_key(): void
    {
        $store = $this->store();
        StoreOption::incrementStoreOption($store->id, 'wallet_amount', 10);
        StoreOption::incrementStoreOption($store->id, 'wallet_amount', 5.25);

        $this->assertEqualsWithDelta(15.25, (float) StoreSettings::legacy($store->id, 'wallet_amount'), 0.0001);
    }

    public function test_cross_store_scan_returns_only_stores_with_the_value(): void
    {
        $a = $this->store();
        $b = $this->store();
        $c = $this->store();
        StoreOption::updateStoreOption($a->id, 'store_template', 'bio');
        StoreOption::updateStoreOption($b->id, 'store_template', 'classic');

        $this->assertSame([$a->id => 'bio', $b->id => 'classic'], StoreSettings::valuesFor('store_template'));
        $this->assertSame([$a->id], StoreSettings::storeIdsWhere('store_template', fn ($v) => $v === 'bio'));
        $this->assertSame([], StoreSettings::valuesFor('store_template', [$c->id]));
    }

    public function test_settings_column_is_hidden_from_the_model_array(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_provider', 'x');

        $this->assertArrayNotHasKey('settings', Store::find($store->id)->toArray());
    }

    public function test_setting_on_a_missing_store_is_a_no_op(): void
    {
        $this->assertFalse(StoreSettings::set(999999, 'whatsapp_provider', 'x'));
        $this->assertNull(StoreSettings::legacy(999999, 'whatsapp_provider'));
    }
}
