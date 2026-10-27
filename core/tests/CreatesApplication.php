<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Juwri\Core\Contracts\Editions\StoreLimitPolicy;
use Tests\Support\EditionTestHooks;
use Tests\Support\UnlimitedStoreLimits;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        /* Tests are written for the sqlite driver, where the in-memory database is empty when the
           application boots, so app_is_installed() is false for the whole test and no options row
           reaches config('settings.*'). On a persistent MySQL / PostgreSQL test database the
           schema already exists from the second test on, the app counts as installed, and boot
           loads the seeded options (include_language_code=1 → hreflang for the seeded 'fr'
           language → "Locale 'fr' is not in the list of supported locales" 500s). Pin the
           same starting point on every driver. */
        if (env('DB_CONNECTION', 'sqlite') !== 'sqlite') {
            $app->instance('setup.complete', false);
        }

        /* P1-13 13c/13i: the suite runs the installed editions licensed — each edition package's test hook (named in its
           manifest) injects a throw-away licence before the providers register. CommunityTestCase asks for no licence
           to prove Community boots; without an edition package there is no hook. */
        foreach (EditionTestHooks::all() as $hook) {
            $hook::beforeBootstrap($app, $this->loadsEnterpriseEdition());
        }

        $this->beforeBootstrap($app);

        $app->make(Kernel::class)->bootstrap();

        /* Edition store/branch limits (owner 2026-10-05): the suite's fixtures create as many stores and branches as a
           test needs, so tests run without the limits unless they ask for the real binding (enforcesStoreLimits) —
           tests/Feature/Editions/StoreLimits* prove the Community default and the licensed bindings. */
        if (! $this->enforcesStoreLimits()) {
            $app->instance(StoreLimitPolicy::class, new UnlimitedStoreLimits);
        }

        return $app;
    }

    /** Runs after bootstrap/app.php, before the providers register (a test case can inject a licence here). */
    protected function beforeBootstrap(Application $app): void {}

    /** True: the app's own StoreLimitPolicy binding applies (Community default, or the licence's). */
    protected function enforcesStoreLimits(): bool
    {
        return false;
    }

    /** The suite runs ee (licensed); a Community test case overrides this. */
    protected function loadsEnterpriseEdition(): bool
    {
        return true;
    }
}
