<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Models\User;
use Tests\Browser\Support\Fixtures;

/**
 * P6-115: inline on*= handlers are dead under the enforced CSP; the replaced delegated handlers must work and the page
 * must log no CSP violation ("Refused to execute inline event handler") in the console.
 */
it('falls back to the default dish image on the pizzaflow storefront and logs no CSP violation', function () {
    $store = Fixtures::store(Fixtures::owner(), 'Pizza Flow Test', 'pizzaflow');
    Fixtures::item($store, 'Margherita', 9.0, 'definitely-missing-dish.png');

    $page = visit('/'.$store->slug);
    $page->wait(2);

    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();
    // the broken dish image was swapped by the delegated data-csp-error="fallback" listener
    expect($page->script("() => [...document.querySelectorAll('img[data-csp-error=\"fallback\"]')].every(i => i.src.endsWith('/storage/menu/default.png'))"))->toBeTrue();
    expect($page->script("() => document.querySelectorAll('img[data-csp-error=\"fallback\"]').length"))->toBeGreaterThan(0);
});

it('loads the owner dashboard without CSP violations', function () {
    Fixtures::owner(['username' => 'csp_owner', 'email' => 'csp-owner@example.test']);

    $page = visit('/login');
    $page->type('username', 'csp-owner@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

it('loads the admin users page (inline-handler-free admin shell) without CSP violations', function () {
    User::factory()->create([
        'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now(),
        'username' => 'csp_admin', 'email' => 'csp-admin@example.test', 'password' => bcrypt(Fixtures::PASSWORD),
    ]);

    $page = visit('/login');
    $page->type('username', 'csp-admin@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIsNot('/login')
        ->navigate('/admin/users')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($page->script("() => document.querySelectorAll('[onsubmit],[onerror],[onclick],[onchange]').length"))->toBe(0);
});
