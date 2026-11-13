<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Foundation\Application;

/**
 * P1-13 13i: how an installed edition package plugs into the test harness without Community naming it. The package
 * names its hook classes in its edition manifest (`test_hooks`, see Juwri\Core\Editions\EditionPackages); the hook is
 * used only when its class is autoloadable (the package's tests are autoloaded in development).
 */
interface EditionTestHook
{
    /**
     * Before the providers register (CreatesApplication). $licensed = false for a Community test case
     * (CommunityTestCase), which must boot as an unlicensed install.
     */
    public static function beforeBootstrap(Application $app, bool $licensed): void;

    /** At the start of every test, after the application booted (TestCase::setUp): reset the edition's static state. */
    public static function setUp(): void;

    /**
     * P1-13 13i part 2b: create the edition's plan row for a test that only needs "a store owner on a plan" (the
     * Community tests call {@see TestPlans::create()}, never the plan model). Return null when the edition sells no
     * plans — the Community stand-in is used then.
     *
     * @param  array<string, mixed>  $attributes  plan columns (name, prices, settings, status, …)
     */
    public static function createPlan(array $attributes): ?object;

    /** Delete every plan row the edition holds (a test that wants an empty plan catalogue). */
    public static function purgePlans(): void;

    /**
     * Class names of the edition's route middleware a Community test passes to withoutMiddleware() next to the
     * Community ones (e.g. the reseller suspension wall).
     *
     * @return list<class-string>
     */
    public static function middleware(): array;

    /**
     * Documented same-layer module edges the package adds to the boundary test's list (module => modules it may allow).
     *
     * @return array<string, list<string>>
     */
    public static function sameLayerEdges(): array;

    /**
     * The package's entries of the module-boundary baseline (using class => non-public classes of other modules it may
     * still use); the architecture test merges them per class into Community's baseline.
     *
     * @return array<string, list<string>>
     */
    public static function boundaryBaseline(): array;

    /**
     * Class-name prefixes the package's classes are scoped out of the Pest arch presets with.
     *
     * @return array{banned_functions: list<string>, laravel: list<string>}
     */
    public static function archPresetIgnores(): array;

    /**
     * The package's rows of the route-tenant-scope manifest (route => scoping mechanism), merged into Community's.
     *
     * @return array<string, string>
     */
    public static function routeScopeManifest(): array;
}
