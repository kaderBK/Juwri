<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WIRING-RUN F1 follow-up — the storefront ignored the chosen language.
 *
 * The app runs in one of two locale modes, switched by settings.include_language_code:
 *
 *   prefix mode  (=1) — URLs carry /fr/…, mcamara's middleware resolves the locale.
 *   session mode (=0) — /lang/{code} stores the choice in the session, and the
 *                       `app.locale` middleware (Juwri\Core\Http\Middleware\Locale)
 *                       applies it on each request.
 *
 * In session mode the main route group got `app.locale`, but the storefront
 * group was declared with an EMPTY middleware list — so every customer-facing menu
 * rendered in the default locale no matter which language the diner picked. That
 * silently voided every storefront translation on any install with prefix mode off.
 *
 * These tests pin the locale actually reaching the storefront in BOTH modes.
 */
class StorefrontLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'French', 'direction' => 'ltr', 'active' => 1]);
    }

    private function storefront(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'loc-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Loc', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The regression this fixes: a diner who picks French must get French on the menu.
     * Asserted on the locale the request resolves to, not on rendered copy, so it does
     * not depend on any particular string staying translated.
     */
    public function test_storefront_honours_the_session_locale(): void
    {
        $store = $this->storefront();

        $this->withSession(['locale' => 'fr'])->get('/'.$store->slug);

        $this->assertSame('fr', app()->getLocale(), 'The storefront ignored the diner\'s chosen language.');
    }

    /** The storefront route must actually carry the middleware that applies it. */
    public function test_storefront_route_carries_the_locale_middleware(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'publicView');

        $this->assertNotNull($route, 'publicView route is missing.');

        $middleware = (array) ($route->getAction('middleware') ?? []);
        $this->assertTrue(
            in_array('app.locale', $middleware, true) || in_array('localize', $middleware, true),
            'publicView carries no locale middleware, so session/prefix language selection cannot reach it. Got: '.implode(', ', $middleware)
        );
    }

    /** A visitor who has chosen nothing still gets the default locale, not a crash. */
    public function test_storefront_without_a_chosen_locale_falls_back_cleanly(): void
    {
        $store = $this->storefront();

        $this->get('/'.$store->slug);

        $this->assertSame(config('app.locale'), app()->getLocale());
    }

    /* ---------------------------------------------------------------------
     * Fusion storefront — its own language memory
     * ------------------------------------------------------------------- */

    /**
     * The Fusion storefront switches language by SESSION (mk.lang) while the
     * rest of the app switches by URL PREFIX. Both used to write
     * session('locale'), and in prefix mode an unprefixed URL means "default
     * locale" — so `locale.remember` on any unprefixed page wrote the site
     * default back over the shopper's choice.
     *
     * Loading the admin panel once was enough: the storefront flipped to
     * Arabic RTL mid-session with English copy still in place. Reproduced
     * against the running site before the fix — pick English, load one admin
     * page, and every Fusion page came back `lang="ar" dir="rtl"`.
     */
    public function test_fusion_language_survives_a_page_that_resets_the_app_locale(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->get(route('mk.lang', 'en'));
        $this->assertSame('en', session('mk_locale'));

        // Exactly what locale.remember does on an unprefixed page: stamp the
        // app-wide key with the resolved (default) locale.
        session(['locale' => 'fr']);

        $this->get(route('mk.home'));
        $this->assertSame('en', app()->getLocale(), 'The shopper chose English; nothing they did asked for another language.');
    }

    /** The storefront picker still wins when the shopper uses it again. */
    public function test_fusion_language_picker_still_switches(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->get(route('mk.lang', 'en'));
        $this->get(route('mk.home'));
        $this->assertSame('en', app()->getLocale());

        $this->get(route('mk.lang', 'fr'));
        $this->get(route('mk.home'));
        $this->assertSame('fr', app()->getLocale());
    }

    /**
     * The override is scoped to the storefront on purpose — ~50 other surfaces
     * behind this middleware share the app-wide picker and must keep following
     * it.
     */
    public function test_non_storefront_surfaces_still_follow_the_app_wide_locale(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->get(route('mk.lang', 'en'));
        session(['locale' => 'fr']);

        $store = $this->storefront();
        $this->get('/'.$store->slug);

        $this->assertSame('fr', app()->getLocale(), 'mk_locale must not leak outside the Fusion storefront.');
    }
}
