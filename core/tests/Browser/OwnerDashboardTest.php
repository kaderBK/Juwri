<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Models\Language;
use Juwri\Core\Settings\UserSettings;
use Mcamara\LaravelLocalization\LaravelLocalization;
use Tests\Browser\Support\Fixtures;

/** P1-18: owner sign-in, the dashboard and the menu screen in a real browser. */
it('signs an owner in and shows the dashboard and the menu', function () {
    $owner = Fixtures::owner(['username' => 'dash_owner', 'email' => 'dash-owner@example.test']);
    $store = Fixtures::store($owner, 'Dashboard Bistro');
    Fixtures::item($store, 'Dashboard Burger');

    $page = visit('/login');
    $page->type('username', 'dash-owner@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    $page->navigate(parse_url(route('stores.menu', $store), PHP_URL_PATH))
        ->assertSee('Dashboard Burger')
        ->assertNoJavaScriptErrors();
});

it('rejects a wrong password', function () {
    Fixtures::owner(['username' => 'dash_owner2', 'email' => 'dash-owner2@example.test']);

    visit('/login')
        ->type('username', 'dash-owner2@example.test')
        ->type('password', 'not-the-password')
        ->click('form button[type=submit]')
        ->assertPathIs('/login');
});

/** P6-47: a new owner is prompted to secure the account (en + ar, RTL) and can dismiss it. */
it('prompts a new owner to set up 2FA or a passkey and remembers the dismissal (en + ar)', function () {
    Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
    $owner = Fixtures::owner(['username' => 'nudge_owner', 'email' => 'nudge-owner@example.test']);

    $page = visit('/login');
    $page->type('username', 'nudge-owner@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIs('/dashboard')
        ->assertPresent('#security-nudge')
        ->assertNoJavaScriptErrors();

    $supported = get_active_languages();
    config(['laravellocalization.supportedLocales' => $supported]);
    app()->make(LaravelLocalization::class)->setSupportedLocales($supported);

    $page->navigate(parse_url(lang_url('en'), PHP_URL_PATH));
    $page->navigate(parse_url(route('dashboard'), PHP_URL_PATH))
        ->assertSeeIn('#security-nudge', 'Secure your account');

    $page->navigate(parse_url(lang_url('ar'), PHP_URL_PATH));
    $page->navigate(parse_url(route('dashboard'), PHP_URL_PATH))
        ->assertPresent('html[dir=rtl]')
        ->assertSeeIn('#security-nudge', 'أمّن حسابك')
        ->click('#security-nudge form button[type=submit]')
        ->assertMissing('#security-nudge')
        ->assertNoJavaScriptErrors();

    expect(UserSettings::legacy((int) $owner->id, 'security_nudge_dismissed'))->toBe('1');
});
