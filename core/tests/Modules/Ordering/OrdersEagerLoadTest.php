<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4 — the merchant Orders list and the staff-PWA order poll must eager-load exactly
 * what Order::getTotalAttribute() and the row partial read (items.menu, items.variant,
 * items.itemExtras.extra) so 50 orders don't fan out into hundreds of lazy queries.
 */
class OrdersEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** F1: the orders-page cleanup of stale unpaid orders restores their deducted stock. */
    public function test_stale_unpaid_order_cleanup_restores_stock(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        // stock_qty 7 = post-deduct state (10 − 3 sold on the abandoned order).
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Burger', 'price' => 20, 'active' => '1', 'track_inventory' => 1, 'stock_qty' => 7]);

        // An abandoned pay-online order older than the 3h cleanup window.
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'unpaid', 'created_at' => now()->subHours(4)]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 3, 'variation' => 0]);

        // Loading the orders page triggers the stale-unpaid cleanup.
        $this->actingAs($owner)->get(route('stores.orders', $r->id))->assertStatus(200);

        // Purged AND its 3 units restored (pre-fix: bulk-deleted with no restore → stuck at 7).
        $this->assertNull(Order::find($order->id), 'stale unpaid order purged');
        $this->assertSame(10, (int) $item->fresh()->stock_qty, 'its deducted stock was restored');
    }

    public function test_reading_totals_over_the_eager_loaded_list_issues_no_lazy_queries(): void
    {
        $r = $this->storeFixture();

        // Two orders, each a two-line cart with a variant + an extra — exactly the
        // shape whose total accessor previously lazy-loaded menu/variant/extra per line.
        for ($n = 0; $n < 2; $n++) {
            $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish'.$n, 'price' => 10, 'active' => 1]);
            $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 12.00, 'options' => '[]']);
            $extra = MenuExtra::create(['menu_id' => $item->id, 'title' => 'Sauce', 'price' => 1.50]);

            OrderApiService::create((int) $r->user_id, $r->id, [
                'type' => 'takeaway',
                'items' => [
                    ['item_id' => $item->id, 'quantity' => 2, 'variation' => $variant->id, 'extras' => [$extra->id]],
                ],
            ]);
        }

        // The exact eager-load set ManagesOrders / StaffAppController now use.
        $orders = Order::query()
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra', 'voiceNote'])
            ->where('store_id', $r->id)
            ->get();

        $this->assertCount(2, $orders);

        // Reading totals must NOT lazy-load the item relations (menu/variant/extras).
        // Order::getTotalAttribute() also reads store_options() — a separate per-store
        // query removed by P1-4 — so we assert specifically that no menu/variant/extra
        // relation is lazy-loaded, which is the N+1 P4 fixes.
        $lazyRelationHits = 0;
        DB::listen(function ($q) use (&$lazyRelationHits) {
            if (preg_match('/from\s+["`]?(menus|menu_variants|order_item_extras|modifiers|order_items)["`]?/i', $q->sql)) {
                $lazyRelationHits++;
            }
        });
        foreach ($orders as $o) {
            $t = $o->total; // exercises menu, variant, itemExtras.extra
            foreach ($o->items as $line) {
                $line->menu?->name;
                $line->variant?->price;
                $line->itemExtras->pluck('extra.title');
            }
            $this->assertIsNumeric($t);
        }

        $this->assertSame(0, $lazyRelationHits, "Item relations must be eager-loaded (no menu/variant/extra lazy queries); got {$lazyRelationHits}.");
    }

    public function test_orders_screen_batches_variant_option_lookups(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        // Two orders, each a line whose variant selects an option from a group — the
        // shape that used to MenuVariantOption::find() once per option in the loop.
        for ($n = 0; $n < 2; $n++) {
            $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish'.$n, 'price' => 10, 'active' => 1]);
            // options is a LIST (from explode) selected by index — mirror real data.
            $group = MenuVariantOption::create([
                'menu_id' => $item->id, 'store_id' => $r->id, 'title' => 'Size', 'options' => ['Small', 'Large'],
            ]);
            $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 12, 'options' => [$group->id => 0]]);
            OrderApiService::create((int) $r->user_id, $r->id, [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 1, 'variation' => $variant->id]],
            ]);
        }

        $optionQueries = 0;
        DB::listen(function ($q) use (&$optionQueries) {
            if (preg_match('/from\s+["`]?[a-z_]*modifier_groups["`]?/i', $q->sql)) {
                $optionQueries++;
            }
        });

        $this->actingAs($owner)->get(route('stores.orders', $r->id))->assertStatus(200);

        // One batched whereIn for the whole page, not one find() per option (would be 2+).
        $this->assertLessThanOrEqual(1, $optionQueries, "variant-option lookups must batch into one query; got {$optionQueries}.");
    }
}
