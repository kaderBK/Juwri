<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * POS P1: register orders flow into the same pipeline (source=pos), reach the KDS
 * when sent, stay out of it (and out of inventory) while held, and settle with
 * correct cash change. Gated by the `pos` permission + `pos_enabled` plan flag.
 */
class PosRegisterTest extends TestCase
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

    private function storeFixture(bool $posEnabled = true, ?User $owner = null): Store
    {
        // Reuse an existing owner (to give one owner several restaurants) or make a fresh one.
        if (! $owner) {
            $plan = TestPlans::create([
                'name' => 'P '.Str::random(5), 'description' => 'T',
                'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
                // Array (NOT json_encode) — settings is cast to object; double-encoding breaks plan_feature().
                'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => $posEnabled ? '1' : '0'],
                'status' => 1,
            ]);
            $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        }
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $role = 'cashier'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    private function item(Store $r, float $price = 10, array $extra = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create(array_merge([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1,
        ], $extra));
    }

    // ── Service-level (the invariants) ────────────────────────────────────────

    public function test_send_to_kitchen_creates_pos_order_visible_to_kds(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 12);

        $order = PosService::createOrder($r, $staff, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        $this->assertSame('pos', $order->source);
        $this->assertNull($order->held_at);
        $this->assertSame((int) $staff->id, (int) $order->created_by_staff_id);
        // Server-side price: 12 * 2 = 24 (client sent no price).
        $this->assertEquals(24, $order->total);

        // Mirrors the KDS poll query — a sent order is visible.
        $visible = Order::whereIn('status', ['new', 'preparing', 'ready'])
            ->whereNull('held_at')->where('store_id', $r->id)->pluck('id');
        $this->assertTrue($visible->contains($order->id));
    }

    public function test_held_order_excluded_from_kitchen_and_inventory(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 10, ['track_inventory' => 1, 'stock_qty' => 10]);

        $order = PosService::createOrder($r, $staff, [
            'type' => 'takeaway', 'held' => true,
            'items' => [['item_id' => $item->id, 'quantity' => 3]],
        ]);

        $this->assertNotNull($order->held_at);
        // Not on the KDS.
        $visible = Order::whereIn('status', ['new', 'preparing', 'ready'])
            ->whereNull('held_at')->where('store_id', $r->id)->pluck('id');
        $this->assertFalse($visible->contains($order->id));
        // Inventory untouched while held.
        $this->assertSame(10, (int) $item->fresh()->stock_qty);
    }

    public function test_resume_sends_to_kitchen_and_deducts_inventory(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 10, ['track_inventory' => 1, 'stock_qty' => 10]);

        $order = PosService::createOrder($r, $staff, [
            'type' => 'takeaway', 'held' => true,
            'items' => [['item_id' => $item->id, 'quantity' => 3]],
        ]);
        PosService::resume($order->fresh());

        $order->refresh();
        $this->assertNull($order->held_at);
        $this->assertContains($order->status, ['new', 'preparing']);
        $this->assertSame(7, (int) $item->fresh()->stock_qty); // 10 - 3 deducted on resume
    }

    public function test_cash_settle_computes_change(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 12);
        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);

        $settled = PosService::settle($order, 'cash', 20.0);

        $this->assertTrue((bool) $settled->is_paid);
        $this->assertSame('cash', $settled->payment_method);
        $this->assertEquals(20, $settled->amount_tendered);
        $this->assertEquals(8, $settled->change_due); // 20 - 12
    }

    // ── Controller-level (gates) ──────────────────────────────────────────────

    public function test_kitchen_role_cannot_open_pos(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'kitchen'); // no 'pos' permission
        $this->actingAs($staff, 'staff')
            ->get(route('pos.index'))
            ->assertStatus(403);
    }

    public function test_plan_without_pos_is_forbidden(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $r = $this->storeFixture(posEnabled: false);
        $staff = $this->staff($r, 'cashier');
        $this->actingAs($staff, 'staff')
            ->get(route('pos.index'))
            ->assertStatus(403);
    }

    public function test_cashier_can_store_order_via_endpoint(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r, 'cashier');
        $item = $this->item($r, 9);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.store'), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('source', 'pos');
    }

    // ── Owner access (web guard) — the register must open for the owner, not 302 ─

    public function test_owner_can_open_pos_register(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        // Owner is on the `web` guard (no staff row). Must load, not bounce to /dashboard.
        $this->actingAs($owner)
            ->get(route('pos.index'))
            ->assertOk();
    }

    public function test_owner_without_pos_plan_is_forbidden(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $r = $this->storeFixture(posEnabled: false);
        $owner = User::find($r->user_id);

        $this->actingAs($owner)
            ->get(route('pos.index'))
            ->assertStatus(403);
    }

    public function test_owner_can_store_order_via_endpoint(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        $item = $this->item($r, 9);

        // Owner has no staff row → PosService gets a null staff (created_by_staff_id null).
        $this->actingAs($owner)
            ->postJson(route('pos.orders.store'), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('source', 'pos');
    }

    public function test_owner_can_only_open_own_restaurant(): void
    {
        $mine = $this->storeFixture();
        $owner = User::find($mine->user_id);
        $others = $this->storeFixture(); // different owner + restaurant

        // Passing another owner's restaurant id must 404, not leak.
        $this->actingAs($owner)
            ->get(route('pos.index', ['store' => $others->id]))
            ->assertStatus(404);
    }

    // ── Menu grid must actually populate (the enum('0','1') int-vs-string trap) ──

    public function test_pos_menu_lists_active_enum_items(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        // `menu.active` is enum('0','1'); an active item is the STRING '1' (as in production).
        // The old where('active', 1) read 1 as the enum index → the value '0' → grid was blank.
        $item = $this->item($r, 15, ['active' => '1']);

        $this->actingAs($owner)
            ->getJson(route('pos.menu'))
            ->assertOk()
            ->assertJsonFragment(['id' => $item->id, 'name' => 'Dish']);
    }

    // ── Restaurant + branch pickers ───────────────────────────────────────────

    public function test_owner_with_multiple_restaurants_sees_switcher(): void
    {
        $r1 = $this->storeFixture();
        $owner = User::find($r1->user_id);
        $r2 = $this->storeFixture(owner: $owner); // second restaurant, same owner

        $html = $this->actingAs($owner)->get(route('pos.index'))->assertOk()->getContent();

        // The switcher navigates to /pos?store=<id>; both restaurants are options.
        $this->assertStringContainsString('?store=', $html);
        $this->assertStringContainsString($r2->title, $html);
    }

    public function test_owner_can_ring_order_on_chosen_restaurant(): void
    {
        $r1 = $this->storeFixture();
        $owner = User::find($r1->user_id);
        $r2 = $this->storeFixture(owner: $owner);
        $item = $this->item($r2, 8); // item belongs to the SECOND restaurant

        // ?store=r2 must scope the order to r2 (not the owner's first post).
        $res = $this->actingAs($owner)
            ->postJson(route('pos.orders.store', ['store' => $r2->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('source', 'pos');

        $this->assertSame($r2->id, (int) Order::find($res->json('id'))->store_id);
    }

    public function test_owner_menu_endpoint_scoped_to_chosen_restaurant(): void
    {
        $r1 = $this->storeFixture();
        $owner = User::find($r1->user_id);
        $r2 = $this->storeFixture(owner: $owner);
        $item1 = $this->item($r1, 12, ['active' => '1']); // item exists only in r1

        // ?store=r2 must NOT leak r1's item; ?store=r1 must include it.
        // (The register JS threads this param onto /pos/menu so the switcher works.)
        $this->actingAs($owner)
            ->getJson(route('pos.menu', ['store' => $r2->id]))
            ->assertOk()
            ->assertJsonMissing(['id' => $item1->id]);

        $this->actingAs($owner)
            ->getJson(route('pos.menu', ['store' => $r1->id]))
            ->assertOk()
            ->assertJsonFragment(['id' => $item1->id]);
    }

    /**
     * Opening the register for a multi-language store whose menu default differs
     * from the operator's dashboard language must NOT flip the whole register UI:
     * without an explicit in-register language choice (the Quick_user_lang_code
     * cookie the switcher sets), the chrome stays in the operator's language.
     */
    public function test_register_ui_keeps_operator_language_without_an_explicit_choice(): void
    {
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('en');

        $r = $this->storeFixture();
        StoreSettings::set($r->id, 'menu_languages', json_encode(['en', 'ar']));
        StoreSettings::set($r->id, 'default_language', 'ar');

        unset($_COOKIE['Quick_user_lang_code']);
        $html = $this->actingAs($r->user)->get(route('pos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="en"', $html);
        $this->assertStringNotContainsString('<html lang="ar"', $html);
    }

    /**
     * An EXPLICIT menu-language choice (the switcher's cookie) still drives the
     * whole register UI, so a staffer who deliberately picks Arabic gets Arabic.
     */
    public function test_explicit_menu_language_cookie_drives_the_register_ui(): void
    {
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('en');

        $r = $this->storeFixture();
        StoreSettings::set($r->id, 'menu_languages', json_encode(['en', 'ar']));
        StoreSettings::set($r->id, 'default_language', 'en');

        $_COOKIE['Quick_user_lang_code'] = 'ar';
        try {
            $html = $this->actingAs($r->user)->get(route('pos.index'))->assertOk()->getContent();
            $this->assertStringContainsString('<html lang="ar"', $html);
        } finally {
            unset($_COOKIE['Quick_user_lang_code']);
        }
    }
}
