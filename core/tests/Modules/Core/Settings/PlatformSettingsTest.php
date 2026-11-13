<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Settings;

use Juwri\Core\Providers\SettingsServiceProvider;
use App\Support\PlatformSecrets;
use Juwri\Core\Support\SecretMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\PlatformSetting;
use Juwri\Core\Settings\PlatformSettingKeys;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * P1-07 slice 1: typed platform settings replace the `options` key/value table. Values are stored
 * as typed JSON, config('settings.*') keeps its old shape, credentials stay encrypted and masked.
 */
class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
    }

    public function test_the_options_table_is_gone_and_the_defaults_are_seeded_typed(): void
    {
        $this->assertFalse(Schema::hasTable('options'));
        $this->assertTrue(Schema::hasTable('platform_settings'));

        $this->assertSame('juwri', PlatformSettings::get('site_title'));
        $this->assertTrue(PlatformSettings::get('blog_enable'));
        $this->assertSame(8, PlatformSettings::get('blog_page_limit'));
        $this->assertSame('free', PlatformSettings::array('free_membership_plan')['id']);

        // Legacy string form (what the options table held) is unchanged.
        $this->assertSame('1', PlatformSettings::legacy('blog_enable'));
        $this->assertSame('8', PlatformSettings::legacy('blog_page_limit'));
        $this->assertSame(['status' => '0', 'measurement_id' => null], json_decode((string) PlatformSettings::legacy('google_analytics'), true));
    }

    public function test_dead_keys_are_not_seeded(): void
    {
        foreach (['tpl_name', 'smtp_auth', 'specific_country', 'demo_mode'] as $dead) {
            $this->assertContains($dead, PlatformSettingKeys::DROPPED);
            $this->assertFalse(PlatformSettings::has($dead), $dead);
        }
        // Referenced by code despite the map's DROP: kept and typed.
        $this->assertSame('bool', PlatformSettingKeys::type('docs_assistant_enabled'));
    }

    public function test_writes_are_typed_and_lenient(): void
    {
        PlatformSettings::set('blog_enable', '0');
        PlatformSettings::set('blog_page_limit', '12');
        PlatformSettings::set('wallet_topup_max', '12.5');
        PlatformSettings::set('mk_home_categories', [3, 1]);
        PlatformSettings::set('abuse_storage_bytes_7', 3000);

        $this->assertFalse(PlatformSettings::get('blog_enable'));
        $this->assertSame('false', PlatformSetting::where('name', 'blog_enable')->value('value'));
        $this->assertSame(12, PlatformSettings::get('blog_page_limit'));
        $this->assertSame('12.5', PlatformSettings::get('wallet_topup_max'), 'a value that does not fit its type is kept');
        PlatformSettings::set('blog_banner', 'on');
        $this->assertSame('on', PlatformSettings::legacy('blog_banner'), 'flag words stay text (lossless)');
        $this->assertTrue(PlatformSettings::bool('blog_banner'));
        $this->assertSame([3, 1], PlatformSettings::get('mk_home_categories'));
        $this->assertSame('3000', PlatformSettings::get('abuse_storage_bytes_7'), 'unregistered keys keep the text form');
        $this->assertSame(3000, PlatformSettings::int('abuse_storage_bytes_7'));
        $this->assertSame(['abuse_storage_bytes_7'], PlatformSettings::namesWithPrefix('abuse_storage_bytes_'));

        // A JSON document given as text for an array setting is stored as that document.
        PlatformSettings::set('free_membership_plan', '{"id":"free","days":{}}');
        $this->assertSame(['id' => 'free', 'days' => []], PlatformSettings::get('free_membership_plan'));

        PlatformSettings::forget('abuse_storage_bytes_7');
        $this->assertFalse(PlatformSettings::has('abuse_storage_bytes_7'));
    }

    public function test_config_keeps_the_pre_p107_shape(): void
    {
        PlatformSettings::set('blog_enable', true);
        PlatformSettings::set('site_title', 'Shape Test');
        config(['app.installed' => true]);
        new SettingsServiceProvider($this->app)->boot(request());

        $this->assertSame(1, config('settings.blog_enable'), 'bools reach config as 1/0 like the old "1"/"0" rows');
        $this->assertSame(8, config('settings.blog_page_limit'));
        $this->assertSame('Shape Test', config('settings.site_title'));
        $this->assertSame('Shape Test', config('app.name'));
        $this->assertIsObject(config('settings.google_analytics'));
        $this->assertSame('0', config('settings.google_analytics')->status);
    }

    public function test_credentials_are_encrypted_at_rest_and_masked_for_views(): void
    {
        $key = 'stripe_secret_key';
        $this->assertTrue(PlatformSecrets::isSecret($key));

        PlatformSettings::set($key, 'sk_live_platform_secret_9876');

        $raw = (string) PlatformSetting::where('name', $key)->value('value');
        $this->assertStringNotContainsString('sk_live_platform_secret_9876', $raw);
        $this->assertSame('sk_live_platform_secret_9876', PlatformSettings::get($key));
        $this->assertSame('sk_live_platform_secret_9876', PlatformSettings::legacy($key));

        // Views / JSON never see it.
        $this->assertSame(SecretMask::mask('sk_live_platform_secret_9876'), PlatformSettings::masked()[$key]);
        $this->assertArrayNotHasKey('value', PlatformSetting::where('name', $key)->first()->toArray());

        // A masked echo keeps the secret; a new value replaces it.
        PlatformSettings::set($key, SecretMask::mask('sk_live_platform_secret_9876'));
        $this->assertSame('sk_live_platform_secret_9876', PlatformSettings::get($key));
        PlatformSettings::set($key, 'sk_live_rotated');
        $this->assertSame('sk_live_rotated', PlatformSettings::get($key));
    }
}
