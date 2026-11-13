<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * POS P5 (offline tolerance): the server guarantee that makes queued-replay safe —
 * a repeated client_uuid at the POS endpoint returns the same order, never a
 * duplicate. (Service worker + IndexedDB queue are client-side / ui-unverified.)
 */
class PosOfflineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'C',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        return [$r, $staff, $item];
    }

    public function test_pos_store_is_idempotent_by_client_uuid(): void
    {
        [$r, $staff, $item] = $this->make();
        $payload = [
            // before J27-A2: 'queued-1' — the key must now be a UUID (or the pre-J27 register shape pos-<ms>-<rand>).
            'type' => 'takeaway', 'client_uuid' => '0190f3a2-7b1c-7def-8abc-00000000a001',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ];

        $first = $this->actingAs($staff, 'staff')->postJson(route('pos.orders.store'), $payload)->assertStatus(201);
        // Replay of the same queued mutation (as the offline queue would do on reconnect).
        $second = $this->actingAs($staff, 'staff')->postJson(route('pos.orders.store'), $payload)->assertStatus(201);

        $this->assertSame($first->json('id'), $second->json('id'), 'replay must return the same order');
        $this->assertSame(1, Order::where('store_id', $r->id)->count(), 'no duplicate order');
    }

    public function test_service_worker_is_served(): void
    {
        // The CACHE name must change with any shell asset or registers keep serving the old one. Bumped to pos-v16: till-calculator.js + pos-offline.js precached (v15 = keys/status never cached (v14 = cold start, v13 =
        // pos-store.js), v12 = pos-device.js, v11 = register shells cached per restaurant, v10 = bare-/pos fallback, v9 = J27-A2 per-attempt order key, v8 = D7
        // order-card channel-origin badge, v7 = pos-hold refund key, v6 = table-merge, v5 = printing).
        $res = $this->get(route('pos.sw'))->assertOk()->assertSee('pos-v16');
        $this->assertStringContainsString('/assets/pos/pos-offline.js', $res->getContent());
        $this->assertStringContainsString('/assets/pos/till-calculator.js', $res->getContent());
        $this->assertStringContainsString('javascript', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('/assets/pos/pos-print.js', $res->getContent());
        $this->assertStringContainsString('/assets/pos/pos-merge.js', $res->getContent());
    }

    public function test_the_worker_carries_offline_texts_for_en_ar_and_ur(): void
    {
        // The offline page was frozen in the locale of the request that registered the worker; it now carries every
        // locale (en, ar, ur + the current one) so it can follow the till's own URL (e.g. /ar/pos). ar/ur are RTL.
        $body = (string) $this->get(route('pos.sw'))->assertOk()->getContent();
        $this->assertStringContainsString('const OFFLINE_ALL = {', $body);
        $this->assertStringContainsString('"dir":"rtl"', $body); // ar/ur (and fa/he) are right-to-left
        $this->assertStringContainsString('"lang":"ar"', $body);
        $this->assertStringContainsString('"lang":"en"', $body);
        $this->assertStringContainsString('"lang":"ur"', $body);
    }

    public function test_offline_navigation_only_ever_serves_the_same_restaurants_shell(): void
    {
        $body = (string) $this->get(route('pos.sw'))->assertOk()->getContent();
        // The session-rendered bare /pos is no longer pre-cached as a generic shell, and nothing falls back to it.
        $this->assertStringNotContainsString("caches.match('/pos')", $body);
        $this->assertStringNotContainsString("'/pos', '/pos/menu'", $body);
        // Shells are kept per restaurant from the server's header, only for a good response, and looked up by the
        // ?store= of the navigation; no shell → the "connect once" page, never another store's register.
        $this->assertStringContainsString("res.headers.get('X-Pos-Restaurant')", $body);
        $this->assertStringContainsString('if (!res.ok || res.redirected || !id) return false;', $body);
        $this->assertStringContainsString("new URL(url).searchParams.get('store')", $body);
        $this->assertStringContainsString('const OFFLINE = {"title":', $body); // the "connect once" page, in the store's language
        $this->assertStringContainsString('status: 503', $body);
        // The first visit's own navigation is kept on request from the page (it loaded before the worker controlled it).
        $this->assertStringContainsString("e.data.type === 'pos-shell'", $body);
        $this->assertStringContainsString("type: 'pos-shell'", (string) file_get_contents(base_path('../assets/pos/pos-core.js')));
    }

    public function test_the_register_is_tagged_with_its_restaurant_for_two_restaurants_of_one_owner(): void
    {
        [$first] = $this->make();
        $owner = User::find($first->user_id);
        $second = Store::create(['user_id' => $owner->id, 'slug' => 'second-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => 'Second',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner, 'web')->get(route('pos.index', ['store' => $first->id]))->assertOk()->assertHeader('X-Pos-Restaurant', (string) $first->id);
        $this->actingAs($owner, 'web')->get(route('pos.index', ['store' => $second->id]))->assertOk()->assertHeader('X-Pos-Restaurant', (string) $second->id);
        // bare /pos renders the session's store (the last picked) — and says which, so it is cached under that store
        $this->actingAs($owner, 'web')->get(route('pos.index'))->assertOk()->assertHeader('X-Pos-Restaurant', (string) $second->id);
        // another owner's restaurant is never rendered (so never cached) for this owner
        [$foreign] = $this->make();
        $this->actingAs($owner, 'web')->get(route('pos.index', ['store' => $foreign->id]))->assertNotFound();
    }
}
