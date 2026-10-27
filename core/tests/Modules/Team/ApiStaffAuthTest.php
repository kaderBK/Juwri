<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Staff app: token issuance (password + PIN), role-permission gating, order
 * status updates, KDS bump/recall, and logout/revocation.
 */
class ApiStaffAuthTest extends TestCase
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

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $role = 'manager', array $extra = []): StaffMember
    {
        return StaffMember::create(array_merge([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'Staff '.Str::random(4), 'email' => Str::lower(Str::random(6)).'@ex.com',
            'password' => Hash::make('secret123'), 'pin' => '4321', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    // ── Auth ───────────────────────────────────────────────────────────────────

    public function test_password_login_issues_token(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);

        $out = $this->postJson(route('api.v1.staff.login', $r->id), ['email' => $s->email, 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('staff.role', 'manager')->json();
        $this->assertNotEmpty($out['token']);
        $this->assertContains('orders', $out['staff']['permissions']);

        $this->postJson(route('api.v1.staff.login', $r->id), ['email' => $s->email, 'password' => 'nope'])
            ->assertUnauthorized();
    }

    public function test_pin_login(): void
    {
        $r = $this->storeFixture();
        $this->staff($r, 'kitchen', ['pin' => '9911']);

        $this->postJson(route('api.v1.staff.pin', $r->id), ['pin' => '9911'])->assertOk()->assertJsonStructure(['token']);
        $this->postJson(route('api.v1.staff.pin', $r->id), ['pin' => '0000'])->assertUnauthorized();
    }

    // ── Permission gating ──────────────────────────────────────────────────────

    public function test_kitchen_role_blocked_from_orders(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r, 'kitchen'); // kitchen = kds only
        $token = $this->postJson(route('api.v1.staff.pin', $r->id), ['pin' => $s->pin])->json('token');

        $this->withToken($token)->getJson(route('api.v1.staff.orders'))->assertForbidden();
        $this->withToken($token)->getJson(route('api.v1.staff.kds'))->assertOk();
    }

    // ── Orders + KDS ────────────────────────────────────────────────────────────

    public function test_manager_updates_order_status(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r, 'manager');
        $token = $this->postJson(route('api.v1.staff.login', $r->id), ['email' => $s->email, 'password' => 'secret123'])->json('token');
        $order = Order::create(['store_id' => $r->id, 'type' => 'on-table', 'status' => 'pending', 'created_at' => now()]);

        $this->withToken($token)
            ->putJson(route('api.v1.staff.orders.status', $order->id), ['status' => 'preparing'])
            ->assertOk()->assertJsonPath('status', 'preparing');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'preparing']);
    }

    public function test_kds_ticket_and_bump(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r, 'kitchen');
        $token = $this->postJson(route('api.v1.staff.pin', $r->id), ['pin' => $s->pin])->json('token');

        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Soup', 'price' => 5, 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'on-table', 'status' => 'pending', 'created_at' => now()]);
        $oi = OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'status' => 'queued']);

        $this->withToken($token)->getJson(route('api.v1.staff.kds'))
            ->assertOk()->assertJsonPath('data.0.items.0.name', 'Soup');

        $this->withToken($token)->putJson(route('api.v1.staff.kds.bump', $oi->id))
            ->assertOk()->assertJsonPath('status', 'preparing');
        $this->assertDatabaseHas('order_items', ['id' => $oi->id, 'status' => 'preparing']);
    }

    public function test_staff_cannot_touch_other_restaurant_kds_item(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r, 'kitchen');
        $token = $this->postJson(route('api.v1.staff.pin', $r->id), ['pin' => $s->pin])->json('token');

        $other = $this->storeFixture();
        $menu = Menu::create(['store_id' => $other->id, 'name' => 'X', 'price' => 5, 'active' => 1]);
        $order = Order::create(['store_id' => $other->id, 'type' => 'on-table', 'status' => 'pending', 'created_at' => now()]);
        $oi = OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'status' => 'queued']);

        $this->withToken($token)->putJson(route('api.v1.staff.kds.bump', $oi->id))->assertNotFound();
    }

    // ── Logout ───────────────────────────────────────────────────────────────────

    public function test_logout_revokes_token(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $token = $this->postJson(route('api.v1.staff.login', $r->id), ['email' => $s->email, 'password' => 'secret123'])->json('token');

        $this->withToken($token)->postJson(route('api.v1.staff.logout'))->assertOk();
        $this->withToken($token)->getJson(route('api.v1.staff.me'))->assertUnauthorized();
    }

    public function test_no_token_rejected(): void
    {
        $this->getJson(route('api.v1.staff.me'))->assertUnauthorized();
    }

    // ── Menu edit + analytics permissions ──────────────────────────────────────

    public function test_manager_can_edit_menu_and_read_analytics(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r, 'manager'); // has menu_edit + analytics
        $token = $this->postJson(route('api.v1.staff.login', $r->id), ['email' => $s->email, 'password' => 'secret123'])->json('token');

        $item = $this->withToken($token)
            ->postJson(route('api.v1.staff.menu.items.store'), ['name' => 'Special', 'price' => 14])
            ->assertCreated()->json();
        $this->assertDatabaseHas('items', ['id' => $item['id'], 'store_id' => $r->id]);

        $this->withToken($token)
            ->patchJson(route('api.v1.staff.menu.items.soldout', $item['id']))
            ->assertOk()->assertJsonPath('is_sold_out', true);

        $this->withToken($token)->getJson(route('api.v1.staff.analytics'))
            ->assertOk()->assertJsonStructure(['orders' => ['total'], 'revenue' => ['gross']]);
    }

    public function test_waiter_blocked_from_menu_and_analytics(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r, 'waiter'); // orders only
        $token = $this->postJson(route('api.v1.staff.pin', $r->id), ['pin' => $s->pin])->json('token');

        $this->withToken($token)->postJson(route('api.v1.staff.menu.items.store'), ['name' => 'X', 'price' => 1])->assertForbidden();
        $this->withToken($token)->getJson(route('api.v1.staff.analytics'))->assertForbidden();
    }
}
