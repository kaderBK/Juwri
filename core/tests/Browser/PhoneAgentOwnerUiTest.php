<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Language;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Mcamara\LaravelLocalization\LaravelLocalization;
use Tests\Browser\Support\Axe;
use Tests\Browser\Support\Fixtures;

/**
 * P6-09 V-16 owner UI browser check: phone-agent settings (route restaurants.phoneagent.index), call log list +
 * detail with transcript, and the /pricing add-ons block, each in English (LTR) and Arabic (RTL).
 * Assertions: document dir, no JS errors, no horizontal overflow, phone numbers render LTR inside an RTL page,
 * axe-core WCAG 2.1 A/AA is clean, and the controls are reachable with the keyboard (Tab lands on a control).
 * Locale switching follows SoftPosCardTerminalsTest (sign in as en, register languages, then /lang/{code}).
 */

/** Active languages registered like the app does, then the locale switched via the picker route. */
$switchLocale = function ($page, string $locale): void {
    Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
    $supported = get_active_languages();
    config(['laravellocalization.supportedLocales' => $supported]);
    app()->make(LaravelLocalization::class)->setSupportedLocales($supported);
    $page->navigate(parse_url(lang_url($locale), PHP_URL_PATH));
};

/** Signs an owner in (as en) with a store, a phone line and two call logs; returns [$page, $store, $line, $call]. */
$signIn = function (string $locale) use ($switchLocale): array {
    app()->setLocale('en');
    Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    $owner = Fixtures::owner(['username' => 'pa_'.$locale, 'email' => 'pa-'.$locale.'@example.test', 'preferred_locale' => 'en']);
    $store = Fixtures::store($owner, 'Phone Bistro');
    $line = PhoneLine::create(['store_id' => $store->id, 'provider' => 'twilio', 'number' => '+966501234567', 'active' => true, 'handoff_number' => '+966509876543']);
    $call = CallLog::create([
        'store_id' => $store->id, 'call_sid' => 'CA-ui-'.$locale, 'provider' => 'twilio', 'from_number' => '+966555000111',
        'outcome' => 'order', 'duration_seconds' => 95,
        'transcript' => [
            ['role' => 'agent', 'text' => $locale === 'ar' ? 'مرحبا، كيف أساعدك؟' : 'Hi! How can I help?'],
            ['role' => 'caller', 'text' => $locale === 'ar' ? 'أريد برجر واحد' : 'One burger please'],
        ],
    ]);
    CallLog::create(['store_id' => $store->id, 'call_sid' => 'CA-ui2-'.$locale, 'provider' => 'twilio', 'from_number' => '+966555000222', 'outcome' => 'missed', 'transcript' => []]);

    $page = visit('/login');
    $page->type('username', 'pa-'.$locale.'@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
    $switchLocale($page, $locale);

    return [$page, $store, $line, $call];
};

/** Common checks: direction, no overflow, no JS errors, axe clean, Tab reaches a focusable control. */
$assertPage = function ($page, string $direction, string $label, string $tabFrom = '#main'): void {
    $page->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.getAttribute("dir")'))->toBe($direction);
    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true);
    Axe::assertClean($page, $label);
    $page->keys($tabFrom, 'Tab');
    expect($page->script('(function(){var e=document.activeElement;return !!e && e!==document.body;})()'))->toBeTrue('keyboard Tab reaches a focusable element');
};

/** Every phone number shown on the page sits in an LTR element (dir=ltr or the ui-ltr class). */
$phonesAreLtr = <<<'JS'
function () {
    var bad = [];
    var nodes = document.querySelectorAll('#main .ui-scope td, #main .ui-scope dd, #main .ui-scope code, #main .ui-scope span');
    Array.prototype.forEach.call(nodes, function (el) {
        if (el.children.length || !/^\s*\+\d[\d\s]{6,}\s*$/.test(el.textContent)) { return; }
        if (getComputedStyle(el).direction !== 'ltr' && !(el.closest('.ui-ltr, [dir=ltr]'))) { bad.push(el.textContent.trim()); }
    });
    return bad;
}
JS;

foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $dir) {
    it("phone agent settings page works in {$locale}", function () use ($signIn, $assertPage, $phonesAreLtr, $locale, $dir) {
        [$page, $store] = $signIn($locale);
        $page->navigate(parse_url(route('stores.phoneagent.index'), PHP_URL_PATH).'?store_id='.$store->id)->assertPresent('#pa-language');
        $assertPage($page, $dir, "phone agent settings ({$locale})");
        expect($page->script($phonesAreLtr))->toBe([], 'phone numbers render LTR');

        // Save: language, recording region, staff handoff number on the "Connect a number" form.
        $page->type('#pa-number', '+966501112233')
            ->type('#pa-handoff', '+966504445566')
            ->select('#pa-language', 'ar')
            ->select('#pa-region', 'ksa')
            ->click('.ui-channels__form button[type=submit]')
            ->assertNoJavaScriptErrors();

        $line = PhoneLine::where('number', '+966501112233')->first();
        expect($line)->not->toBeNull('the new line saved');
        expect($line->language)->toBe('ar');
        expect($line->recording_region)->toBe('ksa');
        expect($line->handoff_number)->toBe('+966504445566');
    });

    it("call log list and detail with transcript work in {$locale}", function () use ($signIn, $assertPage, $phonesAreLtr, $locale, $dir) {
        [$page, $store, $line, $call] = $signIn($locale);
        $page->navigate(parse_url(route('stores.phoneagent.calls.index'), PHP_URL_PATH).'?store_id='.$store->id)->assertSee('+966555000111');
        $assertPage($page, $dir, "call log list ({$locale})");
        expect($page->script($phonesAreLtr))->toBe([], 'phone numbers render LTR');

        $page->navigate(parse_url(route('stores.phoneagent.calls.show', $call->id), PHP_URL_PATH).'?store_id='.$store->id)
            ->assertPresent('.ui-phone-agent__transcript');
        $assertPage($page, $dir, "call detail ({$locale})");
        expect($page->script($phonesAreLtr))->toBe([], 'phone numbers render LTR');
        $page->assertSee($locale === 'ar' ? 'أريد برجر واحد' : 'One burger please');
    });

    it("pricing add-ons block works in {$locale}", function () use ($switchLocale, $assertPage, $locale, $dir) {
        if (! Route::has('pricing')) {
            test()->markTestSkipped('pitch routes belong to the edition package');
        }
        config(['settings.platform_marketing' => true]);
        app()->setLocale('en');
        $page = visit('/pricing');
        $switchLocale($page, $locale);
        $page->navigate('/pricing')->assertPresent('#addons');
        $assertPage($page, $dir, "pricing add-ons ({$locale})", '#addons');
        $page->assertPresent('#addon-phone-agent')->assertPresent('#addon-zatca')->assertPresent('#addon-delivery-relay');
    });
}
