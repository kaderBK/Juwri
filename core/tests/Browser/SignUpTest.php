<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Models\User;
use Tests\Browser\Support\Fixtures;

/** P1-18: the public owner sign-up form in a real browser. */
it('lets a new owner sign up and lands them signed in', function () {
    $page = visit('/signup');

    $page->type('username', 'browser_owner')
        ->type('email', 'browser-owner@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->type('password_confirmation', Fixtures::PASSWORD)
        ->check('agree_for_term')
        ->click('form.ui-auth__form button[type=submit]')
        ->assertPathIsNot('/signup')
        ->assertNoJavaScriptErrors();

    $user = User::where('email', 'browser-owner@example.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->username)->toBe('browser_owner');
});

it('keeps the visitor on the form when the username is already taken', function () {
    Fixtures::owner(['username' => 'taken_name', 'email' => 'someone@example.test']);

    $page = visit('/signup');
    $page->type('username', 'taken_name')
        ->type('email', 'fresh@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->type('password_confirmation', Fixtures::PASSWORD)
        ->check('agree_for_term')
        ->click('form.ui-auth__form button[type=submit]')
        ->assertPathIs('/signup')
        ->assertPresent('.ui-field--error #username[aria-invalid="true"]');

    expect(User::where('email', 'fresh@example.test')->exists())->toBeFalse();
});
