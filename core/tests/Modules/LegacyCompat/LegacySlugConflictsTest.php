<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Import\RowLoader;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacySlugConflicts;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J52b M8 (plan §1.1 rule 4, §5.4, R4, R9): imported slugs that this install's routes shadow are listed in
 * legacy_slug_conflicts and served as the store page on the old platform's host only, before every other route.
 */
class LegacySlugConflictsTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = 'old-platform.test';

    private const ACTION = '\App\Http\Controllers\User\StoreController@publicView';

    protected function setUp(): void
    {
        parent::setUp();
        config(['legacy_compat.hosts' => [self::LEGACY]]);
        Cache::forget(LegacySlugConflicts::CACHE_KEY);
    }

    private function store(string $slug): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['scan_limit' => 999, 'table_qr' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'tmp-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'Store '.$slug,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        // Imported slugs are kept byte-for-byte (the model would rename a reserved one): set it like the importer does.
        DB::table('stores')->where('id', $store->id)->update(['slug' => $slug]);
        DB::table(RowLoader::SIDE_TABLE)->insert([
            'source_table' => 'synthetic_stores', 'source_id' => (string) $store->id, 'target_table' => 'stores',
            'target_id' => (string) $store->id, 'source_checksum' => str_repeat('0', 64), 'run_id' => 'test-run', 'created_at' => now(),
        ]);

        return $store->refresh();
    }

    private function on(): void
    {
        config(['legacy_compat.enabled' => true]);
        LegacyCompatServiceProvider::activate($this->app);
    }

    public function test_reasons_reserved_shadowed_and_clean(): void
    {
        $this->assertSame('reserved word', LegacySlugConflicts::reasonFor('admin'));
        $this->assertSame('reserved word', LegacySlugConflicts::reasonFor('AR'));
        // A static path registered before the {slug} page (not a reserved word) shadows a slug of the same name.
        $this->assertSame('shadowed by route setup', LegacySlugConflicts::reasonFor('setup'));
        $this->assertSame('not routable as one path segment', LegacySlugConflicts::reasonFor('a/b'));
        $this->assertNull(LegacySlugConflicts::reasonFor('plain-store-slug'));
        $this->assertNull(LegacySlugConflicts::reasonFor('12345'));
    }

    public function test_scan_lists_only_imported_stores_and_the_command_stores_the_list(): void
    {
        $this->on();
        $admin = $this->store('admin');
        $this->store('plain-store-slug');
        // Not imported: never listed, whatever its slug.
        $other = $this->store('blog');
        DB::table(RowLoader::SIDE_TABLE)->where('target_table', 'stores')->where('target_id', (string) $other->id)->delete();

        $this->assertSame([['store_id' => (int) $admin->id, 'slug' => 'admin', 'reason' => 'reserved word']], LegacySlugConflicts::scan());

        $this->assertSame(0, Artisan::call('legacy:slug-conflicts'));
        $this->assertSame(['admin'], DB::table(LegacySlugConflicts::TABLE)->pluck('slug')->all());
        $this->assertSame(['admin'], LegacySlugConflicts::slugs());

        // R9: the list is cached; a change reaches routes only through persist()/the command (which flush it).
        DB::table(LegacySlugConflicts::TABLE)->insert(['slug' => 'blog', 'reason' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertCount(1, LegacySlugConflicts::slugs());
        LegacySlugConflicts::flush();
        $this->assertSame(['admin', 'blog'], LegacySlugConflicts::slugs());
    }

    public function test_off_nothing_is_listed_or_registered(): void
    {
        DB::table(LegacySlugConflicts::TABLE)->insert(['slug' => 'admin', 'reason' => 'reserved word', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], LegacySlugConflicts::slugs());
        $this->assertSame([], LegacyCompatRoutes::registerSlugConflicts(self::ACTION, []));
        $this->assertSame(1, Artisan::call('legacy:slug-conflicts'));
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringStartsNotWith(LegacySlugConflicts::ROUTE_PREFIX, (string) $route->getName());
        }
    }

    public function test_no_old_platform_host_means_no_route(): void
    {
        $this->on();
        config(['legacy_compat.hosts' => []]);
        LegacySlugConflicts::persist([['store_id' => 1, 'slug' => 'admin', 'reason' => 'reserved word']]);

        $this->assertSame([], LegacyCompatRoutes::registerSlugConflicts(self::ACTION, []));
    }

    public function test_the_conflicting_slug_opens_its_store_on_the_old_host_only(): void
    {
        $this->on();
        $store = $this->store('admin');
        $plain = $this->store('plain-store-slug');
        $this->assertSame(0, Artisan::call('legacy:slug-conflicts'));

        $before = $this->routeName('http://localhost/admin');
        $this->assertSame('publicView', $this->routeName('http://'.self::LEGACY.'/'.$plain->slug));
        // Like routes/web.php: the conflict routes come BEFORE every other route.
        $existing = Route::getRoutes()->getRoutes();
        Route::setRoutes(new RouteCollection);
        $names = [];
        Route::middleware('web')->group(function () use (&$names): void {
            $names = LegacyCompatRoutes::registerSlugConflicts(self::ACTION, ['app.locale']);
        });
        foreach ($existing as $route) {
            Route::getRoutes()->add($route);
        }
        Route::getRoutes()->refreshNameLookups();
        $this->assertCount(2, $names); // the host and its www twin

        // The old printed URL wins on the old platform's host…
        $this->assertStringStartsWith(LegacySlugConflicts::ROUTE_PREFIX, (string) $this->routeName('http://'.self::LEGACY.'/admin'));
        $this->assertStringStartsWith(LegacySlugConflicts::ROUTE_PREFIX, (string) $this->routeName('http://www.'.self::LEGACY.'/admin'));
        // …this install's own host keeps its own path, and other slugs keep the normal store route.
        $this->assertSame($before, $this->routeName('http://localhost/admin'));
        $this->assertSame('publicView', $this->routeName('http://'.self::LEGACY.'/'.$plain->slug));

        $this->bootStorefront();
        $this->get('http://'.self::LEGACY.'/admin')->assertOk()->assertSee('Store admin');
        $this->assertSame((int) $store->id, (int) Store::query()->where('slug', 'admin')->value('id'));
    }

    public function test_conflict_routes_never_bind_to_this_install_s_own_hosts(): void
    {
        config(['hosts.mode' => 'split', 'app.url' => 'https://app.example.test', 'hosts.app_url' => 'https://app.example.test',
            'hosts.admin_url' => 'https://admin.example.test', 'hosts.marketing_url' => 'https://example.test',
            'hosts.storefront_url' => 'https://shops.example.test']);
        $this->on();
        $this->store('pricing');
        $this->assertSame(0, Artisan::call('legacy:slug-conflicts'));
        $this->assertSame([self::LEGACY, 'www.'.self::LEGACY], LegacySlugConflicts::hosts());

        $before = $this->routeName('https://shops.example.test/pricing');
        $existing = Route::getRoutes()->getRoutes();
        Route::setRoutes(new RouteCollection);
        Route::middleware('web')->group(fn () => LegacyCompatRoutes::registerSlugConflicts(self::ACTION, ['app.locale']));
        foreach ($existing as $route) {
            Route::getRoutes()->add($route);
        }
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame($before, $this->routeName('https://shops.example.test/pricing'), 'storefront host /pricing unchanged');
        $this->assertStringStartsWith(LegacySlugConflicts::ROUTE_PREFIX, (string) $this->routeName('https://'.self::LEGACY.'/pricing'));
    }

    public function test_unroutable_slugs_are_listed_but_never_in_a_route(): void
    {
        $this->on();
        LegacySlugConflicts::persist([
            ['store_id' => 1, 'slug' => 'a/b', 'reason' => 'not routable as one path segment'],
            ['store_id' => 2, 'slug' => ' padded', 'reason' => 'not routable as one path segment'],
        ]);
        $this->assertCount(2, LegacySlugConflicts::slugs());
        $this->assertSame([], LegacyCompatRoutes::registerSlugConflicts(self::ACTION, []));

        LegacySlugConflicts::persist([
            ['store_id' => 1, 'slug' => 'a/b', 'reason' => 'not routable as one path segment'],
            ['store_id' => 3, 'slug' => 'admin', 'reason' => 'reserved word'],
        ]);
        $patterns = [];
        LegacySlugConflicts::registerRoutes(static function (string $host, string $pattern) use (&$patterns): void {
            $patterns[] = $pattern;
        });
        $this->assertSame(['(?:admin)', '(?:admin)'], $patterns);
    }

    private function routeName(string $url): ?string
    {
        try {
            return Route::getRoutes()->match(Request::create($url, 'GET'))->getName();
        } catch (HttpException) {
            return null;
        }
    }

    private function bootStorefront(): void
    {
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }
}
