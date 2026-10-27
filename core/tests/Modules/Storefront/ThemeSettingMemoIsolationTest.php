<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * theme_setting() memoises its DB read for the life of a request, because a single
 * render calls it many times. The memo was keyed on `spl_object_id(request())` —
 * and PHP recycles object ids: once a Request is garbage-collected, the very next
 * object allocated can be handed the same id. A fresh request then reads the
 * PREVIOUS request's cached values, including a cached null.
 *
 * That is why Tests\Modules\Storefront\ThemeJsonCustomizerTest sporadically failed on CI with
 * "Failed asserting that null is identical to 'Published Title'" while passing
 * locally — it builds several Request objects in sequence, and GC timing differs
 * between machines. Under a long-lived worker (Octane, a queue worker rendering
 * views) the same collision would serve one restaurant's theme settings into
 * another request.
 *
 * These tests force the collision deliberately rather than waiting for it.
 */
class ThemeSettingMemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(string $template = 'aurora'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return $store;
    }

    /**
     * Bind a brand-new Request.
     *
     * The old instance must be released from the container BEFORE the new one is
     * allocated — otherwise it is still referenced at allocation time, its id is
     * still occupied, and PHP hands out a fresh one. Getting that order wrong makes
     * the collision impossible to reproduce and the tests below vacuous.
     */
    private function rebind(): Request
    {
        $this->app->forgetInstance('request');
        gc_collect_cycles();

        $request = Request::create('/', 'GET');
        $this->app->instance('request', $request);

        return $request;
    }

    /**
     * The direct reproduction: same post, value changed between two requests whose
     * object ids collide. The second request must see the new value.
     */
    public function test_a_recycled_request_id_does_not_serve_the_previous_requests_values(): void
    {
        $store = $this->storeFixture();
        ThemeSetting::create([
            'store_id' => $store->id, 'template' => 'aurora',
            'settings' => ['texts.hero_title' => 'First Value'],
        ]);

        $first = $this->rebind();
        $firstId = spl_object_id($first);
        $this->assertSame('First Value', theme_setting($store->id, 'texts.hero_title'));

        // Publish a new value, then start a "new request" — dropping every reference
        // to the old one so PHP is free to hand its id straight back.
        ThemeSetting::where('store_id', $store->id)->update([
            'settings' => json_encode(['texts.hero_title' => 'Second Value']),
        ]);
        unset($first);           // drop the local ref; rebind() releases the container's
        $second = $this->rebind();

        // Guard the premise: if PHP did not recycle the id, this test proves nothing,
        // so say so loudly rather than passing for the wrong reason.
        $this->assertSame(
            $firstId,
            spl_object_id($second),
            'Premise failed: PHP did not recycle the request object id, so the collision under test was never triggered.'
        );

        $this->assertSame(
            'Second Value',
            theme_setting($store->id, 'texts.hero_title'),
            'A new request inherited the previous request memo through a recycled spl_object_id.'
        );
    }

    /**
     * The nastier variant, and the one that actually bit CI: the first request
     * cached a MISS (no ThemeSetting row yet). A later request with a recycled id
     * must not keep reporting "no settings" once the row exists.
     */
    public function test_a_recycled_request_id_does_not_serve_a_cached_miss(): void
    {
        $store = $this->storeFixture();

        $first = $this->rebind();
        $firstId = spl_object_id($first);
        // No ThemeSetting row yet -> the memo stores a null for this post.
        $this->assertNull(theme_setting($store->id, 'texts.hero_title'));

        ThemeSetting::create([
            'store_id' => $store->id, 'template' => 'aurora',
            'settings' => ['texts.hero_title' => 'Published Title'],
        ]);
        unset($first);           // drop the local ref; rebind() releases the container's
        $second = $this->rebind();

        $this->assertSame(
            $firstId,
            spl_object_id($second),
            'Premise failed: PHP did not recycle the request object id, so the collision under test was never triggered.'
        );

        $this->assertSame(
            'Published Title',
            theme_setting($store->id, 'texts.hero_title'),
            'A new request inherited a cached MISS from the previous request through a recycled spl_object_id.'
        );
    }

    /**
     * The memo must still exist — it is why the helper is cheap to call dozens of
     * times per render. Within ONE request a repeated read must not re-query.
     */
    public function test_the_memo_still_avoids_repeat_queries_within_one_request(): void
    {
        $store = $this->storeFixture();
        ThemeSetting::create([
            'store_id' => $store->id, 'template' => 'aurora',
            'settings' => ['texts.hero_title' => 'Memoised'],
        ]);

        $this->rebind();
        $this->assertSame('Memoised', theme_setting($store->id, 'texts.hero_title'));

        DB::enableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('Memoised', theme_setting($store->id, 'texts.hero_title'));
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'Repeated reads in one request should be served from the memo, not re-queried.');
    }
}
