<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * P1 sub-item 2 — the platform settings table (P1-07; was `options`) is cached across
 * requests and busted at the single write choke point (PlatformSettings::set), so
 * back-to-back boots issue the select once and saving a setting still re-reads immediately.
 */
class OptionsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_are_queried_once_across_two_boots(): void
    {
        PlatformSettings::flush();
        config(['app.installed' => true]);

        $optionSelects = 0;
        DB::listen(function ($q) use (&$optionSelects) {
            if (preg_match('/from\s+["`]?[a-z0-9_]*platform_settings["`]?/i', $q->sql)) {
                $optionSelects++;
            }
        });

        // Two request boots — the second must hit the cache, not the DB.
        new SettingsServiceProvider($this->app)->boot(new Request());
        new SettingsServiceProvider($this->app)->boot(new Request());

        $this->assertSame(1, $optionSelects, 'options must be selected once across two boots');
    }

    public function test_saving_a_setting_busts_the_cache(): void
    {
        config(['app.installed' => true]);

        // Prime the cache.
        new SettingsServiceProvider($this->app)->boot(new Request());
        $this->assertTrue(Cache::has(PlatformSettings::CACHE_KEY));

        // The single write choke point must forget it so the next request re-reads.
        PlatformSettings::set('site_title', 'Changed Title '.uniqid());
        $this->assertFalse(Cache::has(PlatformSettings::CACHE_KEY), 'PlatformSettings::set must bust the settings cache');

        new SettingsServiceProvider($this->app)->boot(new Request());
        $this->assertStringContainsString('Changed Title', (string) config('settings.site_title'));
    }
}
