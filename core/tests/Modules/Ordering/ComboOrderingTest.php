<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Services\Combos\ComboService;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T6 — combos through the order path, end to end.
 *
 * The assertion this file exists for is the FIRST one: what the configurator
 * quotes is exactly what `Order::getTotalAttribute()` charges. A combo that
 * quotes one price and charges another is the same bug class as the receipt that
 * did not add up (T1) and the four themes that quoted totals the server never
 * charged (theme-rescue) — so it is pinned here rather than assumed.
 *
 * Everything else is the guard rail around it: slot rules enforced on the
 * SERVER, an unavailable component refused by name, tax computed on the bare
 * pre-discount subtotal, and the POS path pricing a combo identically to the
 * storefront path.
 */
class ComboOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        Mail::fake();
        Notification::fake();
        Queue::fake();

        config(['settings.admin_allow_combos' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        /* Storefront templates extend a layout that reads $settings from a
           view composer ViewServiceProvider only registers when APP_INSTALLED is
           set — phpunit.xml deliberately leaves it unset. Same stub
           TableVerificationTest uses. */
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
            EnsureStaffDashboardPermission::class,
        ]);
    }

    // --- fixtures --------------------------------------------------------

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        FeatureAccess::flush();

        return $store;
    }

    private function item(Store $store, string $name, float $price): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => $name, 'price' => $price, 'active' => 1,
        ]);
    }

    /**
     * "Family Meal" — 15.00 bundle, two slots:
     *   Main   (pick 1): Burger [+0]        · Steak [+3.50]
     *   Drink  (pick 1): Water  [+0]        · Cola  [+0.75]
     * Bought separately the cheapest picks cost 10 + 2 = 12.
     */
    private function combo(Store $store, float $price = 15.00): array
    {
        $burger = $this->item($store, 'Burger', 10.00);
        $steak = $this->item($store, 'Steak', 22.00);
        $water = $this->item($store, 'Water', 2.00);
        $cola = $this->item($store, 'Cola', 3.00);

        $combo = Combo::create([
            'store_id' => $store->id, 'name' => 'Family Meal', 'description' => 'Feeds two',
            'price' => $price, 'active' => true, 'position' => 0,
        ]);

        $main = ComboSlot::create([
            'combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Choose your main',
            'min_select' => 1, 'max_select' => 1, 'position' => 0,
        ]);
        $drink = ComboSlot::create([
            'combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Choose your drink',
            'min_select' => 1, 'max_select' => 1, 'position' => 1,
        ]);

        $mk = fn (ComboSlot $slot, Menu $menu, float $up, int $pos) => ComboSlotOption::create([
            'combo_slot_id' => $slot->id, 'combo_id' => $combo->id, 'store_id' => $store->id,
            'menu_id' => $menu->id, 'upcharge' => $up, 'active' => true, 'position' => $pos,
        ]);

        return [
            'combo' => $combo,
            'main' => $main,
            'drink' => $drink,
            'burger' => $mk($main, $burger, 0, 0),
            'steak' => $mk($main, $steak, 3.50, 1),
            'water' => $mk($drink, $water, 0, 0),
            'cola' => $mk($drink, $cola, 0.75, 1),
            'menus' => compact('burger', 'steak', 'water', 'cola'),
        ];
    }

    /** The wire shape the storefront partial / API / POS all post. */
    private function picks(array $c, string $mainKey = 'burger', string $drinkKey = 'water', int $qty = 1): array
    {
        return [[
            'combo_id' => $c['combo']->id,
            'quantity' => $qty,
            'picks' => [
                ['slot_id' => $c['main']->id,  'option_ids' => [$c[$mainKey]->id]],
                ['slot_id' => $c['drink']->id, 'option_ids' => [$c[$drinkKey]->id]],
            ],
        ]];
    }

    private function submitOrder(Store $store, array $extra = []): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $store), array_merge([
            'name' => 'Test Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '12345678',
            'pay_via' => 'cash',
        ], $extra));
    }

    // =====================================================================
    // THE assertion: quoted == charged
    // =====================================================================

    public function test_the_quoted_combo_price_is_exactly_what_the_order_totals_to(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        // What the configurator quotes: bundle 15.00 + steak 3.50 + cola 0.75.
        $resolved = ComboService::resolve((int) $store->id, $this->picks($c, 'steak', 'cola', 2));
        $this->assertSame(19.25, $resolved[0]['unit_price']);

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c, 'steak', 'cola', 2))])
            ->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();

        // …and what the ONE price authority charges. No tax/delivery configured,
        // so total is the pure combo subtotal: 19.25 × 2.
        $this->assertSame(38.50, round((float) $order->total, 2));

        // One line, no menu row, priced entirely from the snapshot.
        $line = $order->items->first();
        $this->assertNull($line->item_id);
        $this->assertSame((int) $c['combo']->id, (int) $line->combo_id);
        $this->assertTrue($line->isCombo());
        $this->assertSame('Family Meal', $line->displayName());
        $this->assertSame(19.25, round($line->snapshotUnitPrice(), 2));
    }

    public function test_editing_the_combo_price_afterwards_does_not_rewrite_a_placed_order(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c))])->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame(15.00, round((float) $order->total, 2));

        // The whole reason for riding the snapshot: yesterday's receipt must not
        // move when the owner re-prices today.
        $c['combo']->update(['price' => 99.00]);
        $c['cola']->update(['upcharge' => 40.00]);

        $this->assertSame(15.00, round((float) Order::find($order->id)->total, 2));
    }

    public function test_a_combo_line_is_priced_alongside_normal_items_extras_and_options(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        $side = $this->item($store, 'Fries', 4.25);

        $this->submitOrder($store, [
            'items' => json_encode([['id' => $side->id, 'quantity' => 2, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'combos' => json_encode($this->picks($c, 'burger', 'cola')),
        ])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        // 4.25×2 (item) + 15.00 + 0.75 (combo) = 24.25
        $this->assertSame(24.25, round((float) $order->total, 2));
        $this->assertSame(2, $order->items->count());
    }

    // =====================================================================
    // Tax / discount ordering — the contract T6 must not disturb
    // =====================================================================

    public function test_tax_is_charged_on_the_combo_subtotal_after_the_discount(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '10'); // 10 %
        $c = $this->combo($store);

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c, 'burger', 'cola'))])
            ->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $subtotal = 15.75;
        $this->assertSame(round($subtotal * 0.10, 2), Order::computeTax($subtotal, store_options($store->id)));
        $this->assertSame(round($subtotal * 1.10, 2), round((float) $order->total, 2));

        // P6-271: the discount lowers the taxed base — 15.75 − 5.00 = 10.75, tax 1.08
        $order->discount_amount = 5.00;
        $order->save();
        $this->assertSame(11.83, round((float) Order::find($order->id)->total, 2));
    }

    public function test_wallet_and_gift_card_lower_what_is_due_not_the_combo_order_value(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c))])->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail();

        $order->wallet_applied = 4.00;
        $order->gift_card_applied = 1.00;
        $order->save();
        $order = Order::find($order->id);

        // Tenders, not discounts: the order is still worth 15.00.
        $this->assertSame(15.00, round((float) $order->total, 2));
        $this->assertSame(10.00, round((float) $order->amount_due, 2));
    }

    // =====================================================================
    // Server-side slot rules
    // =====================================================================

    public function test_a_missing_required_slot_is_refused_server_side(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        $bad = [[
            'combo_id' => $c['combo']->id, 'quantity' => 1,
            'picks' => [['slot_id' => $c['main']->id, 'option_ids' => [$c['burger']->id]]], // no drink
        ]];

        $this->submitOrder($store, ['combos' => json_encode($bad)])->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'A rejected combo must not leave a half-priced order behind.');
    }

    public function test_too_many_picks_for_a_slot_are_refused(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        $bad = [[
            'combo_id' => $c['combo']->id, 'quantity' => 1,
            'picks' => [
                ['slot_id' => $c['main']->id, 'option_ids' => [$c['burger']->id, $c['steak']->id]], // pick-1 slot
                ['slot_id' => $c['drink']->id, 'option_ids' => [$c['water']->id]],
            ],
        ]];

        $this->expectException(ValidationException::class);
        ComboService::resolve((int) $store->id, $bad);
    }

    public function test_a_repeated_slot_in_the_payload_cannot_smuggle_a_second_free_pick(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        // Two entries for the SAME pick-1 slot. Merging (not overwriting) is what
        // makes the max check see both.
        $bad = [[
            'combo_id' => $c['combo']->id, 'quantity' => 1,
            'picks' => [
                ['slot_id' => $c['main']->id, 'option_ids' => [$c['burger']->id]],
                ['slot_id' => $c['main']->id, 'option_ids' => [$c['steak']->id]],
                ['slot_id' => $c['drink']->id, 'option_ids' => [$c['water']->id]],
            ],
        ]];

        $this->expectException(ValidationException::class);
        ComboService::resolve((int) $store->id, $bad);
    }

    public function test_a_duplicate_pick_inside_one_slot_is_refused(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        // Widen the slot so the duplicate is what fails, not the max rule.
        $c['drink']->update(['min_select' => 2, 'max_select' => 2]);

        $bad = [[
            'combo_id' => $c['combo']->id, 'quantity' => 1,
            'picks' => [
                ['slot_id' => $c['main']->id, 'option_ids' => [$c['burger']->id]],
                ['slot_id' => $c['drink']->id, 'option_ids' => [$c['cola']->id, $c['cola']->id]],
            ],
        ]];

        $this->expectException(ValidationException::class);
        ComboService::resolve((int) $store->id, $bad);
    }

    public function test_an_option_from_another_combo_is_refused(): void
    {
        $store = $this->store();
        $a = $this->combo($store);
        $b = $this->combo($store);

        $bad = [[
            'combo_id' => $a['combo']->id, 'quantity' => 1,
            'picks' => [
                ['slot_id' => $a['main']->id,  'option_ids' => [$b['burger']->id]], // wrong combo's option
                ['slot_id' => $a['drink']->id, 'option_ids' => [$a['water']->id]],
            ],
        ]];

        $this->expectException(ValidationException::class);
        ComboService::resolve((int) $store->id, $bad);
    }

    public function test_another_stores_combo_cannot_be_ordered(): void
    {
        $mine = $this->store();
        $theirs = $this->store();
        $c = $this->combo($theirs);

        $this->expectException(ValidationException::class);
        ComboService::resolve((int) $mine->id, $this->picks($c));
    }

    public function test_a_multi_pick_slot_enforces_its_range_and_prices_every_pick(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        $c['drink']->update(['min_select' => 2, 'max_select' => 2]);

        $line = [[
            'combo_id' => $c['combo']->id, 'quantity' => 1,
            'picks' => [
                ['slot_id' => $c['main']->id,  'option_ids' => [$c['burger']->id]],
                ['slot_id' => $c['drink']->id, 'option_ids' => [$c['water']->id, $c['cola']->id]],
            ],
        ]];

        // 15.00 + 0 (water) + 0.75 (cola)
        $this->assertSame(15.75, ComboService::resolve((int) $store->id, $line)[0]['unit_price']);
    }

    // =====================================================================
    // Unavailable components
    // =====================================================================

    public function test_a_sold_out_component_is_refused_by_name(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        $c['menus']['cola']->update(['is_sold_out' => 1]);

        try {
            ComboService::resolve((int) $store->id, $this->picks($c, 'burger', 'cola'));
            $this->fail('A sold-out component must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Cola', implode(' ', $e->validator->errors()->all()));
        }
    }

    public function test_a_component_outside_its_availability_window_is_refused(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');
        $c = $this->combo($store);
        // A window that cannot contain "now" whatever the clock says.
        $from = now('UTC')->addHours(2)->format('H:i');
        $to = now('UTC')->addHours(3)->format('H:i');
        $c['menus']['water']->update(['available_from' => $from, 'available_to' => $to]);

        $this->expectException(ValidationException::class);
        ComboService::resolve((int) $store->id, $this->picks($c, 'burger', 'water'));
    }

    public function test_a_combo_whose_required_slot_has_nothing_left_disappears_from_the_storefront(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        $this->assertCount(1, ComboService::sellable((int) $store->id));

        $c['menus']['water']->update(['is_sold_out' => 1]);
        $c['menus']['cola']->update(['is_sold_out' => 1]);

        $this->assertCount(0, ComboService::sellable((int) $store->id),
            'A combo whose required slot cannot be satisfied is a dead end, not an offer.');
    }

    public function test_an_inactive_combo_is_neither_listed_nor_orderable(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        $c['combo']->update(['active' => false]);

        $this->assertCount(0, ComboService::sellable((int) $store->id));
        $this->expectException(ValidationException::class);
        ComboService::resolve((int) $store->id, $this->picks($c));
    }

    // =====================================================================
    // Downstream: kitchen, inventory, receipt
    // =====================================================================

    public function test_the_kitchen_ticket_lists_the_components_not_the_bundle_name_alone(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c, 'steak', 'cola'))])
            ->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail();

        $kot = new EscPosRenderer(48)->renderKot($order);
        $this->assertStringContainsString('Family Meal', $kot);
        $this->assertStringContainsString('Steak', $kot);
        $this->assertStringContainsString('Cola', $kot);
        // The bundle group repeats the line name and carries only a price — a cook
        // does not need it printed twice.
        $this->assertSame(1, substr_count($kot, 'Family Meal'));
    }

    public function test_the_receipt_subtotal_reconciles_with_the_order_total(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '10');
        $c = $this->combo($store);

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c, 'steak', 'cola'))])
            ->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail();

        $receipt = new EscPosRenderer(48)->render($order);

        // Subtotal 19.25, tax 1.93, total 21.18 — the printed Total must be the
        // accessor's number, and the printed subtotal must add up to it.
        $this->assertStringContainsString('19.25', $receipt);
        $this->assertStringContainsString(number_format((float) $order->total, 2), $receipt);
        $this->assertStringContainsString('Family Meal', $receipt);
        $this->assertStringContainsString('Steak', $receipt);
    }

    public function test_stock_is_deducted_per_component_not_once_for_the_bundle(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        foreach (['burger', 'cola'] as $k) {
            $c['menus'][$k]->update(['track_inventory' => 1, 'stock_qty' => 10]);
        }

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c, 'burger', 'cola', 3))])
            ->assertJson(['success' => true]);

        $this->assertSame(7, (int) Menu::find($c['menus']['burger']->id)->stock_qty);
        $this->assertSame(7, (int) Menu::find($c['menus']['cola']->id)->stock_qty);
        // The un-picked component is untouched.
        $this->assertNull(Menu::find($c['menus']['steak']->id)->stock_qty);
    }

    public function test_resuming_a_held_combo_deducts_its_component_stock(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        foreach (['burger', 'cola'] as $k) {
            $c['menus'][$k]->update(['track_inventory' => 1, 'stock_qty' => 10]);
        }

        $order = Order::create([
            'store_id' => $store->id, 'status' => 'pending', 'type' => 'takeaway',
            'customer_name' => 'C', 'phone_number' => '1', 'held_at' => now(), 'created_at' => now(),
        ]);
        // Held → deduction deferred (persist skips deductComponents).
        ComboService::persist($order, ComboService::resolve((int) $store->id, $this->picks($c, 'burger', 'cola', 3)), true);
        $this->assertSame(10, (int) Menu::find($c['menus']['burger']->id)->stock_qty, 'held: not deducted yet');

        PosService::resume($order->fresh());

        // Resuming must deduct the combo's components — it was a silent no-op before.
        $this->assertSame(7, (int) Menu::find($c['menus']['burger']->id)->stock_qty);
        $this->assertSame(7, (int) Menu::find($c['menus']['cola']->id)->stock_qty);
    }

    public function test_a_combos_only_order_still_shows_on_the_owners_orders_screen(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c))])->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail();

        // ManagesOrders drops any order that prices to 0 (its "menu rows deleted"
        // liveness check). A combo line has no menu row by design, so this is the
        // regression that would silently hide every combos-only order.
        $this->assertGreaterThan(0, $order->items->first()->snapshotUnitPrice());
        $this->assertSame(15.00, round((float) $order->total, 2));
    }

    // =====================================================================
    // POS / API parity
    // =====================================================================

    public function test_the_api_path_prices_a_combo_identically_to_the_storefront(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        $viaApi = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway',
            'source' => 'pos',
            'combos' => $this->picks($c, 'steak', 'cola'),
        ]);

        $this->submitOrder($store, ['combos' => json_encode($this->picks($c, 'steak', 'cola'))])
            ->assertJson(['success' => true]);
        $viaStorefront = Order::where('store_id', $store->id)->where('id', '!=', $viaApi->id)->firstOrFail();

        $this->assertSame(
            round((float) $viaApi->total, 3),
            round((float) $viaStorefront->total, 3),
            'The POS/API path and the storefront path must charge the same combo the same money.'
        );
    }

    public function test_an_order_may_be_combos_only(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway',
            'combos' => $this->picks($c),
        ]);

        $this->assertSame(1, $order->items()->count());
        $this->assertSame(15.00, round((float) $order->total, 2));
    }

    public function test_an_order_with_neither_items_nor_combos_is_still_refused(): void
    {
        $store = $this->store();

        $this->expectException(ValidationException::class);
        OrderApiService::create((int) $store->user_id, (int) $store->id, ['type' => 'takeaway']);
    }

    public function test_replacing_an_orders_items_restores_each_combo_components_stock(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        $c['menus']['burger']->update(['track_inventory' => 1, 'stock_qty' => 10]);
        $c['menus']['water']->update(['track_inventory' => 1, 'stock_qty' => 10]);
        $plain = $this->item($store, 'Soup', 6.00);

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'combos' => $this->picks($c, 'burger', 'water', 2),
        ]);
        $this->assertSame(8, (int) Menu::find($c['menus']['burger']->id)->stock_qty);

        OrderApiService::replaceItems($order, [['item_id' => $plain->id, 'quantity' => 1]]);

        // Both components come back — the snapshot is where they were recorded.
        $this->assertSame(10, (int) Menu::find($c['menus']['burger']->id)->stock_qty);
        $this->assertSame(10, (int) Menu::find($c['menus']['water']->id)->stock_qty);
        $this->assertSame(6.00, round((float) Order::find($order->id)->total, 2));
    }

    public function test_a_variant_component_is_recorded_and_never_double_priced(): void
    {
        $store = $this->store();
        $c = $this->combo($store);
        // menu_variants has no `name` column — a variant is its option keys + price.
        $large = MenuVariant::create([
            'menu_id' => $c['menus']['cola']->id, 'price' => 99.00, 'position' => 0, 'options' => json_encode([]),
        ]);
        $c['cola']->update(['variant_id' => $large->id]);

        $resolved = ComboService::resolve((int) $store->id, $this->picks($c, 'burger', 'cola'));

        // The variant is the component's identity, not its price: the line is
        // still bundle 15.00 + the cola option's own 0.75 upcharge.
        $this->assertSame(15.75, $resolved[0]['unit_price']);
        $this->assertSame((int) $large->id, $resolved[0]['components'][1]['variant_id']);
    }

    public function test_the_minimum_order_value_counts_combos(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'min_order_value', '12');
        $c = $this->combo($store);

        // 15.00 clears a 12.00 minimum — before combos counted, a combos-only
        // basket scored 0 and was refused.
        $this->submitOrder($store, ['combos' => json_encode($this->picks($c))])->assertJson(['success' => true]);
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_the_saving_badge_never_overstates_what_the_diner_saves(): void
    {
        $store = $this->store();
        $c = $this->combo($store);

        // Cheapest picks à la carte: burger 10.00 + water 2.00 = 12.00; bundle 15.00.
        $payload = ComboService::payload($c['combo']->fresh()->load('slots.options.menu', 'slots.options.variant'));
        $this->assertSame(12.00, $payload['ala_carte']);
        $this->assertSame(0.0, $payload['saving'], 'A bundle dearer than its cheapest picks must claim no saving.');

        $c['combo']->update(['price' => 9.00]);
        $payload = ComboService::payload($c['combo']->fresh()->load('slots.options.menu', 'slots.options.variant'));
        $this->assertSame(3.00, $payload['saving']);
    }

    public function test_the_storefront_renders_the_combo_partial_for_a_store_that_has_one(): void
    {
        $store = $this->store();
        $this->combo($store);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('mb-combos-field', $html, 'The forwarded combos field must be on the page.');
        $this->assertStringContainsString('Family Meal', $html);
    }

    public function test_the_storefront_configurator_renders_under_the_arabic_rtl_locale(): void
    {
        // phpunit.xml forces DEFAULT_LANGUAGE=ar. The partial is laid out with
        // logical CSS properties (inset-inline-start, margin-inline-start) so the
        // sheet mirrors instead of hanging off the wrong edge rather than needing
        // an `[dir=rtl]` stylesheet fork per theme.
        //
        // The page itself is rendered under the default locale: switching the app
        // locale in a test hits an unrelated pre-existing limitation (the themes'
        // layout dereferences current_language()->direction, and the test database
        // has no `languages` rows), so the Arabic half is asserted against the
        // lang files instead.
        $store = $this->store();
        $this->combo($store);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('mb-combos-field', $html);
        // Logical properties only — no physical left/right on the sheet or the FAB.
        $this->assertStringContainsString('inset-inline-start', $html);
        $this->assertStringContainsString('margin-inline-start', $html);
        $this->assertStringNotContainsString('.mb-combo-fab{position:fixed;left', $html);

        foreach (['your_combos', 'combos_subtotal', 'included', 'no_combos_added_yet'] as $key) {
            $ar = trans('lang.'.$key, [], 'ar');
            $this->assertNotSame('lang.'.$key, $ar, "Missing Arabic translation for [$key].");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar, "[$key] was left in English for ar.");
        }
    }

    public function test_the_storefront_renders_nothing_for_a_store_without_combos(): void
    {
        $store = $this->store();

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString('mb-combos-field', $html);
    }
}
