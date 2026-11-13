<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Tests\TestCase;

/**
 * J52 (Opus review B1/S1): the data migrations behind the restaurant -> store rename move saved store settings and
 * platform settings to their new names without overwriting a value already saved under the new name.
 */
class StoreRenameSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_MIGRATION = 'app/Core/Database/Migrations/2026_10_26_100000_move_renamed_store_setting_paths.php';

    private const PLATFORM_MIGRATION = 'app/Core/Database/Migrations/2026_10_26_100100_rename_store_platform_setting_keys.php';

    public function test_old_store_setting_paths_move_to_the_new_paths(): void
    {
        $store = $this->storeWithSettings([
            'core' => ['restaurant_template' => 'crave', 'menu_layout' => 'grid'],
            'restaurant' => ['color' => '#aa0000'],
        ]);

        (require base_path(self::STORE_MIGRATION))->up();

        $settings = $this->settings($store);
        $this->assertSame(['menu_layout' => 'grid', 'store_template' => 'crave'], $this->sorted($settings['core']));
        $this->assertSame(['color' => '#aa0000'], $settings['store']);
        $this->assertArrayNotHasKey('restaurant', $settings);
        $this->assertSame('crave', StoreSettings::legacy($store->id, 'store_template'));
        $this->assertSame('#aa0000', StoreSettings::legacy($store->id, 'restaurant_color'));
    }

    public function test_a_value_already_under_the_new_path_is_kept(): void
    {
        $store = $this->storeWithSettings([
            'core' => ['restaurant_template' => 'old-theme', 'store_template' => 'new-theme'],
            'restaurant' => ['color' => '#old'],
            'store' => ['color' => '#new'],
        ]);

        $migration = require base_path(self::STORE_MIGRATION);
        $migration->up();
        $migration->up(); // re-run is a no-op

        $settings = $this->settings($store);
        $this->assertSame(['store_template' => 'new-theme'], $settings['core']);
        $this->assertSame(['color' => '#new'], $settings['store']);
        $this->assertArrayNotHasKey('restaurant', $settings);
    }

    public function test_store_setting_down_moves_the_values_back(): void
    {
        $store = $this->storeWithSettings(['core' => ['restaurant_template' => 'crave'], 'restaurant' => ['color' => '#123456']]);

        $migration = require base_path(self::STORE_MIGRATION);
        $migration->up();
        $migration->down();

        $this->assertEquals(['core' => ['restaurant_template' => 'crave'], 'restaurant' => ['color' => '#123456']], $this->settings($store));
    }

    public function test_old_platform_setting_names_are_renamed(): void
    {
        DB::table('platform_settings')->whereIn('name', ['store_watermark', 'store_order_whatsapp_message', 'fallback_store_theme'])->delete();
        $this->platformRow('post_watermark', 'true');
        $this->platformRow('quickorder_whatsapp_message', '"Order: {items}"');
        $this->platformRow('fallback_post_template', '"crave"');

        (require base_path(self::PLATFORM_MIGRATION))->up();

        $names = DB::table('platform_settings')->pluck('name')->all();
        foreach (['post_watermark', 'quickorder_whatsapp_message', 'fallback_post_template'] as $old) {
            $this->assertNotContains($old, $names);
        }
        $this->assertTrue(PlatformSettings::get('store_watermark'));
        $this->assertSame('Order: {items}', PlatformSettings::get('store_order_whatsapp_message'));
        $this->assertSame('crave', PlatformSettings::get('fallback_store_theme'));
    }

    public function test_a_platform_setting_already_under_the_new_name_is_kept(): void
    {
        $this->platformRow('quickorder_enable', 'false');
        $this->platformRow('store_order_enable', 'true');

        $migration = require base_path(self::PLATFORM_MIGRATION);
        $migration->up();

        $this->assertFalse(DB::table('platform_settings')->where('name', 'quickorder_enable')->exists());
        $this->assertTrue(PlatformSettings::get('store_order_enable'));

        $migration->down();
        $this->assertFalse(DB::table('platform_settings')->where('name', 'store_order_enable')->exists());
        $this->assertTrue(DB::table('platform_settings')->where('name', 'quickorder_enable')->exists());
    }

    /** @param  array<string, array<string, mixed>>  $settings */
    private function storeWithSettings(array $settings): Store
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'j52-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'J52 Settings',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
        DB::table('stores')->where('id', $store->id)->update(['settings' => json_encode($settings)]);
        StoreSettings::flush();

        return $store;
    }

    /** @return array<string, mixed> */
    private function settings(Store $store): array
    {
        return json_decode((string) DB::table('stores')->where('id', $store->id)->value('settings'), true);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    private function platformRow(string $name, string $json): void
    {
        DB::table('platform_settings')->where('name', $name)->delete();
        DB::table('platform_settings')->insert(['name' => $name, 'value' => $json, 'created_at' => now(), 'updated_at' => now()]);
        PlatformSettings::flush();
    }
}
