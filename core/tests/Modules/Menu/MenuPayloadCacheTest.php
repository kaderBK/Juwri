<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Http\Controllers\User\ModifierController;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5 — the storefront menu payload is cached per store/branch/locale, busted on any
 * menu write, and served with a conditional-GET ETag.
 */
class MenuPayloadCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave'); // one of the 17 allowed themes
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);
        MenuVariant::create(['menu_id' => $item->id, 'price' => 14.00, 'options' => '[]']);
        MenuExtra::create(['menu_id' => $item->id, 'title' => 'Cheese', 'price' => 1.50]);

        return [$store, $item];
    }

    public function test_second_load_serves_menu_from_cache(): void
    {
        [$store] = $this->store();

        $this->get(route('publicView', $store->slug))->assertOk(); // populate

        // Second load: the eager-loaded relations (only the cached assembly loads them)
        // must not be queried again.
        $relationQueries = 0;
        DB::listen(function ($q) use (&$relationQueries) {
            if (preg_match('/from\s+["`]?(menu_variants|modifiers|modifier_groups|item_modifier_group)["`]?/i', $q->sql)) {
                $relationQueries++;
            }
        });

        $this->get(route('publicView', $store->slug))->assertOk();

        $this->assertSame(0, $relationQueries, 'second load must serve the menu assembly from cache');
    }

    public function test_editing_a_menu_item_busts_the_cache(): void
    {
        [$store, $item] = $this->store();
        $before = StorefrontMenuCache::version($store->id);

        $item->price = 99.00;
        $item->save();

        $this->assertNotSame($before, StorefrontMenuCache::version($store->id), 'a menu write must replace the cache token');
    }

    public function test_conditional_get_returns_304(): void
    {
        [$store] = $this->store();

        $first = $this->get(route('publicView', $store->slug))->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag, 'anonymous storefront must send an ETag');

        $this->get(route('publicView', $store->slug), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_qr_scan_is_never_304_so_scans_still_record(): void
    {
        [$store] = $this->store();
        $first = $this->get(route('publicView', $store->slug))->assertOk();
        $etag = $first->headers->get('ETag');

        // A tracked scan carries qr-id — the ETag short-circuit is skipped for it, so it
        // is NEVER answered with a bare 304 (which would skip scan recording).
        $qr = app_xor_encrypt($store->slug, 'qr-scan');
        $status = $this->get(route('publicView', $store->slug).'?qr-id='.$qr, ['If-None-Match' => $etag])->status();
        $this->assertNotSame(304, $status, 'a qr-id scan must not be answered with a 304');
    }

    public function test_an_evicted_token_is_a_cache_miss_not_a_revert_to_v1(): void
    {
        [$store, $item] = $this->store();
        config(['perf.menu_cache_ttl' => 300]);

        // store()'s menu writes already bumped the counter; clear it so the pre-edit
        // payload is cached at the SAME key the buggy fallback-to-1 reproduces —
        // otherwise the pre-fix revert lands on an unused key and hides the bug.
        Cache::forget('menu.ver:'.$store->id);

        // 1. Pre-edit payload cached under the store's current token (v1 pre-fix).
        $this->assertSame('PRE-EDIT', StorefrontMenuCache::remember($store->id, null, 'en', fn () => 'PRE-EDIT'));

        // 2. The merchant edits a price -> the token is replaced -> a new key.
        $item->price = 99.00;
        $item->save();
        $this->assertSame('POST-EDIT', StorefrontMenuCache::remember($store->id, null, 'en', fn () => 'POST-EDIT'));

        // 3. redis `allkeys-lru` evicts the tiny token; the large payloads survive.
        Cache::forget('menu.ver:'.$store->id);

        // BEFORE the fix version() fell back to 1, key() rebuilt the step-1 key, and
        // this returned 'PRE-EDIT' — the store served at pre-edit prices.
        $this->assertSame(
            'FRESH',
            StorefrontMenuCache::remember($store->id, null, 'en', fn () => 'FRESH'),
            'a missing menu-cache token must behave as a MISS, never as a revert to v1'
        );
    }

    public function test_an_evicted_token_does_not_resurrect_the_pre_edit_etag(): void
    {
        [$store, $item] = $this->store();

        // Clear the counter so $before is captured at v1 — the value the buggy
        // fallback reverts to after eviction (otherwise the revert lands elsewhere).
        Cache::forget('menu.ver:'.$store->id);

        $before = StorefrontMenuCache::signature($store->id, null, 'en');
        $item->price = 99.00;
        $item->save();
        $this->assertNotSame($before, StorefrontMenuCache::signature($store->id, null, 'en'));

        Cache::forget('menu.ver:'.$store->id);

        // BEFORE the fix the token fell back to 1 and the signature became bit-identical
        // to $before (max(id) unchanged by a price edit), so a browser holding the
        // pre-edit ETag was answered 304 and re-rendered its own stale body.
        $this->assertNotSame(
            $before,
            StorefrontMenuCache::signature($store->id, null, 'en'),
            'an evicted token must not resurrect the pre-edit ETag'
        );
    }

    public function test_the_token_has_no_expiry(): void
    {
        [$store, $item] = $this->store();
        $item->price = 99.00;
        $item->save();                                   // bust()

        $this->travel(31)->days();

        $this->assertNotNull(
            StorefrontMenuCache::peek($store->id),
            'the menu-cache token must not expire on its own — a quiet store used to lose it after 30 days'
        );
    }

    public function test_peek_does_not_mint_a_token(): void
    {
        [$store] = $this->store();
        Cache::forget('menu.ver:'.$store->id);

        $this->assertNull(StorefrontMenuCache::peek($store->id));
        $this->assertNull(Cache::get('menu.ver:'.$store->id), 'peek() must be read-only');
    }

    /**
     * C1: MenuVariantOption is loaded into the cached payload (Menu::variantOptions /
     * attachedVariantOptions), so editing a group must invalidate both the cached payload
     * and the ETag signature. Before the fix the model lacked BustsMenuCache, so a rename
     * left both frozen for the whole TTL.
     */
    public function test_editing_a_variant_option_group_busts_the_cache_and_etag(): void
    {
        [$store] = $this->store();

        // create() must bust
        $verBeforeCreate = StorefrontMenuCache::version($store->id);
        $group = MenuVariantOption::create([
            'store_id' => $store->id, 'is_global' => true, 'menu_id' => null,
            'title' => 'Size', 'options' => ['Small', 'Large'],
        ]);
        $this->assertNotSame($verBeforeCreate, StorefrontMenuCache::version($store->id), 'creating a variant-option group must bust the cache');

        // update() must bust the payload token AND change the ETag signature
        $verBeforeEdit = StorefrontMenuCache::version($store->id);
        $sigBeforeEdit = StorefrontMenuCache::signature($store->id, null, 'en');
        $group->update(['title' => 'Sizes']);
        $this->assertNotSame($verBeforeEdit, StorefrontMenuCache::version($store->id), 'editing a variant-option group must bust the cache');
        $this->assertNotSame($sigBeforeEdit, StorefrontMenuCache::signature($store->id, null, 'en'), 'editing a variant-option group must change the ETag');

        // delete() must bust
        $verBeforeDelete = StorefrontMenuCache::version($store->id);
        $group->delete();
        $this->assertNotSame($verBeforeDelete, StorefrontMenuCache::version($store->id), 'deleting a variant-option group must bust the cache');
    }

    /**
     * C2: attaching/detaching a global modifier is a pivot-only write that fires no model
     * event, so BustsMenuCache never runs — the controller must bust explicitly. Before the
     * fix a newly attached "Extra cheese" never appeared to customers for the whole TTL.
     */
    public function test_attaching_and_detaching_a_modifier_busts_the_cache(): void
    {
        [$store, $item] = $this->store();
        $owner = User::find($store->user_id);
        $this->actingAs($owner);

        $extra = MenuExtra::create(['store_id' => $store->id, 'is_global' => true, 'title' => 'Bacon', 'price' => 2.00]);
        $group = MenuVariantOption::create([
            'store_id' => $store->id, 'is_global' => true, 'menu_id' => null,
            'title' => 'Crust', 'options' => ['Thin', 'Thick'],
        ]);

        $controller = new ModifierController;
        $json = ['HTTP_ACCEPT' => 'application/json']; // force the JSON return path (no back())

        $ver = StorefrontMenuCache::version($store->id);
        $controller->attachExtra(Request::create('', 'POST', ['extra_id' => $extra->id], [], [], $json), $item->id);
        $this->assertNotSame($ver, $ver = StorefrontMenuCache::version($store->id), 'attachExtra must bust the cache');

        $controller->detachExtra(Request::create('', 'DELETE', [], [], [], $json), $item->id, $extra->id);
        $this->assertNotSame($ver, $ver = StorefrontMenuCache::version($store->id), 'detachExtra must bust the cache');

        $controller->attachGroup(Request::create('', 'POST', ['group_id' => $group->id], [], [], $json), $item->id);
        $this->assertNotSame($ver, $ver = StorefrontMenuCache::version($store->id), 'attachGroup must bust the cache');

        $controller->detachGroup(Request::create('', 'DELETE', [], [], [], $json), $item->id, $group->id);
        $this->assertNotSame($ver, StorefrontMenuCache::version($store->id), 'detachGroup must bust the cache');
    }
}
