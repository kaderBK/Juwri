<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use App\Services\Agents\AgentToolsService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\OrderApiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Models\PriceScheduleTarget;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T5 — time-based menus & pricing.
 *
 * The money assertions are the point of this file:
 *   • a scheduled price is resolved SERVER-side and snapshotted onto the line;
 *   • a historical order re-totals to what was charged after the schedule
 *     changes, ends or is deleted;
 *   • the totals contract is untouched — tax on the BARE item subtotal, before
 *     delivery/tray/tip/service charge and before the discount, and wallet /
 *     gift card remain tenders rather than discounts.
 */
class MenuSchedulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_menu_schedules' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        MenuScheduleService::flush();

        /* Storefront templates extend a layout whose $settings/$activeTheme come
           from a view composer ViewServiceProvider only registers when
           APP_INSTALLED is set — phpunit.xml deliberately leaves it unset, so a
           rendered template dies on "Undefined variable $settings". Same stub
           StorefrontRoomServiceOptionTest uses. */
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
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MenuScheduleService::flush();
        parent::tearDown();
    }

    /* ───────────────────────────── fixtures ──────────────────────────── */

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(?User $owner = null, ?string $tz = null): Store
    {
        $owner = $owner ?: $this->owner();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($tz) {
            StoreOption::updateStoreOption($store->id, 'timezone', $tz);
        }
        MenuScheduleService::flush();

        return $store;
    }

    private function item(Store $store, array $attrs = []): Menu
    {
        return Menu::create(array_merge([
            'store_id' => $store->id, 'name' => 'Mojito', 'price' => 10, 'active' => '1',
        ], $attrs));
    }

    private function schedule(Store $store, array $attrs = [], array $targets = []): PriceSchedule
    {
        $s = PriceSchedule::create(array_merge([
            'store_id' => $store->id, 'name' => 'HH', 'label' => 'Happy Hour',
            'scope' => 'store', 'adjustment_type' => 'percent', 'adjustment_value' => 20,
            'active' => true,
        ], $attrs));

        foreach ($targets as [$type, $id]) {
            PriceScheduleTarget::create(['schedule_id' => $s->id, 'target_type' => $type, 'target_id' => $id]);
        }
        MenuScheduleService::flush();

        return $s;
    }

    /* ──────────────────────── availability resolution ────────────────── */

    /**
     * The heart of the feature: a window is resolved on the STORE's wall clock.
     * 06:00 UTC is 09:00 in Riyadh — inside a 07:00–11:00 breakfast window — and
     * 22:00 in Auckland, which is not.
     */
    public function test_availability_resolves_on_the_store_timezone_not_the_server(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 06:00', 'UTC'));

        $riyadh = $this->store(null, 'Asia/Riyadh');
        $auckland = $this->store(null, 'Pacific/Auckland');

        $window = ['available_from' => '07:00', 'available_to' => '11:00'];
        $a = $this->item($riyadh, $window);
        $b = $this->item($auckland, $window);

        $this->assertSame('Asia/Riyadh', MenuScheduleService::timezoneFor((int) $riyadh->id));
        $this->assertTrue(MenuScheduleService::isAvailable($a), 'Riyadh is 09:00 — inside the window');
        $this->assertFalse(MenuScheduleService::isAvailable($b), 'Auckland is 18:00 — outside the window');
    }

    public function test_an_overnight_window_is_open_after_midnight_on_the_store_clock(): void
    {
        // 22:00 UTC = 01:00 next day in Riyadh — inside a 22:00→02:00 window.
        Carbon::setTestNow(Carbon::parse('2026-07-08 22:00', 'UTC'));
        $store = $this->store(null, 'Asia/Riyadh');
        $item = $this->item($store, ['available_from' => '22:00', 'available_to' => '02:00']);

        $this->assertTrue(MenuScheduleService::isAvailable($item));
    }

    /**
     * A DST transition must not shift a window. Europe/Berlin springs forward at
     * 01:00 UTC on 2026-03-29, so the SAME 10:00 UTC instant is 11:00 local the
     * day before and 12:00 local the day after. A 09:00–11:30 window covers the
     * first and not the second — which is the whole point of resolving on the
     * store's wall clock rather than a fixed offset.
     */
    public function test_a_window_follows_the_wall_clock_across_a_dst_transition(): void
    {
        $store = $this->store(null, 'Europe/Berlin');
        $item = $this->item($store, ['available_from' => '09:00', 'available_to' => '11:30']);

        Carbon::setTestNow(Carbon::parse('2026-03-28 10:00', 'UTC')); // 11:00 CET
        MenuScheduleService::flush();
        $this->assertTrue(MenuScheduleService::isAvailable($item));

        Carbon::setTestNow(Carbon::parse('2026-03-30 10:00', 'UTC')); // 12:00 CEST
        MenuScheduleService::flush();
        $this->assertFalse(MenuScheduleService::isAvailable($item));
    }

    public function test_a_closed_category_closes_its_items(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        $cat = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Breakfast',
            'available_from' => '07:00', 'available_to' => '11:00',
        ]);
        // The ITEM has no window of its own — only the category closes it.
        $item = $this->item($store, ['category_id' => $cat->id]);

        $this->assertTrue($item->isAvailableNow('UTC'), 'the item-level helper only sees the item');
        $this->assertFalse(MenuScheduleService::isAvailable($item), 'the resolver folds in the category');
    }

    public function test_a_category_in_a_subcategory_chain_still_gates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        $parent = MenuCategory::create(['store_id' => $store->id, 'name' => 'Breakfast']);
        $child = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Pastries', 'parent' => $parent->id,
            'available_from' => '07:00', 'available_to' => '11:00',
        ]);

        $this->assertFalse(MenuScheduleService::isAvailable($this->item($store, ['category_id' => $child->id])));
    }

    public function test_order_type_scope_narrows_availability(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store, ['available_order_types' => 'on-table']);

        $this->assertTrue(MenuScheduleService::isAvailable($item, null, 'on-table'));
        $this->assertFalse(MenuScheduleService::isAvailable($item, null, 'delivery'));
        // Browsing before a type is chosen must not hide it.
        $this->assertTrue(MenuScheduleService::isAvailable($item, null, null));
    }

    public function test_opens_at_reports_the_narrower_of_item_and_category(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 05:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        $cat = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Brunch',
            'available_from' => '09:00', 'available_to' => '12:00',
        ]);
        $item = $this->item($store, [
            'category_id' => $cat->id, 'available_from' => '07:00', 'available_to' => '14:00',
        ]);

        $this->assertSame('09:00', MenuScheduleService::opensAt($item));
    }

    /* ───────────────────────────── pricing ───────────────────────────── */

    public function test_a_live_schedule_discounts_the_price(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 20]);

        $quote = MenuScheduleService::quote($item);
        $this->assertSame(8.0, $quote['price']);
        $this->assertSame(10.0, $quote['base']);
        $this->assertSame('Happy Hour', $quote['label']);
    }

    public function test_a_schedule_outside_its_window_does_not_apply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);
        $this->schedule($store, ['starts_at' => '17:00', 'ends_at' => '19:00']);

        $this->assertSame(10.0, MenuScheduleService::quote($item)['price']);
        $this->assertNull(MenuScheduleService::quote($item)['schedule']);
    }

    /** LOCKED RULE: schedules never stack — the single best one wins. */
    public function test_schedules_do_not_stack_the_cheapest_wins(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);
        $this->schedule($store, ['name' => 'A', 'adjustment_type' => 'percent', 'adjustment_value' => 10]);
        $this->schedule($store, ['name' => 'B', 'label' => 'Half price', 'adjustment_type' => 'percent', 'adjustment_value' => 50]);

        $quote = MenuScheduleService::quote($item);
        // Stacked it would be 10 × 0.9 × 0.5 = 4.50. Best-single is 5.00.
        $this->assertSame(5.0, $quote['price']);
        $this->assertSame('Half price', $quote['label']);
    }

    public function test_item_and_category_scoped_schedules_only_touch_their_targets(): void
    {
        $store = $this->store(null, 'UTC');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Drinks']);
        $inCat = $this->item($store, ['category_id' => $cat->id]);
        $targeted = $this->item($store, ['name' => 'Wings', 'price' => 20]);
        $untouched = $this->item($store, ['name' => 'Cake', 'price' => 30]);

        $this->schedule($store, ['scope' => 'category', 'adjustment_type' => 'percent', 'adjustment_value' => 50], [['category', $cat->id]]);
        $this->schedule($store, ['scope' => 'item', 'adjustment_type' => 'amount', 'adjustment_value' => 5], [['item', $targeted->id]]);

        $this->assertSame(5.0, MenuScheduleService::quote($inCat)['price']);
        $this->assertSame(15.0, MenuScheduleService::quote($targeted)['price']);
        $this->assertSame(30.0, MenuScheduleService::quote($untouched)['price']);
    }

    public function test_decoration_rewrites_the_price_themes_render_and_keeps_the_original(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 20, 'active' => 1, 'options' => []]);
        $item->load('variants');
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 50]);

        MenuScheduleService::decorate(collect([$item]), (int) $store->id);

        $this->assertSame(5.0, (float) $item->price);
        $this->assertSame(10.0, (float) $item->original_price);
        $this->assertSame('Happy Hour', $item->schedule_label);
        $this->assertTrue($item->available_now);
        // A variant REPLACES the base price, so it must be discounted too or the
        // "Large" would quietly sell at the undiscounted rate.
        $this->assertSame(10.0, (float) $item->variants->first()->price);
    }

    /* ──────────────────── snapshot + historical re-total ─────────────── */

    public function test_a_scheduled_price_is_snapshotted_onto_the_order_line(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 20]);

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'qr',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        $line = $order->items->first();
        $this->assertSame(8.0, (float) $line->unit_price);
        $this->assertSame('Happy Hour', $line->price_label);
        $this->assertSame(16.0, (float) $order->fresh()->total);
    }

    /**
     * THE regression this feature exists to avoid: an order placed during a
     * happy hour must still re-total to what the guest actually paid after the
     * schedule is changed, ended or deleted.
     */
    public function test_a_historical_order_retotals_correctly_after_the_schedule_changes(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);
        $schedule = $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 50]);

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'qr',
            'items' => [['item_id' => $item->id, 'quantity' => 3]],
        ]);
        $this->assertSame(15.0, (float) $order->fresh()->total); // 5.00 × 3

        // The owner ends the happy hour and deletes it outright.
        $schedule->targets()->delete();
        $schedule->delete();
        MenuScheduleService::flush();

        $this->assertSame(15.0, (float) Order::find($order->id)->total, 'a deleted schedule must not re-price history');

        // …and even a brand-new, deeper discount must not touch the old order.
        $this->schedule($store, ['name' => 'Deeper', 'adjustment_type' => 'percent', 'adjustment_value' => 90]);
        $this->assertSame(15.0, (float) Order::find($order->id)->total);
    }

    public function test_a_line_with_no_schedule_still_derives_from_the_menu(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'qr',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertNull($order->items->first()->unit_price);
        $this->assertSame(10.0, (float) $order->fresh()->total);

        // Pre-T5 behaviour intact: raising the menu price re-prices an unsnapshotted line.
        $item->update(['price' => 12]);
        $this->assertSame(12.0, (float) Order::find($order->id)->total);
    }

    public function test_a_variant_line_snapshots_off_the_variant_price(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 20, 'active' => 1, 'options' => []]);
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 25]);

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'qr',
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'variation' => $variant->id]],
        ]);

        $this->assertSame(15.0, (float) $order->items->first()->unit_price);
        $this->assertSame(15.0, (float) $order->fresh()->total);
    }

    /* ───────────────────────── totals contract ───────────────────────── */

    /**
     * The T0 money contract, re-asserted with a schedule in play: tax is charged
     * on the item subtotal, before delivery/tip/service charge and (P6-271) AFTER
     * the discount; wallet and gift card are
     * TENDERS that lower what is due, never the order value.
     */
    public function test_the_totals_contract_is_unchanged_by_a_scheduled_price(): void
    {
        $store = $this->store(null, 'UTC');
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '10');
        StoreOption::updateStoreOption($store->id, 'restaurant_tax_type', 'percentage');
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', '7');

        $item = $this->item($store, ['price' => 100]);
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 20]); // → 80

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'delivery', 'source' => 'qr', 'address' => '1 St', 'tip_amount' => 3,
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order->discount_amount = 5;   // a coupon — lowers the tax base (P6-271)
        $order->wallet_applied = 20;  // a TENDER — must not change `total`
        $order->save();

        $order = Order::find($order->id);

        // subtotal 80 (the SCHEDULED price) − discount 5 → tax 7.5 on 75
        //   80 + 7.5 + delivery 7 + tip 3 − discount 5 = 92.5
        $this->assertSame(92.5, (float) $order->total);
        // Tax was NOT charged on delivery/tip (computeTax on the bare 80 is 8).
        $this->assertSame(8.0, round(Order::computeTax(80.0, store_options($store->id)), 2));
        // Wallet lowers only what is due.
        $this->assertSame(72.5, (float) $order->amount_due); // 92.5 − wallet 20
    }

    public function test_a_schedule_applies_before_a_coupon_never_after(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store, ['price' => 100]);
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 10]); // → 90

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'qr',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order->discount_amount = 10;
        $order->save();

        // 90 − 10 = 80. If the coupon had been applied first it would be
        // (100 − 10) × 0.9 = 81, and a stacked reading would give 81 or 72.
        $this->assertSame(80.0, (float) Order::find($order->id)->total);
    }

    /* ─────────────────── checkout revalidation by channel ────────────── */

    public function test_a_guest_channel_refuses_an_item_outside_its_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        $item = $this->item($store, ['available_from' => '07:00', 'available_to' => '11:00']);

        $this->expectException(ValidationException::class);
        OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'customer_app',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
    }

    /**
     * The register and the aggregator webhook deliberately do NOT enforce the
     * window: a cashier ringing up an off-menu item is a legitimate override,
     * and refusing a partner's already-paid order over a clock loses real money.
     */
    public function test_a_staff_or_partner_channel_may_still_ring_it_up(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        $item = $this->item($store, ['available_from' => '07:00', 'available_to' => '11:00']);

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'pos',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertCount(1, $order->items);
    }

    /**
     * The AI ordering agent is a guest channel: what it reads back to the
     * customer must be what the order path then charges, and it must not offer
     * an item OrderApiService will refuse for `source=agent`.
     */
    public function test_the_agent_quotes_the_scheduled_price_and_hides_closed_items(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        $item = $this->item($store, ['name' => 'Mojito', 'price' => 10]);
        $this->item($store, ['name' => 'Breakfast Bun', 'available_from' => '07:00', 'available_to' => '11:00']);
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 20]);

        $names = collect(AgentToolsService::searchMenu($store, ''))->pluck('name')->all();
        $this->assertContains('Mojito', $names);
        $this->assertNotContains('Breakfast Bun', $names, 'the agent must not offer an out-of-window item');

        $quote = AgentToolsService::validateCart($store, [
            ['item_id' => $item->id, 'quantity' => 2],
        ]);
        $this->assertSame(16.0, round((float) $quote['subtotal'], 2), 'the agent must quote the scheduled price');

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'agent',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);
        $this->assertSame(16.0, (float) $order->fresh()->total, 'quoted must equal charged');
    }

    /* ─────────────────────────── storefront ──────────────────────────── */

    public function test_the_storefront_hides_an_item_outside_its_window_and_shows_the_happy_hour_price(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');

        $shown = $this->item($store, ['name' => 'ZzMojito']);
        $this->item($store, ['name' => 'ZzBreakfastBun', 'available_from' => '07:00', 'available_to' => '11:00']);
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 20]);

        $res = $this->get('/'.$store->slug);
        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('ZzMojito', $html);
        $this->assertStringNotContainsString('ZzBreakfastBun', $html, 'an out-of-window item must not reach the page');
        // The payload carries the SCHEDULED price, plus the badge and the base.
        $this->assertStringContainsString('Happy Hour', $html);
        $this->assertMatchesRegularExpression('/"price"\s*:\s*"?8(\.0+)?"?/', $html);
    }

    public function test_the_storefront_can_grey_out_instead_of_hiding(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));
        $store = $this->store(null, 'UTC');
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        StoreOption::updateStoreOption($store->id, 'menu_show_unavailable', '1');
        $this->item($store, ['name' => 'ZzBreakfastBun', 'available_from' => '07:00', 'available_to' => '11:00']);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('ZzBreakfastBun', $html);
        $this->assertStringContainsString('"available_now"', $html);
    }

    /* ──────────────────────────── owner CRUD ─────────────────────────── */

    public function test_an_owner_can_create_and_toggle_a_schedule(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)
            ->post(route('stores.schedules.store').'?store_id='.$store->id, [
                'name' => 'Happy Hour', 'label' => 'HH −20%', 'scope' => 'store',
                'adjustment_type' => 'percent', 'adjustment_value' => 20,
                'starts_at' => '17:00', 'ends_at' => '19:00',
                'days' => ['mon', 'tue'], 'order_types' => ['on-table'], 'active' => '1',
            ])->assertRedirect();

        $s = PriceSchedule::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('mon,tue', $s->days);
        $this->assertSame('on-table', $s->order_types);
        $this->assertTrue($s->active);

        $this->actingAs($owner)
            ->post(route('stores.schedules.toggle', $s->id).'?store_id='.$store->id)
            ->assertRedirect();
        $this->assertFalse($s->fresh()->active);
    }

    public function test_a_percentage_over_100_is_refused(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)
            ->post(route('stores.schedules.store').'?store_id='.$store->id, [
                'name' => 'Free food', 'scope' => 'store',
                'adjustment_type' => 'percent', 'adjustment_value' => 150, 'active' => '1',
            ])->assertSessionHasErrors('adjustment_value');

        $this->assertSame(0, PriceSchedule::where('store_id', $store->id)->count());
    }

    /** A posted target id is attacker-controlled — another tenant's item must be dropped. */
    public function test_a_cross_tenant_target_is_dropped(): void
    {
        $owner = $this->owner();
        $mine = $this->store($owner);
        $theirs = $this->store();
        $theirItem = $this->item($theirs);

        $this->actingAs($owner)
            ->post(route('stores.schedules.store').'?store_id='.$mine->id, [
                'name' => 'Leak', 'scope' => 'item',
                'adjustment_type' => 'percent', 'adjustment_value' => 50, 'active' => '1',
                'target_ids' => [$theirItem->id],
            ])->assertRedirect();

        $s = PriceSchedule::where('store_id', $mine->id)->firstOrFail();
        $this->assertSame(0, $s->targets()->count());
        // And the other store's price is untouched.
        MenuScheduleService::flush();
        $this->assertSame(10.0, MenuScheduleService::quote($theirItem->fresh())['price']);
    }

    /* ───────────────────────────── caching ───────────────────────────── */

    public function test_the_cache_bucket_flips_within_the_window_length(): void
    {
        $store = $this->store(null, 'UTC');

        Carbon::setTestNow(Carbon::parse('2026-07-08 10:59', 'UTC'));
        $before = MenuScheduleService::cacheBucket((int) $store->id);

        Carbon::setTestNow(Carbon::parse('2026-07-08 11:01', 'UTC'));
        $this->assertNotSame($before, MenuScheduleService::cacheBucket((int) $store->id));

        // …and is stable inside one bucket.
        Carbon::setTestNow(Carbon::parse('2026-07-08 11:03', 'UTC'));
        $this->assertSame(
            MenuScheduleService::cacheBucket((int) $store->id),
            MenuScheduleService::cacheBucket((int) $store->id)
        );
    }

    /* ───────────────────────── money precision ───────────────────────── */

    /**
     * A 3-decimal currency must keep its fils all the way onto the order line.
     * OrderItem has no store_id, so this only works because it implements
     * storeIdForMoney() — without it the money falls back to 2 decimals and
     * 2.475 OMR is silently persisted as 2.48.
     */
    public function test_a_three_decimal_currency_keeps_its_fils_on_the_snapshot(): void
    {
        $store = $this->store(null, 'UTC');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'OMR');
        MenuScheduleService::flush();
        OrderItem::flushStoreIdCache();

        $item = $this->item($store, ['price' => 3.300]);
        $this->schedule($store, ['adjustment_type' => 'percent', 'adjustment_value' => 25]); // → 2.475

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway', 'source' => 'qr',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertSame('2.475', $order->items->first()->unit_price);
    }

    /* ──────────────────── per-language customer labels ───────────────── */

    /**
     * displayLabel() follows the customer's menu-language cookie (the same
     * mechanism Menu::getNameAttribute uses) and falls back to the base label
     * for languages the owner never translated.
     */
    public function test_display_label_follows_the_customer_language_cookie(): void
    {
        $store = $this->store(null, 'UTC');
        $s = $this->schedule($store, [
            'label_translations' => ['en' => 'Happy Hour', 'ar' => 'ساعة السعادة'],
        ]);

        try {
            $_COOKIE['Quick_user_lang_code'] = 'ar';
            $this->assertSame('ساعة السعادة', $s->displayLabel());

            $_COOKIE['Quick_user_lang_code'] = 'en';
            $this->assertSame('Happy Hour', $s->displayLabel());

            // Untranslated language → base label, exactly the pre-feature behavior.
            $_COOKIE['Quick_user_lang_code'] = 'fr';
            $this->assertSame('Happy Hour', $s->displayLabel());
        } finally {
            unset($_COOKIE['Quick_user_lang_code']);
        }
    }

    /** Decoration hands the translated badge to every theme. */
    public function test_decorated_schedule_label_is_translated(): void
    {
        $store = $this->store(null, 'UTC');
        $item = $this->item($store);
        $this->schedule($store, [
            'label_translations' => ['ar' => 'ساعة السعادة'],
        ]);

        try {
            $_COOKIE['Quick_user_lang_code'] = 'ar';
            $menus = collect([$item]);
            MenuScheduleService::decorate($menus, (int) $store->id);
            $this->assertSame('ساعة السعادة', $menus->first()->schedule_label);
        } finally {
            unset($_COOKIE['Quick_user_lang_code']);
        }
    }

    /** The dashboard round-trips per-language labels and drops empty ones. */
    public function test_owner_can_save_per_language_labels(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'UTC');
        $s = $this->schedule($store);

        $this->actingAs($owner)
            ->post(route('stores.schedules.update', $s->id).'?store_id='.$store->id, [
                'name' => 'HH', 'label' => 'Happy Hour',
                'label_i18n' => ['ar' => 'ساعة السعادة', 'fr' => '   ', 'bogus key' => 'x'],
                'scope' => 'store', 'adjustment_type' => 'percent', 'adjustment_value' => 20,
                'active' => '1',
            ])->assertSessionHas('success');

        $s->refresh();
        $this->assertSame('ساعة السعادة', $s->label_translations->ar);
        $this->assertObjectNotHasProperty('fr', $s->label_translations);
        $this->assertObjectNotHasProperty('bogus key', $s->label_translations);
    }
}
