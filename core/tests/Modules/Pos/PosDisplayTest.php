<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P2: the customer-facing display page + its polling-fallback state endpoint.
 * The state endpoint is scoped to the actor's own restaurant (ids never come from
 * the client), and reflects the current held/open (unpaid) order.
 */
class PosDisplayTest extends TestCase
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

    private function storeFixture(?User $owner = null): Store
    {
        if (! $owner) {
            $plan = TestPlans::create([
                'name' => 'P '.Str::random(5), 'description' => 'T',
                'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
                'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
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

    private function staff(Store $r): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
    }

    private function item(Store $r, float $price = 10): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
    }

    public function test_display_page_renders_for_owner(): void
    {
        $r = $this->storeFixture();
        $this->actingAs($r->user, 'web')
            ->get(route('pos.display', ['store' => $r->id]))->assertOk()->assertSeeHtml('pos-cart-')->assertSeeHtml('posDisplay()')->assertSeeHtml(route('pos.display.state')); // polling-fallback endpoint
    }

    public function test_state_idle_when_no_unpaid_order(): void
    {
        $r = $this->storeFixture();
        $this->actingAs($r->user, 'web')
            ->getJson(route('pos.display.state', ['store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('active', false);
    }

    public function test_state_reflects_open_order_lines(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 12);

        PosService::createOrder($r, $staff, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        $res = $this->actingAs($r->user, 'web')
            ->getJson(route('pos.display.state', ['store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('lines.0.qty', 2)
            ->assertJsonPath('lines.0.name', 'Dish');

        $this->assertEquals(24, $res->json('total'));
    }

    /** A settled (paid) order no longer drives the display → back to idle. */
    public function test_paid_order_returns_idle(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 10);

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($order, 'cash', 10, null);

        $this->actingAs($r->user, 'web')
            ->getJson(route('pos.display.state', ['store' => $r->id]))
            ->assertJsonPath('active', false);
    }

    /** Scoping: an owner cannot read another restaurant's display state (404). */
    public function test_state_scoped_to_own_restaurant(): void
    {
        $mine = $this->storeFixture();
        $theirs = $this->storeFixture();

        // Owner of $mine asking for $theirs → context() firstOrFail → 404.
        $this->actingAs($mine->user, 'web')
            ->getJson(route('pos.display.state', ['store' => $theirs->id]))
            ->assertStatus(404);
    }

    /** SYSTEMS-RUN D-P1: idle state carries display config + promo fallback. */
    public function test_idle_state_includes_config(): void
    {
        $r = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'display_tip_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'display_tip_percents', '10,15,20');

        $res = $this->actingAs($r->user, 'web')
            ->getJson(route('pos.display.state', ['store' => $r->id]))
            ->assertJsonPath('active', false)
            ->assertJsonPath('config.tip_enabled', true);
        $this->assertSame([10, 15, 20], $res->json('config.tip_percents'));
        $res->assertJsonStructure(['idle_promos']);
    }

    /** SYSTEMS-RUN D-P1: active state carries pay-status + tip config. */
    public function test_active_state_pay_status(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 10);
        PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);

        $this->actingAs($r->user, 'web')
            ->getJson(route('pos.display.state', ['store' => $r->id]))
            ->assertJsonPath('active', true)
            ->assertJsonPath('pay_status', 'awaiting_payment');
    }

    /** SYSTEMS-RUN D-P1: loyalty check-in by phone attaches the customer. */
    public function test_display_check_in_by_phone(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 10);
        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $customer = Customer::create(['name' => 'Reg', 'store_id' => $r->id, 'phone' => '55-123']);

        $this->actingAs($r->user, 'web')
            ->postJson(route('pos.display.checkIn', ['store' => $r->id]), ['phone' => '55-123'])
            ->assertJsonPath('found', true)->assertJsonPath('name', 'Reg');
        $this->assertSame($customer->id, $order->fresh()->customer_id);

        // Unknown phone → not found.
        $this->actingAs($r->user, 'web')
            ->postJson(route('pos.display.checkIn', ['store' => $r->id]), ['phone' => '00-000'])
            ->assertJsonPath('found', false);
    }
}
