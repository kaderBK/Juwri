<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Models\Language;
use Tests\Browser\Support\Axe;
use Tests\Browser\Support\Fixtures;
use Tests\Support\TestPlans;

/**
 * P1-18 a11y: the owner dashboard (with its fresh-store onboarding checklist) and the public
 * pricing page have no axe-core WCAG 2.1 level A/AA violations, in English (LTR) and Arabic (RTL).
 * Locale switching follows PhoneAgentOwnerUiTest: register the languages, then navigate the
 * picker route (lang_url), so the page re-renders in the target locale.
 */

$switchLocale = function ($page, string $locale): void {
    Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
    $supported = get_active_languages();
    config(['laravellocalization.supportedLocales' => $supported]);
    app()->make(\Mcamara\LaravelLocalization\LaravelLocalization::class)->setSupportedLocales($supported);
    $page->navigate(parse_url(lang_url($locale), PHP_URL_PATH));
};

foreach (['en', 'ar'] as $locale) {
    it("has no axe-core WCAG 2.1 AA violations on the owner dashboard ({$locale})", function () use ($switchLocale, $locale) {
        app()->setLocale('en');
        $owner = Fixtures::owner(['username' => 'a11y_dash_'.$locale, 'email' => 'a11y-dash-'.$locale.'@example.test']);
        Fixtures::store($owner, 'A11y Bistro');

        $page = visit('/login');
        $page->type('username', 'a11y-dash-'.$locale.'@example.test')
            ->type('password', Fixtures::PASSWORD)
            ->click('form button[type=submit]')
            ->assertPathIs('/dashboard')
            ->assertNoJavaScriptErrors();

        $switchLocale($page, $locale);

        $page->navigate(parse_url(route('dashboard'), PHP_URL_PATH))
            ->assertPresent('#ob-checklist')
            ->wait(1);
        Axe::assertClean($page, "owner dashboard ({$locale})");
    });

    it("has no axe-core WCAG 2.1 AA violations on the public pricing page ({$locale})", function () use ($switchLocale, $locale) {
        app()->setLocale('en');
        TestPlans::create([
            'name' => 'A11y Plan', 'description' => 't', 'monthly_price' => 9, 'annual_price' => 90,
            'lifetime_price' => 0, 'recommended' => 'yes', 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1', 'table_qr' => '1'],
        ]);

        $page = visit('/pricing');
        $switchLocale($page, $locale);

        $page->navigate(parse_url(route('pricing'), PHP_URL_PATH))
            ->assertPresent('.mk-pricing')
            ->assertPresent('.pc-group')
            ->wait(1);
        Axe::assertClean($page, "public pricing page ({$locale})");
    });
}
