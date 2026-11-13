<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Pest\Arch\Repositories\ObjectsRepository;
use Pest\Browser\Playwright\Playwright;
use Tests\Browser\Support\Fixtures;
use Tests\Browser\Support\ParseMultipartFields;
use Tests\Support\ArchParseCache;
use Tests\Support\PlaywrightReaper;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest wiring (P1-18)
|--------------------------------------------------------------------------
| The existing suite is class-based PHPUnit and stays that way: those classes extend Tests\TestCase themselves.
| This file only (a) makes closure-style Pest tests under tests/Feature and tests/Modules boot the same app
| TestCase, and (b) leaves tests/Architecture alone: arch() tests need no application.
| Run with `vendor/bin/pest` or `php artisan test` (same tests, same counts); `--parallel` works for both.
*/

pest()->extend(TestCase::class)->in('Feature', 'Modules');

// pest-plugin-browser starts `playwright run-server` whenever a browser test file is loaded (a --filter or full run too)
// and orphans its node process at the end; stop this checkout's leftover server when the run's process exits.
PlaywrightReaper::register();

// P1-18 part 3: Pest browser tests (tests/Browser) boot the same app TestCase; each file brings its own RefreshDatabase.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        if (($why = Fixtures::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        // The browser sees the app as installed (the suite default is "not installed", see phpunit.xml) and sends no real mail.
        // 'free' = the settings.default_user_plan the options seed gives a real install (the suite database holds no options).
        config(['app.installed' => true, 'settings.default_user_plan' => 'free']);
        Mail::fake();
        Notification::fake();
        ParseMultipartFields::install();
        // A control that never becomes clickable must fail the test, not hang the run (the default has no bound for clicks).
        Playwright::setTimeout(15_000);
    })
    ->in('Browser');

/*
 * arch() tests parse every class of the app (P6-11: now ~1.1 GB peak for the whole directory, via Tests\Support\ArchParseCache; it was multi-GB). Pest's parallel workers are
 * long-lived and run many files, so: give an arch file headroom, and drop Pest's cached object repository when the file ends,
 * otherwise a second arch file in the same worker (or any later test) runs out of the suite's 2G memory_limit.
 */
pest()->afterEach(function () {
    ArchParseCache::slimRepository();
})->in('Architecture');

pest()->beforeAll(function () {
    // Raise, never lower: a long-lived parallel worker may already hold more than 1.5 GB when an arch file starts (a
    // 1500M limit here killed the GitHub CI worker in ArchPresetsTest, 2026-10-29).
    ini_set('memory_limit', '3G');
    ArchParseCache::install();
})->afterAll(function () {
    $repository = new ReflectionProperty(ObjectsRepository::class, 'instance');
    $repository->setValue(null, null);
    ArchParseCache::flush();
    gc_collect_cycles();
    ini_set('memory_limit', '2G');
})->in('Architecture');
