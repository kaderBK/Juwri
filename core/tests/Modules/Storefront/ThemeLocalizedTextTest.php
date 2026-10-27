<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-L1: per-locale texts.
 *
 * A localized `texts.*` override is a {locale: string} map. theme_setting()
 * returns the active locale's value with a graceful fallback, and a legacy
 * plain-string override keeps working untouched.
 */
class ThemeLocalizedTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_active_locale_value_is_returned(): void
    {
        $store = $this->store(['texts.hero_title' => ['en' => 'Welcome', 'ar' => 'أهلاً']]);

        App::setLocale('en');
        $this->assertSame('Welcome', theme_setting($store->id, 'texts.hero_title', 'FB'));

        App::setLocale('ar');
        $this->assertSame('أهلاً', theme_setting($store->id, 'texts.hero_title', 'FB'));
    }

    public function test_a_missing_locale_falls_back_to_the_store_default_language(): void
    {
        $store = $this->store(['texts.hero_title' => ['ar' => 'أهلاً']], 'ar');

        // No 'fr' value → falls back to the store default language (ar), not the
        // theme default, so a shopper on a language the owner did not fill still
        // sees the owner's copy rather than the shipped text.
        App::setLocale('fr');
        $this->assertSame('أهلاً', theme_setting($store->id, 'texts.hero_title', 'FB'));
    }

    public function test_an_empty_active_value_falls_back_to_the_theme_default(): void
    {
        $store = $this->store(['texts.hero_title' => ['en' => '', 'ar' => 'أهلاً']]);

        App::setLocale('en');
        $this->assertSame('FB', theme_setting($store->id, 'texts.hero_title', 'FB'));
    }

    public function test_a_legacy_plain_string_override_still_works(): void
    {
        $store = $this->store(['texts.hero_title' => 'Just a string']);

        App::setLocale('ar');
        $this->assertSame('Just a string', theme_setting($store->id, 'texts.hero_title', 'FB'));
    }

    public function test_the_validator_accepts_a_locale_map_for_a_localized_schema(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');
        $this->assertTrue($schema['texts']['localized'] ?? false, 'crave is a localized schema');

        $this->assertSame([], ThemeCustomizerService::validate($schema, [
            'texts.hero_title' => ['en' => 'Hi', 'ar' => 'مرحبا'],
        ]));

        // A non-string leaf is rejected.
        $this->assertArrayHasKey('texts.hero_title', ThemeCustomizerService::validate($schema, [
            'texts.hero_title' => ['en' => ['nested' => 'no']],
        ]));
    }

    private function store(array $settings, string $defaultLang = 'en'): Store
    {
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        StoreOption::updateStoreOption($store->id, 'default_language', $defaultLang);
        ThemeSetting::create(['store_id' => $store->id, 'template' => 'crave', 'settings' => $settings]);

        return $store;
    }
}
