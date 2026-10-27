<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T6 follow-up — the POS register combo tile.
 *
 * The server contract (ComboService::resolve) was already wired into the POS
 * order path when T6 shipped; what was missing was the register's ability to
 * LIST combos and SEND a chosen one. This pins both new surfaces:
 *   • GET pos/combos returns the sellable combos in the configurator shape (and
 *     nothing when the feature is off — no 403, no dead tile).
 *   • pos.orders.store with a `combos` payload prices the line on the SERVER,
 *     from the owner's rows, never from anything the till could send.
 */
class PosComboTileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_combos' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(): array
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
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        return [$r, $staff];
    }

    /** Combo 15.00; main = burger(+0)/steak(+3.50); drink = water(+0)/cola(+0.75). */
    private function combo(Store $store): array
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'M', 'active' => 1]);
        $item = fn (string $name, float $p) => Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'price' => $p, 'active' => 1,
        ]);
        $burger = $item('Burger', 10.00);
        $steak = $item('Steak', 22.00);
        $water = $item('Water', 2.00);
        $cola = $item('Cola', 3.00);

        $combo = Combo::create([
            'store_id' => $store->id, 'name' => 'Family Meal', 'description' => 'Feeds two',
            'price' => 15.00, 'active' => true, 'position' => 0,
        ]);
        $main = ComboSlot::create(['combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Main', 'min_select' => 1, 'max_select' => 1, 'position' => 0]);
        $drink = ComboSlot::create(['combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Drink', 'min_select' => 1, 'max_select' => 1, 'position' => 1]);
        $mk = fn (ComboSlot $s, Menu $m, float $up, int $pos) => ComboSlotOption::create([
            'combo_slot_id' => $s->id, 'combo_id' => $combo->id, 'store_id' => $store->id,
            'menu_id' => $m->id, 'upcharge' => $up, 'active' => true, 'position' => $pos,
        ]);

        return [
            'combo' => $combo, 'main' => $main, 'drink' => $drink,
            'burger' => $mk($main, $burger, 0, 0), 'steak' => $mk($main, $steak, 3.50, 1),
            'water' => $mk($drink, $water, 0, 0), 'cola' => $mk($drink, $cola, 0.75, 1),
        ];
    }

    public function test_pos_combos_lists_sellable_combos_in_the_configurator_shape(): void
    {
        [$r, $staff] = $this->storeFixture();
        $c = $this->combo($r);

        $res = $this->actingAs($staff, 'staff')
            ->getJson(route('pos.combos', ['store' => $r->id]))
            ->assertOk()
            ->assertJsonStructure(['combos' => [['id', 'name', 'price', 'saving', 'slots' => [['id', 'title', 'min', 'max', 'options' => [['id', 'name', 'upcharge']]]]]]]);

        $combo = $res->json('combos.0');
        $this->assertSame((int) $c['combo']->id, $combo['id']);
        $this->assertSame(15.0, (float) $combo['price']);
        $this->assertCount(2, $combo['slots']); // main + drink
    }

    public function test_pos_combos_is_empty_when_the_feature_is_off(): void
    {
        [$r, $staff] = $this->storeFixture();
        $this->combo($r);
        config(['settings.admin_allow_combos' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.combos', ['store' => $r->id]))
            ->assertOk()
            ->assertExactJson(['combos' => []]);
    }

    public function test_the_register_prices_a_combo_line_server_side(): void
    {
        [$r, $staff] = $this->storeFixture();
        $c = $this->combo($r);

        // The register sends only combo_id + picks; there is no price field.
        $res = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'combos' => [[
                    'combo_id' => $c['combo']->id, 'quantity' => 1,
                    'picks' => [
                        ['slot_id' => $c['main']->id, 'option_ids' => [$c['steak']->id]],  // +3.50
                        ['slot_id' => $c['drink']->id, 'option_ids' => [$c['cola']->id]],   // +0.75
                    ],
                ]],
            ])
            ->assertStatus(201);

        $order = Order::find($res->json('id'));
        $line = OrderItem::where('order_id', $order->id)->whereNotNull('combo_id')->first();
        $this->assertNotNull($line, 'A combo line must be persisted.');
        $this->assertSame((int) $c['combo']->id, (int) $line->combo_id);
        $this->assertNull($line->item_id, 'A combo line carries no item_id.');
        // 15.00 bundle + 3.50 (steak) + 0.75 (cola) = 19.25, derived by the server.
        $this->assertEqualsWithDelta(19.25, (float) $order->total, 0.001);
    }

    public function test_a_pos_order_may_mix_a_combo_and_a_normal_item(): void
    {
        [$r, $staff] = $this->storeFixture();
        $c = $this->combo($r);
        $cat = MenuCategory::where('store_id', $r->id)->first();
        $side = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Fries', 'price' => 5.00, 'active' => 1]);

        $res = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway',
                'items' => [['item_id' => $side->id, 'quantity' => 1]],
                'combos' => [[
                    'combo_id' => $c['combo']->id, 'quantity' => 1,
                    'picks' => [
                        ['slot_id' => $c['main']->id, 'option_ids' => [$c['burger']->id]], // +0
                        ['slot_id' => $c['drink']->id, 'option_ids' => [$c['water']->id]], // +0
                    ],
                ]],
            ])
            ->assertStatus(201);

        $order = Order::find($res->json('id'));
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
        // combo 15.00 + fries 5.00 = 20.00
        $this->assertEqualsWithDelta(20.00, (float) $order->total, 0.001);
    }
}
