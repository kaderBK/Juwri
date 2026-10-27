<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Models\User;
use Tests\Browser\Support\Fixtures;

/** P1-18: the platform admin signs in and opens the admin dashboard and the user list. */
it('signs an admin in and opens the admin dashboard', function () {
    User::factory()->create([
        'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now(),
        'username' => 'browser_admin', 'email' => 'browser-admin@example.test', 'password' => bcrypt(Fixtures::PASSWORD),
    ]);
    Fixtures::owner(['username' => 'listed_owner', 'email' => 'listed-owner@example.test']);

    $page = visit('/login');
    $page->type('username', 'browser-admin@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIsNot('/login') // Pest 5 browser: click no longer waits for the redirect; navigating now races the login
        ->navigate('/admin/dashboard')
        ->assertPathIs('/admin/dashboard')
        ->assertNoJavaScriptErrors();

    $page->navigate('/admin/users')->assertSee('listed-owner@example.test');
});

it('keeps an owner out of the admin area', function () {
    Fixtures::owner(['username' => 'plain_owner', 'email' => 'plain-owner@example.test']);

    $page = visit('/login');
    $page->type('username', 'plain-owner@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIsNot('/login') // Pest 5 browser: click no longer waits for the redirect; navigating now races the login
        ->navigate('/admin/dashboard')
        ->assertPathIsNot('/admin/dashboard');
});
