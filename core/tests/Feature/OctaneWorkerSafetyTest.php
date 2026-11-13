<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Models\Store;
use Juwri\Core\Octane\BeginRequest;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Marketing\Models\AbExperiment;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * Phase 2 / item 16: a worker serves many requests from ONE long-lived PHP process (Laravel Octane on FrankenPHP). This
 * boots several "requests" in one application instance — Octane's own per-request step is {@see BeginRequest} plus the
 * container's scoped-instance reset — with different stores, locales, users and visitors, and asserts that nothing the
 * first one cached, set or switched on reaches the next. The worker-safety audit behind it lives in the internal run notes.
 */
class OctaneWorkerSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** What Octane does between two requests of a worker, as far as juwri state is concerned. */
    private function nextRequest(): void
    {
        app()->forgetScopedInstances();
        (new BeginRequest)->handle((object) ['sandbox' => app()]);
    }

    private function store(string $slug, string $type = 'retail'): Store
    {
        $fx = new ApiFixtures;

        return $fx->store($fx->owner(), $slug, 'Store '.$slug, $type);
    }

    public function test_every_request_gets_a_new_stamp(): void
    {
        $first = RequestEpoch::id();
        $this->assertSame($first, RequestEpoch::id(), 'stable inside one request');

        $this->nextRequest();

        $this->assertNotSame($first, RequestEpoch::id());
    }

    public function test_a_store_setting_changed_elsewhere_is_read_again_by_the_next_request(): void
    {
        $a = $this->store('worker-a');
        StoreSettings::set((int) $a->id, 'currency_code', 'SAR');
        $this->assertSame('SAR', StoreSettings::string((int) $a->id, 'currency_code'));

        // another worker saves a new value straight to the row (this process' memo is not told)
        $raw = (string) DB::table('stores')->where('id', $a->id)->value('settings');
        $this->assertStringContainsString('SAR', $raw);
        DB::table('stores')->where('id', $a->id)->update(['settings' => str_replace('SAR', 'KWD', $raw)]);

        $this->assertSame('SAR', StoreSettings::string((int) $a->id, 'currency_code'), 'inside the same request the memo holds (as before)');
        $this->nextRequest();
        $this->assertSame('KWD', StoreSettings::string((int) $a->id, 'currency_code'), 'the next request re-reads: a worker never serves a stale setting');
    }

    public function test_the_store_memos_of_the_seo_helpers_do_not_cross_requests(): void
    {
        $a = $this->store('worker-seo');
        $this->assertFalse(store_has_menu((int) $a->id));

        $cat = MenuCategory::create(['store_id' => $a->id, 'name' => 'C', 'active' => 1]);
        Menu::create(['store_id' => $a->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => 1]);

        $this->assertFalse(store_has_menu((int) $a->id), 'same request: memoised');
        $this->nextRequest();
        $this->assertTrue(store_has_menu((int) $a->id), 'next request: the item is seen');
    }

    public function test_a_visitors_ab_variant_does_not_reach_the_next_visitor(): void
    {
        AbExperiment::create(['name' => 'octane-exp', 'variants' => ['x', 'y', 'z', 'w', 'q', 'r'], 'status' => 'active']);

        $seen = [];
        foreach (range(1, 12) as $i) {
            session()->setId(Str::random(40));
            $seen[] = ab_variant('octane-exp');
            $this->nextRequest(); // a different visitor on the same worker
        }

        $this->assertGreaterThan(1, count(array_unique($seen)), 'each visitor is assigned from their own session, not from the first visitor');
    }

    public function test_the_platform_settings_are_projected_into_every_requests_config(): void
    {
        config(['app.installed' => true]); // the provider projects the settings only on an installed site
        PlatformSettings::set('site_title', 'Octane Test Title');
        $this->nextRequest();
        $this->assertSame('Octane Test Title', config('app.name'));

        PlatformSettings::set('site_title', 'Changed By An Admin');
        config(['app.name' => 'left over by the previous request']);
        $this->nextRequest();
        $this->assertSame('Changed By An Admin', config('app.name'), 'a worker applies the settings an admin saved since it started');
    }

    public function test_the_tenant_of_one_request_is_not_the_tenant_of_the_next(): void
    {
        $a = $this->store('tenant-a');
        $b = $this->store('tenant-b');

        TenantContext::current()->actAs((int) $a->account_id, [(int) $a->id]);
        $this->assertTrue(TenantContext::current()->active());
        $this->assertSame([(int) $a->id], TenantContext::current()->storeIds());

        $this->nextRequest();
        $this->assertFalse(TenantContext::current()->active(), 'the current store / account is reset: no scope carries over');
        $this->assertNull(TenantContext::current()->accountId());
        $this->assertNull(TenantContext::current()->storeIds());

        TenantContext::current()->actAs((int) $b->account_id, [(int) $b->id]);
        $this->assertSame([(int) $b->id], TenantContext::current()->storeIds());
        $this->assertSame((int) $b->account_id, TenantContext::current()->accountId());
    }

    public function test_two_stores_in_two_locales_never_bleed_into_each_other(): void
    {
        $fx = new ApiFixtures;
        $owner = $fx->owner();
        $a = $fx->store($owner, 'bleed-a', 'Alpha Shop', 'retail');
        $b = $fx->store($owner, 'bleed-b', 'Beta Shop', 'retail');
        $builder = app(ViewModelBuilder::class);

        $before = app()->getLocale();
        $first = $builder->build(new ViewModelRequest(store: $a, locale: 'ar', page: 'home'));
        $this->assertSame($before, app()->getLocale(), 'building a view model leaves the request locale as it found it');
        $this->nextRequest();
        $before = app()->getLocale();
        $second = $builder->build(new ViewModelRequest(store: $b, locale: 'en', page: 'home'));
        $this->assertSame($before, app()->getLocale());

        $this->assertNotSame($first['i18n'], $second['i18n'], 'the second request speaks its own language, not the first one\'s');
        $this->assertSame('Alpha Shop', $first['store']['name']);
        $this->assertSame('Beta Shop', $second['store']['name']);
    }

    public function test_request_scoped_switches_are_off_between_requests(): void
    {
        $this->assertFalse(StoreLimitGuard::bypassed(), 'the seeder bypass of the store limit never stays on');
        StoreLimitGuard::bypass(fn () => $this->assertTrue(StoreLimitGuard::bypassed()));
        $this->assertFalse(StoreLimitGuard::bypassed());

        Menu::withoutMenuWebhook(fn () => null);
        $r = new \ReflectionProperty(Menu::class, 'suppressMenuWebhook');
        $this->assertFalse($r->getValue(), 'the webhook suppression is restored by its finally block');
    }

    public function test_page_state_and_the_not_installed_answer_do_not_outlive_a_request(): void
    {
        view()->share('pageFeatureKey', 'crm_suite');
        app()->instance('setup.complete', false);

        $this->nextRequest();

        $this->assertNull(view()->shared('pageFeatureKey'), 'the previous page\'s feature key must not mark the next page');
        $this->assertFalse(app()->bound('setup.complete') && app()->make('setup.complete') === false, 'a cached "not installed" is asked again');
    }

    public function test_the_view_composers_decide_per_request_not_at_boot(): void
    {
        // the provider registers the admin and the storefront composers at boot; each one checks ITS request
        $source = (string) file_get_contents(app_path('Providers/ViewServiceProvider.php'));
        $this->assertStringNotContainsString('if (is_admin_url())', $source);
        $this->assertStringContainsString('adminRequest()', $source);
        $this->assertStringContainsString('frontendRequest()', $source);
    }

    public function test_the_octane_config_runs_the_juwri_step_first_and_flushes_stateful_singletons(): void
    {
        if (! class_exists(\Laravel\Octane\Octane::class)) {
            $this->markTestSkipped('laravel/octane is not installed in this vendor folder');
        }
        $received = config('octane.listeners.'.\Laravel\Octane\Events\RequestReceived::class);
        $this->assertContains(BeginRequest::class, $received);
        $this->assertContains(BeginRequest::class, config('octane.listeners.'.\Laravel\Octane\Events\TaskReceived::class));
        $this->assertContains(BeginRequest::class, config('octane.listeners.'.\Laravel\Octane\Events\TickReceived::class));

        $flush = config('octane.flush');
        foreach (['csp-nonce', \Illuminate\Foundation\Vite::class, \Juwri\Modules\Storefront\Services\StoreAddressResolver::class, \Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyMarkets::class, \Mcamara\LaravelLocalization\LaravelLocalization::class] as $name) {
            $this->assertContains($name, $flush, "{$name} holds per-request state and must be rebuilt for every request");
        }
    }

    public function test_the_worker_never_runs_the_boot_time_front_controller_redirect_or_exit(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = '1';
        try {
            $this->assertTrue(RequestEpoch::inWorker());
        } finally {
            unset($_SERVER['LARAVEL_OCTANE']);
        }
        $this->assertFalse(RequestEpoch::inWorker());
    }
}
