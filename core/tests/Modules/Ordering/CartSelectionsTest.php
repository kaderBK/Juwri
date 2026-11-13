<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\MultiVendor\CrossCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Fusion×Crave P3.8: the cart selections seam. cart_lines has carried
 * variation/options/extras since P0-3 and checkout has always forwarded them,
 * but nothing wrote them — a fusion shopper could not order a specific
 * size/option/extra. These cover the write path: validation against the item,
 * line identity by (menu_id + normalized selection signature), line_id
 * targeting, and the add → checkout round trip.
 */
class CartSelectionsTest extends TestCase
{
    use RefreshDatabase;

    private CrossCartService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware(\App\Http\Middleware\Installed::class);
        config(['settings.admin_allow_multivendor_cart' => true]);
        $this->svc = new CrossCartService();
    }

    /**
     * A store whose dish has one priced variant combo (15), one choice group
     * (Small +0 / Large +3) and one extra (Cheese +2) over a 10 base price.
     *
     * @return array{0: Store, 1: Menu, 2: MenuVariant, 3: MenuVariantOption, 4: MenuExtra}
     */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Store' . Str::random(3), 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        $variant = MenuVariant::create(['menu_id' => $menu->id, 'price' => 15, 'options' => ['Family'], 'active' => 1]);
        $group = MenuVariantOption::create([
            'store_id' => $r->id, 'menu_id' => $menu->id, 'title' => 'Size',
            'options' => ['Small', 'Large'], 'option_prices' => [0, 3],
        ]);
        $extra = MenuExtra::create(['menu_id' => $menu->id, 'title' => 'Cheese', 'price' => 2]);

        return [$r, $menu, $variant, $group, $extra];
    }

    // ---------------------------------------------------------------- identity

    public function test_same_item_with_different_selections_makes_two_lines(): void
    {
        [$r, $menu, , $group, $extra] = $this->store();

        $this->svc->addItem($r->id, $menu->id, 1, null, null, [['group_id' => $group->id, 'selected' => ['Small']]]);
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [['group_id' => $group->id, 'selected' => ['Large']]]);
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [], [$extra->id]);
        $this->svc->addItem($r->id, $menu->id, 1); // bare — a fourth configuration

        $lines = $this->svc->cart()[$r->id];
        $this->assertCount(4, $lines);
        $this->assertSame([1, 1, 1, 1], array_column($lines, 'quantity'));
        $this->assertCount(4, array_unique(array_column($lines, 'line_id')));
    }

    public function test_same_item_with_identical_selections_increments_one_line(): void
    {
        [$r, $menu, $variant, $group, $extra] = $this->store();

        $add = fn (array $options, array $extras) => $this->svc
            ->addItem($r->id, $menu->id, 1, null, $variant->id, $options, $extras);

        $add([['group_id' => $group->id, 'selected' => ['Small', 'Large']]], [$extra->id]);
        // Same configuration, posted in a DIFFERENT order — normalization must
        // fold it into the first line rather than forking a duplicate.
        $add([['group_id' => $group->id, 'selected' => ['Large', 'Small']]], [$extra->id, $extra->id]);

        $lines = $this->svc->cart()[$r->id];
        $this->assertCount(1, $lines);
        $this->assertSame(2, $lines[0]['quantity']);
        $this->assertSame([(int) $extra->id], $lines[0]['extras']);
    }

    public function test_empty_selection_shapes_are_all_equivalent_to_no_selection(): void
    {
        [$r, $menu, , $group] = $this->store();

        $this->svc->addItem($r->id, $menu->id, 1);
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [], []);
        // A group posted with an empty selection list is not a selection.
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [['group_id' => $group->id, 'selected' => []]], []);

        $lines = $this->svc->cart()[$r->id];
        $this->assertCount(1, $lines);
        $this->assertSame(3, $lines[0]['quantity']);
    }

    // -------------------------------------------------------------- validation

    public function test_variant_from_another_item_is_rejected(): void
    {
        [$r, $menu] = $this->store();
        [, , $foreignVariant] = $this->store();

        $this->expectException(ValidationException::class);
        $this->svc->addItem($r->id, $menu->id, 1, null, $foreignVariant->id);
    }

    public function test_extra_from_another_item_is_rejected(): void
    {
        [$r, $menu] = $this->store();
        [, , , , $foreignExtra] = $this->store();

        $this->expectException(ValidationException::class);
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [], [$foreignExtra->id]);
    }

    public function test_group_from_another_item_is_rejected(): void
    {
        [$r, $menu] = $this->store();
        [, , , $foreignGroup] = $this->store();

        $this->expectException(ValidationException::class);
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [['group_id' => $foreignGroup->id, 'selected' => ['Small']]]);
    }

    public function test_unknown_option_label_is_rejected(): void
    {
        [$r, $menu, , $group] = $this->store();

        $this->expectException(ValidationException::class);
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [['group_id' => $group->id, 'selected' => ['Gigantic']]]);
    }

    public function test_http_add_422s_on_a_tampered_selection_and_keeps_the_bag_clean(): void
    {
        [$r, $menu, , $group, $extra] = $this->store();

        $this->postJson('/order-hub/add', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'quantity' => 1,
            'variation' => 999999,
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->postJson('/order-hub/add', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'quantity' => 1,
            'extras' => [$extra->id + 999999],
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->postJson('/order-hub/add', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'quantity' => 1,
            'options' => [['group_id' => $group->id, 'selected' => ['Gigantic']]],
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertEmpty($this->svc->cart());
    }

    // ------------------------------------------------------------- HTTP + view

    public function test_http_add_stores_selections_and_the_view_carries_labels_and_line_id(): void
    {
        [$r, $menu, $variant, $group, $extra] = $this->store();

        $res = $this->postJson('/order-hub/add', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'quantity' => 2,
            'variation' => $variant->id,
            'options'   => [['group_id' => $group->id, 'selected' => ['Large']]],
            'extras'    => [$extra->id],
        ])->assertOk()->assertJsonPath('ok', true);

        $item = $res->json('cart.0.items.0');
        $this->assertNotNull($item['line_id']);
        $this->assertSame('Family', $item['variant']['label']);
        $this->assertSame('Large', $item['options'][0]['label']);
        $this->assertSame('Size', $item['options'][0]['group']);
        $this->assertSame(3.0, (float) $item['options'][0]['price']);
        $this->assertSame('Cheese', $item['extras'][0]['label']);
        $this->assertSame('Family · Large · Cheese', $item['selection']);
        // variant 15 (absolute) + option 3 + extra 2 = 20 per unit, × 2.
        $this->assertSame(20.0, (float) $item['unit_price']);
        $this->assertSame(40.0, (float) $item['line_total']);
    }

    public function test_line_id_targets_one_configuration_for_update_and_remove(): void
    {
        [$r, $menu, , $group] = $this->store();

        $small = $this->postJson('/order-hub/add', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'quantity' => 1,
            'options' => [['group_id' => $group->id, 'selected' => ['Small']]],
        ])->assertOk()->json('cart.0.items.0.line_id');
        $this->postJson('/order-hub/add', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'quantity' => 1,
            'options' => [['group_id' => $group->id, 'selected' => ['Large']]],
        ])->assertOk();

        // Step the Small line only.
        $items = $this->postJson('/order-hub/update', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'quantity' => 5, 'line_id' => $small,
        ])->assertOk()->json('cart.0.items');
        $this->assertCount(2, $items);
        $this->assertSame(5, collect($items)->firstWhere('line_id', $small)['quantity']);
        $this->assertSame(1, collect($items)->reject(fn ($i) => $i['line_id'] === $small)->first()['quantity']);

        // Remove the Small line only — the Large one survives.
        $items = $this->postJson('/order-hub/remove', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'line_id' => $small,
        ])->assertOk()->json('cart.0.items');
        $this->assertCount(1, $items);
        $this->assertSame('Large', $items[0]['options'][0]['label']);
    }

    /** Back-compat: no line_id keeps the pre-P3.8 first-line / all-lines behaviour. */
    public function test_update_and_remove_without_line_id_keep_legacy_behaviour(): void
    {
        [$r, $menu, , $group] = $this->store();
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [['group_id' => $group->id, 'selected' => ['Small']]]);
        $this->svc->addItem($r->id, $menu->id, 1, null, null, [['group_id' => $group->id, 'selected' => ['Large']]]);

        $this->svc->setItem($r->id, $menu->id, 4);
        $lines = $this->svc->cart()[$r->id];
        $this->assertSame([4, 1], array_column($lines, 'quantity')); // first line only

        $this->svc->removeItem($r->id, $menu->id);
        $this->assertEmpty($this->svc->cart()); // every line of that item
    }

    public function test_update_with_an_unknown_line_id_422s(): void
    {
        [$r, $menu] = $this->store();
        $this->svc->addItem($r->id, $menu->id, 1);

        $this->postJson('/order-hub/update', [
            'store_id' => $r->id, 'item_id' => $menu->id, 'quantity' => 3, 'line_id' => 987654,
        ])->assertStatus(422)->assertJsonPath('ok', false);
    }

    // ---------------------------------------------------------------- checkout

    public function test_selections_ride_through_to_the_order_item_and_price_it(): void
    {
        [$r, $menu, $variant, $group, $extra] = $this->store();

        $this->svc->addItem(
            $r->id, $menu->id, 2, 'no onions',
            $variant->id,
            [['group_id' => $group->id, 'selected' => ['Large']]],
            [$extra->id]
        );

        $results = $this->svc->checkout(['name' => 'Sam', 'phone' => '555']);
        $this->assertTrue($results[0]['ok']);

        $order = Order::where('store_id', $r->id)->where('source', 'marketplace')->firstOrFail();
        $orderItem = $order->items()->firstOrFail();

        $this->assertSame((int) $variant->id, (int) $orderItem->variation);
        $this->assertSame(2, (int) $orderItem->quantity);
        $this->assertSame('no onions', $orderItem->order_note);
        // T6-2 snapshot: group title + chosen label + its price, priced server-side.
        $snapshot = (array) $orderItem->options;
        $this->assertSame((int) $group->id, (int) $snapshot[0]['group_id']);
        $this->assertSame('Size', $snapshot[0]['title']);
        $this->assertSame('Large', $snapshot[0]['selected'][0]['option']);
        $this->assertSame(3.0, (float) $snapshot[0]['selected'][0]['price']);
        // The extra is a real OrderItemExtra row, not a stored price.
        $this->assertSame([(int) $extra->id], $orderItem->itemExtras()->pluck('extra_id')->map('intval')->all());

        // (variant 15 + option 3 + extra 2) × 2 = 40, no tax/delivery configured.
        $this->assertSame(40.0, round((float) $order->fresh()->total, 2));
    }
}
