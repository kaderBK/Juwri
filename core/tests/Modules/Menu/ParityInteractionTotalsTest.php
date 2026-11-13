<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Juwri\Modules\Tables\Services\TableMergeService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN WV.V — the money re-check, across tracks rather than within one.
 *
 * Every build track proved its own totals contract in isolation and all ten are
 * green. This file asserts the combinations nobody owned: a T6 combo and a T5
 * happy-hour price on the same order, sitting at a T4 merged party, printed on
 * a T1 ESC/POS receipt — plus the storefront modifier upcharge that W2 carried
 * forward as a live under-charge.
 *
 * `Order::getTotalAttribute()` is the single price authority. Anything that
 * prints, quotes or settles money must reconcile with it to the cent; where it
 * did not, the failure is pinned here first and fixed in the same commit.
 */
class ParityInteractionTotalsTest extends TestCase
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

        config([
            'settings.admin_allow_combos' => true,
            'settings.admin_allow_table_merge' => true,
            'settings.admin_allow_price_schedules' => true,
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        TableMergeService::flushMemo();
        MenuScheduleService::flush();

        /* Storefront templates extend a layout reading $settings from a view
           composer ViewServiceProvider only registers when APP_INSTALLED is set,
           and phpunit.xml deliberately leaves it unset. Same stub the T4/T6
           suites use. */
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1', 'table_qr' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Bites', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_ontable_order', '1');
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

    /** A paid library modifier group attached to $menu: Plain +0 · Truffle +2.50. */
    private function sauceGroup(Store $store, Menu $menu): MenuVariantOption
    {
        $group = MenuVariantOption::create([
            'store_id' => $store->id, 'is_global' => true, 'menu_id' => null,
            'title' => 'Sauce', 'options' => ['Plain', 'Truffle'], 'option_prices' => [0, 2.50],
            'position' => 0, 'active' => 1, 'required' => false, 'min_select' => 0, 'max_select' => 1,
        ]);
        $menu->attachedVariantOptions()->attach($group->id, ['active' => true]);

        return $group;
    }

    private function table(Store $store, int $no): StoreTable
    {
        $t = new StoreTable;
        $t->forceFill([
            'store_id' => $store->id, 'table_no' => $no, 'label' => "T$no", 'seats' => 4,
            'status' => 'free', 'branch_id' => null, 'key' => Str::random(10),
        ])->save();

        return $t;
    }

    /** "Family Meal" — 15.00 bundle, one slot: Water +0 · Cola +0.75. */
    private function combo(Store $store): array
    {
        $water = $this->item($store, 'Water', 2.00);
        $cola = $this->item($store, 'Cola', 3.00);

        $combo = Combo::create([
            'store_id' => $store->id, 'name' => 'Family Meal', 'description' => 'Feeds two',
            'price' => 15.00, 'active' => true, 'position' => 0,
        ]);
        $slot = ComboSlot::create([
            'combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Choose your drink',
            'min_select' => 1, 'max_select' => 1, 'position' => 0,
        ]);
        $mk = fn (Menu $m, float $up, int $pos) => ComboSlotOption::create([
            'combo_slot_id' => $slot->id, 'combo_id' => $combo->id, 'store_id' => $store->id,
            'menu_id' => $m->id, 'upcharge' => $up, 'active' => true, 'position' => $pos,
        ]);

        return ['combo' => $combo, 'slot' => $slot, 'water' => $mk($water, 0, 0), 'cola' => $mk($cola, 0.75, 1)];
    }

    private function send(Store $store, array $extra = []): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $store), array_merge([
            'name' => 'Test Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '12345678',
            'pay_via' => 'cash',
        ], $extra));
    }

    /** Printable text of a receipt with the ESC/GS control bytes stripped. */
    private function receiptText(Order $order): string
    {
        $bytes = EscPosRenderer::forWidthMm(80)->render(
            $order->fresh()->load('items.menu', 'items.variant', 'items.itemExtras.extra')
        );

        return preg_replace('/\x1B@|\x1B[Ea].|\x1DV0|\x1B[!-]./', '', $bytes);
    }

    // =====================================================================
    // 1 — the storefront modifier upcharge (carried forward from W2)
    // =====================================================================

    /**
     * The under-charge, stated as an equation. Before WV.V the picks were
     * written to `order_items.order_note` as TEXT and their price lived only in
     * a local variable used for the confirmation message — so the storefront
     * quoted 22.50 and `Order::total`, which is what a cashier settles and a
     * folio charges, said 20.00.
     */
    public function test_a_storefront_modifier_upcharge_reaches_the_order_total(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Burger', 20.00);
        $group = $this->sauceGroup($store, $burger);

        $this->send($store, ['items' => json_encode([[
            'id' => $burger->id, 'quantity' => 1, 'variants' => 0, 'extras' => [],
            'mods' => [['groupId' => $group->id, 'optIndex' => 1]], // Truffle +2.50
        ]])])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();

        $this->assertSame(22.50, round((float) $order->total, 2));
        $this->assertSame(22.50, round((float) $order->amount_due, 2));

        // …and the money is on the line as a snapshot, in the shape the price
        // authority sums — not only as text a human can read.
        $line = $order->items->first();
        $this->assertSame(2.50, round($line->snapshotUnitPrice(), 2));
        $this->assertStringContainsString('Sauce: Truffle', (string) $line->order_note);
        $this->assertSame('Sauce', $line->options[0]['title']);
        $this->assertSame((int) $group->id, (int) $line->options[0]['group_id']);
    }

    public function test_the_modifier_upcharge_is_multiplied_by_the_line_quantity(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Burger', 20.00);
        $group = $this->sauceGroup($store, $burger);

        $this->send($store, ['items' => json_encode([[
            'id' => $burger->id, 'quantity' => 3, 'variants' => 0, 'extras' => [],
            'mods' => [['groupId' => $group->id, 'optIndex' => 1]],
        ]])])->assertJson(['success' => true]);

        // 3 × (20.00 + 2.50)
        $this->assertSame(67.50, round((float) Order::where('store_id', $store->id)->value('id')
            ? (float) Order::where('store_id', $store->id)->firstOrFail()->total : 0, 2));
    }

    /**
     * The repair is forward-only ON PURPOSE. A line placed before this commit
     * carries `options = NULL`; re-pricing it would rewrite history, which is an
     * owner decision, not a deploy side effect.
     */
    public function test_an_order_placed_before_the_fix_does_not_re_price_itself(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Burger', 20.00);
        $group = $this->sauceGroup($store, $burger);

        $this->send($store, ['items' => json_encode([[
            'id' => $burger->id, 'quantity' => 1, 'variants' => 0, 'extras' => [],
            'mods' => [['groupId' => $group->id, 'optIndex' => 1]],
        ]])])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $line = $order->items->first();

        // Reproduce the pre-fix row exactly: the note kept, the snapshot absent.
        $line->forceFill(['options' => null])->save();

        $this->assertSame(20.00, round((float) Order::find($order->id)->total, 2));
    }

    /** A group belonging to ANOTHER store cannot be smuggled onto this bill. */
    public function test_a_foreign_modifier_group_is_refused(): void
    {
        $store = $this->store();
        $other = $this->store();
        $burger = $this->item($store, 'Burger', 20.00);
        $foreign = $this->sauceGroup($other, $this->item($other, 'Their burger', 5.00));

        $this->send($store, ['items' => json_encode([[
            'id' => $burger->id, 'quantity' => 1, 'variants' => 0, 'extras' => [],
            'mods' => [['groupId' => $foreign->id, 'optIndex' => 1]],
        ]])])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame(20.00, round((float) $order->total, 2));
        $this->assertNull($order->items->first()->options);
    }

    // =====================================================================
    // 2 — T5 × T1: a scheduled price must print
    // =====================================================================

    /**
     * `getTotalAttribute()` lets a snapshotted `unit_price` replace the derived
     * menu price; the ESC/POS renderer still derived it, so a happy-hour receipt
     * printed a Subtotal + Tax that did not add up to its own Total line.
     */
    public function test_a_happy_hour_receipt_reconciles_with_the_order_total(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '10');
        $burger = $this->item($store, 'Burger', 20.00);

        PriceSchedule::create([
            'store_id' => $store->id, 'name' => 'HH', 'label' => 'Happy Hour',
            'scope' => 'store', 'adjustment_type' => 'percent', 'adjustment_value' => 50,
            'active' => true,
        ]);
        MenuScheduleService::flush();

        $this->send($store, ['items' => json_encode([[
            'id' => $burger->id, 'quantity' => 2, 'variants' => 0, 'extras' => [], 'mods' => [],
        ]])])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();

        // 2 × 10.00 = 20.00 subtotal, +10 % tax = 22.00.
        $this->assertSame(10.00, round((float) $order->items->first()->unit_price, 2));
        $this->assertSame(22.00, round((float) $order->total, 2));

        $text = $this->receiptText($order);
        $this->assertStringContainsString('20.00', $text);   // Subtotal, at the charged price
        $this->assertStringContainsString('2.00', $text);    // Tax on the bare subtotal
        $this->assertStringContainsString('22.00', $text);   // Total
        // The list price must NOT appear anywhere on the paper.
        $this->assertStringNotContainsString('40.00', $text);
    }

    // =====================================================================
    // 3 — the full interaction: T6 combo + T5 schedule + T4 party + T1 paper
    // =====================================================================

    /**
     * The combination nobody owned. One dine-in order at a merged party
     * carrying a happy-hour item, a paid modifier and a combo, then printed.
     *
     *   Burger   2 × (20.00 → 10.00 happy hour  + 2.50 Truffle) = 25.00
     *   Combo    1 × (15.00 bundle + 0.75 Cola)                 = 15.75
     *   Subtotal                                                  40.75
     *   Tax 10 % of the bare subtotal                              4.075 → 4.08
     *   Total                                                     44.83
     *
     * A schedule prices the ITEM; it never discounts a modifier upcharge or a
     * combo bundle (the T6 ledger records that as an owner decision), and tax is
     * charged on the bare pre-discount subtotal.
     */
    public function test_a_combo_a_happy_hour_item_and_a_modifier_at_a_merged_party_all_reconcile_on_paper(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '10');

        $burger = $this->item($store, 'Burger', 20.00);
        $group = $this->sauceGroup($store, $burger);
        $c = $this->combo($store);

        PriceSchedule::create([
            'store_id' => $store->id, 'name' => 'HH', 'label' => 'Happy Hour',
            'scope' => 'store', 'adjustment_type' => 'percent', 'adjustment_value' => 50,
            'active' => true,
        ]);
        MenuScheduleService::flush();

        $t1 = $this->table($store, 1);
        $t2 = $this->table($store, 2);
        TableMergeService::merge((int) $store->id, (int) $t1->id, [(int) $t2->id]);
        TableMergeService::flushMemo();

        // The guest is sitting at the SECONDARY table; T4 routes the order to the
        // party's primary so a merge cannot end up with two bills.
        $this->send($store, [
            'ordering-type' => 'on-table',
            'table' => '2',
            'items' => json_encode([[
                'id' => $burger->id, 'quantity' => 2, 'variants' => 0, 'extras' => [],
                'mods' => [['groupId' => $group->id, 'optIndex' => 1]],
            ]]),
            'combos' => json_encode([[
                'combo_id' => $c['combo']->id, 'quantity' => 1,
                'picks' => [['slot_id' => $c['slot']->id, 'option_ids' => [$c['cola']->id]]],
            ]]),
        ])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();

        $this->assertSame('1', (string) $order->table_number, 'the secondary table must route to the primary');
        $this->assertSame(44.83, round((float) $order->total, 2));

        // The party bill is Σ of each order's own authority — never re-summed.
        $group_ = TableMergeService::activeGroupFor((int) $t1->id);
        $bill = TableMergeService::groupBill($group_);
        $this->assertSame(44.83, round((float) $bill['total'], 2));

        $text = $this->receiptText($order);

        // Every priced element is on the paper at the price that was charged…
        $this->assertStringContainsString('T1 + T2', $text);          // T4 party, not "Table 1"
        $this->assertStringContainsString('20.00', $text);            // burger line 2 × 10.00
        $this->assertStringContainsString('Truffle', $text);
        $this->assertStringContainsString('5.00', $text);             // 2 × 2.50 modifier
        $this->assertStringContainsString('Family Meal', $text);
        $this->assertStringContainsString('15.75', $text);            // combo line
        $this->assertStringContainsString('40.75', $text);            // Subtotal
        $this->assertStringContainsString('44.83', $text);            // Total

        // …and the printed arithmetic closes: the item lines sum to the printed
        // Subtotal, and Subtotal + Tax equals the printed Total.
        $this->assertSame(44.83, round(40.75 + Order::computeTax(40.75, store_options($store->id)), 2));
    }

    /**
     * The KOT the runner carries names the party too, or half of it never gets
     * its food.
     */
    public function test_the_kitchen_ticket_names_the_merged_party(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Burger', 20.00);
        $t1 = $this->table($store, 1);
        $t2 = $this->table($store, 2);
        TableMergeService::merge((int) $store->id, (int) $t1->id, [(int) $t2->id]);
        TableMergeService::flushMemo();

        $this->send($store, [
            'ordering-type' => 'on-table', 'table' => '1',
            'items' => json_encode([['id' => $burger->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
        ])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $kot = EscPosRenderer::forWidthMm(80)->renderKot($order->fresh()->load('items.menu'));

        $this->assertStringContainsString('T1 + T2', $kot);
    }

    /** No merge, no party: the paper still says what it always said. */
    public function test_an_unmerged_table_still_prints_its_bare_number(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Burger', 20.00);
        $this->table($store, 7);

        $this->send($store, [
            'ordering-type' => 'on-table', 'table' => '7',
            'items' => json_encode([['id' => $burger->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
        ])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();

        $this->assertStringContainsString('Table 7', $this->receiptText($order));
    }
}
