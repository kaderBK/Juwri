<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T6-2: choice-group (variant-option) validation + pricing in OrderApiService.
 * Groups are snapshotted onto order_items.options and priced into the order total;
 * required/min/max violations are 422s and legacy (no-options) payloads are
 * unaffected.
 */
class PosOptionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Store,1:Menu} */
    private function restaurantAndItem(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        return [$r, $item];
    }

    private function group(Store $r, Menu $item, array $attr): MenuVariantOption
    {
        $g = MenuVariantOption::create(array_merge([
            'store_id' => $r->id, 'is_global' => false, 'menu_id' => $item->id,
            'title' => 'Size', 'options' => ['Small', 'Large'], 'option_prices' => [0, 3],
            'required' => false, 'min_select' => 0, 'max_select' => null, 'active' => 1,
        ], $attr));
        $item->attachedVariantOptions()->attach($g->id, ['active' => true, 'position' => 0]);

        return $g;
    }

    public function test_required_group_without_selection_is_422(): void
    {
        [$r, $item] = $this->restaurantAndItem();
        $this->group($r, $item, ['required' => true]);

        $this->expectException(ValidationException::class);
        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
    }

    public function test_min_select_violation_is_422(): void
    {
        [$r, $item] = $this->restaurantAndItem();
        $g = $this->group($r, $item, ['min_select' => 2]);

        $this->expectException(ValidationException::class);
        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'options' => [['group_id' => $g->id, 'selected' => ['Small']]]]],
        ]);
    }

    public function test_max_select_violation_is_422(): void
    {
        [$r, $item] = $this->restaurantAndItem();
        $g = $this->group($r, $item, ['max_select' => 1]);

        $this->expectException(ValidationException::class);
        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'options' => [['group_id' => $g->id, 'selected' => ['Small', 'Large']]]]],
        ]);
    }

    public function test_unknown_option_label_is_422(): void
    {
        [$r, $item] = $this->restaurantAndItem();
        $g = $this->group($r, $item, []);

        $this->expectException(ValidationException::class);
        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'options' => [['group_id' => $g->id, 'selected' => ['Enormous']]]]],
        ]);
    }

    public function test_group_from_another_item_is_422(): void
    {
        [$rA, $itemA] = $this->restaurantAndItem();
        [$rB, $itemB] = $this->restaurantAndItem();
        $gB = $this->group($rB, $itemB, []);

        // Posting restaurant B's group on restaurant A's item must be rejected.
        $this->expectException(ValidationException::class);
        OrderApiService::create($rA->user_id, $rA->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $itemA->id, 'quantity' => 1, 'options' => [['group_id' => $gB->id, 'selected' => ['Small']]]]],
        ]);
    }

    public function test_valid_selection_prices_into_total_and_snapshots(): void
    {
        [$r, $item] = $this->restaurantAndItem(); // base price 10
        $g = $this->group($r, $item, ['required' => true]); // Large = +3

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 2, 'options' => [['group_id' => $g->id, 'selected' => ['Large']]]]],
        ]);
        $order->refresh();

        // (10 base + 3 option) × 2 = 26
        $this->assertEqualsWithDelta(26.0, (float) $order->total, 0.001);

        $snap = $order->items->first()->options;
        $this->assertSame($g->id, $snap[0]['group_id']);
        $this->assertSame('Large', $snap[0]['selected'][0]['option']);
        $this->assertEqualsWithDelta(3.0, (float) $snap[0]['selected'][0]['price'], 0.001);
    }

    public function test_legacy_payload_without_options_is_unaffected(): void
    {
        [$r, $item] = $this->restaurantAndItem();
        // No group attached; a plain order still prices to base × qty.
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 3]],
        ]);
        $order->refresh();

        $this->assertEqualsWithDelta(30.0, (float) $order->total, 0.001);
        $this->assertNull($order->items->first()->options);
    }

    // T6-5 sweep: held → resume preserves the snapshotted options.
    public function test_held_order_resume_keeps_options(): void
    {
        [$r, $item] = $this->restaurantAndItem();
        $g = $this->group($r, $item, ['required' => true]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'held' => true,
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'options' => [['group_id' => $g->id, 'selected' => ['Large']]]]],
        ]);
        $this->assertNotNull($order->held_at);

        PosService::resume($order->refresh());
        $order->refresh();

        $this->assertNull($order->held_at);
        $snap = $order->items->first()->options;
        $this->assertSame('Large', $snap[0]['selected'][0]['option']);
        $this->assertEqualsWithDelta(3.0, (float) $snap[0]['selected'][0]['price'], 0.001);
    }

    // T6-5 sweep: voiding an unpaid options order works and leaves the snapshot intact.
    public function test_void_unaffected_by_options(): void
    {
        [$r, $item] = $this->restaurantAndItem();
        $g = $this->group($r, $item, ['required' => true]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'options' => [['group_id' => $g->id, 'selected' => ['Large']]]]],
        ]);

        $adj = PosService::void($order->refresh(), null, 'test void');
        $this->assertSame('void', $adj->type);
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Large', $order->items->first()->options[0]['selected'][0]['option']);
    }

    // T6-5 sweep: z-report sales include option revenue (Order::total carries it).
    public function test_zreport_includes_option_revenue(): void
    {
        [$r, $item] = $this->restaurantAndItem();
        $g = $this->group($r, $item, ['required' => true]); // Large = +3

        $owner = User::find($r->user_id);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);
        $shift = PosService::openShift($r, $staff, null, 0.0);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'options' => [['group_id' => $g->id, 'selected' => ['Large']]]]],
        ]);
        PosService::settle($order->refresh(), 'cash', 100.0);

        $z = PosService::zReport($shift->refresh());
        // 10 base + 3 option = 13 (no tax configured in this fresh restaurant).
        $this->assertEqualsWithDelta(13.0, (float) ($z['sales_by_method']['cash'] ?? 0), 0.001);
    }
}
