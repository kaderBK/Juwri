<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
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
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Ordering\Jobs\SendStaffOrderPushJob;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-H15 — the storefront checkout folded into OrderApiService::create, shipped
 * dark behind the `storefront_order_pipeline` platform Option.
 *
 * The whole behavioural surface of H15 is server-side: the theme JS only
 * assembles the POST body, so an HTTP POST to route('store.submitOrder')
 * exercises the entire fold. These tests run the SAME payload through both flag
 * states and assert equivalence — the unified pipeline must produce byte-identical
 * money-bearing order rows and totals to the legacy inline path.
 */
class StorefrontUnifiedPipelineTest extends TestCase
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
        // H15: default legacy — the dark-ship contract. Each test opts in explicitly.
        config(['settings.storefront_order_pipeline' => 'legacy']);
        config(['settings.storefront_order_pipeline_stores' => '']);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

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

    /** A "Large" variant of $menu, priced 25 (replaces the base price). */
    private function largeVariant(Menu $menu): MenuVariant
    {
        $sizeOption = MenuVariantOption::create([
            'menu_id' => $menu->id, 'title' => 'Size', 'options' => ['Small', 'Large'],
            'position' => 0, 'active' => 1,
        ]);

        return MenuVariant::create([
            'menu_id' => $menu->id, 'price' => 25.00,
            'options' => [$sizeOption->id => 1], // key 1 = 'Large'
            'active' => 1, 'position' => 0,
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

    private function extra(Menu $menu, string $title, float $price): MenuExtra
    {
        return MenuExtra::create([
            'menu_id' => $menu->id, 'title' => $title, 'price' => $price, 'position' => 0, 'active' => 1,
        ]);
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

    private function comboPicks(array $c, int $qty = 1): array
    {
        return [[
            'combo_id' => $c['combo']->id, 'quantity' => $qty,
            'picks' => [['slot_id' => $c['slot']->id, 'option_ids' => [$c['cola']->id]]],
        ]];
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

    /** The full multi-shape cart the equivalence check exercises. */
    private function richCartPayload(Store $store): array
    {
        $burger = $this->item($store, 'Burger', 20.00);
        $variant = $this->largeVariant($burger);
        $group = $this->sauceGroup($store, $burger);
        $cheese = $this->extra($burger, 'Cheese', 2.00);
        $c = $this->combo($store);

        return [
            'items' => json_encode([[
                'id' => $burger->id, 'quantity' => 2, 'variants' => $variant->id,
                'extras' => [['id' => $cheese->id]],
                'mods' => [['groupId' => $group->id, 'optIndex' => 1]], // Truffle +2.50
            ]]),
            'combos' => json_encode($this->comboPicks($c)),
        ];
    }

    private function orderComparable(Order $order): array
    {
        $order = $order->fresh()->load(['items.itemExtras']);
        $items = $order->items->sortBy(fn ($i) => [$i->item_id, $i->combo_id])->values()->map(fn ($i) => [
            'item_id' => $i->item_id,
            'combo_id' => $i->combo_id,
            'quantity' => (int) $i->quantity,
            'variation' => (int) $i->variation,
            'options' => $i->options,
            'unit_price_snapshot' => round($i->snapshotUnitPrice(), 3),
            'extras' => $i->itemExtras->pluck('extra_id')->sort()->values()->all(),
        ])->all();

        return [
            'total' => round((float) $order->total, 3),
            'amount_due' => round((float) $order->amount_due, 3),
            'subtotal' => round((float) $order->subtotal, 3),
            'tax_total' => round((float) $order->tax_total, 3),
            'grand_total' => round((float) $order->grand_total, 3),
            'items' => $items,
        ];
    }

    // =====================================================================
    // Money correctness: the two pipelines are equivalent
    // =====================================================================

    public function test_unified_and_legacy_produce_identical_rows_and_totals(): void
    {
        // ONE store + ONE catalogue, so the two orders reference identical ids —
        // any difference is then a real pipeline delta, not a fresh-store id skew.
        $store = $this->store();
        $payload = $this->richCartPayload($store);

        config(['settings.storefront_order_pipeline' => 'legacy']);
        $this->send($store, $payload)->assertJson(['success' => true]);
        $legacyOrder = Order::where('store_id', $store->id)->firstOrFail();

        config(['settings.storefront_order_pipeline' => 'unified']);
        $this->send($store, $payload)->assertJson(['success' => true]);
        $unifiedOrder = Order::where('store_id', $store->id)
            ->where('id', '!=', $legacyOrder->id)->firstOrFail();

        // 20→25 (Large) ×2 + Cheese 2×2 + Truffle 2.50×2 + combo (15 + 0.75) = 74.75.
        $this->assertSame(74.75, round((float) $legacyOrder->total, 2));

        $this->assertEquals(
            $this->orderComparable($legacyOrder),
            $this->orderComparable($unifiedOrder),
            'The unified pipeline must produce byte-identical money-bearing rows + totals to legacy.'
        );
    }

    // =====================================================================
    // The named WI tests
    // =====================================================================

    public function test_unified_storefront_order_is_created_by_the_shared_service(): void
    {
        $store = $this->store();
        $payload = $this->richCartPayload($store);

        // The legacy figure for the identical payload, to compare against.
        config(['settings.storefront_order_pipeline' => 'legacy']);
        $legacyStore = $this->store();
        $legacyPayload = $this->richCartPayload($legacyStore);
        $this->send($legacyStore, $legacyPayload)->assertJson(['success' => true]);
        $legacyTotal = round((float) Order::where('store_id', $legacyStore->id)->firstOrFail()->total, 2);

        config(['settings.storefront_order_pipeline' => 'unified']);
        $this->send($store, $payload)->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail();

        $this->assertSame($legacyTotal, round((float) $order->total, 2));
        $this->assertNotNull($order->track_token);

        // The paid modifier is in the options snapshot (the shape the total sums).
        $burgerLine = $order->items->firstWhere('item_id', '!=', null);
        $this->assertNotNull($burgerLine);
        $this->assertSame('Sauce', $burgerLine->options[0]['title']);
        $this->assertSame('Truffle', $burgerLine->options[0]['selected'][0]['option']);

        // The assertion that FAILS before the fix: the storefront never called KDS,
        // so the row stayed 'pending'. create() derives 'new'.
        $this->assertSame('new', $order->status);
    }

    public function test_the_flag_defaults_to_legacy(): void
    {
        $store = $this->store();
        $payload = $this->richCartPayload($store);

        // No Option set (setUp leaves it 'legacy'); simulate a genuinely absent one.
        config(['settings.storefront_order_pipeline' => null]);

        $this->send($store, $payload)->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail();

        // Legacy leaves the row at its default 'pending' — NOT the KDS-derived 'new'.
        $this->assertNotSame('new', $order->status);
        $this->assertSame('pending', $order->status);
    }

    public function test_canary_store_list_opts_one_store_in(): void
    {
        $optedIn = $this->store();
        $optedOut = $this->store();

        // Platform stays on legacy; only $optedIn is canaried.
        config(['settings.storefront_order_pipeline' => 'legacy']);
        config(['settings.storefront_order_pipeline_stores' => (string) $optedIn->id]);

        $this->send($optedIn, $this->richCartPayload($optedIn))->assertJson(['success' => true]);
        $this->send($optedOut, $this->richCartPayload($optedOut))->assertJson(['success' => true]);

        $this->assertSame('new', Order::where('store_id', $optedIn->id)->firstOrFail()->status,
            'The canaried store must take the unified path (KDS-derived status).');
        $this->assertSame('pending', Order::where('store_id', $optedOut->id)->firstOrFail()->status,
            'A store not on the canary list stays on legacy.');
    }

    public function test_a_sold_out_line_is_skipped_not_rejected(): void
    {
        $store = $this->store();
        $keep = $this->item($store, 'Keep', 10.00);
        $soldOut = $this->item($store, 'SoldOut', 8.00);
        $soldOut->update(['is_sold_out' => 1]);

        config(['settings.storefront_order_pipeline' => 'unified']);
        $this->send($store, [
            'items' => json_encode([
                ['id' => $keep->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
                ['id' => $soldOut->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
            ]),
        ])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame(1, $order->items->count(), 'The sold-out line is skipped, not rejected.');
        $this->assertSame(10.00, round((float) $order->total, 2));
    }

    public function test_no_duplicate_staff_push(): void
    {
        $store = $this->store();
        config(['settings.storefront_order_pipeline' => 'unified']);

        $this->send($store, $this->richCartPayload($store))->assertJson(['success' => true]);

        // create()'s staff-PWA job is suppressed via side_effects; the storefront's
        // own Web Push job fires instead — exactly one staff alert, not two.
        Queue::assertNotPushed(SendStaffOrderPushJob::class);
        Queue::assertPushed(SendPushNotificationJob::class);
    }

    public function test_double_submit_still_resolves_to_one_order(): void
    {
        $store = $this->store();
        config(['settings.storefront_order_pipeline' => 'unified']);
        $payload = array_merge($this->richCartPayload($store), ['client_uuid' => '0190f3a2-7b1c-7def-8abc-0123456789ab']);
        // before J27-A2: 'fixed-uuid-123' — the key must now be a UUID (422 otherwise).

        $this->send($store, $payload)->assertJson(['success' => true]);
        $this->send($store, $payload)->assertJson(['success' => true, 'duplicate' => true]);

        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }
}
