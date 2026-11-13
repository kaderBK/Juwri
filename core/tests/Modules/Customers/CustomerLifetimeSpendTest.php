<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * WI-M4: customers.total_spent must move ATOMICALLY. `$c->total_spent += $amount`
 * + save() was a read-modify-write against a value read by find(), so two
 * concurrent orders by the same diner both wrote (old + own) and one order's money
 * vanished from the LTV while orders_count (already increment()) counted both. The
 * fix is `increment('total_spent', $spend, ['last_order_at' => now()])` with an
 * explicit round() (increment bypasses the model's money cast). Plus a derived-spend
 * reconciliation the merchant can see and repair.
 */
class CustomerLifetimeSpendTest extends TestCase
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
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return isset($this->d[$k]);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        // The owner order-notification email renders through css-to-inline-styles,
        // which 500s on the empty template in the test env — fake it so the
        // submitOrder RESPONSE is clean (the M4 aggregate runs before the mail).
        Mail::fake();
        Notification::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeMenuItem(Store $r, float $price = 10): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => $price, 'active' => 1]);
    }

    private function customer(Store $r): Customer
    {
        return Customer::create(['store_id' => $r->id, 'name' => 'Ada', 'phone' => UniquePhone::next('55-'), 'total_spent' => 0, 'orders_count' => 0]);
    }

    private function placeOrder(Store $r, Customer $c, Menu $item, array $extra = []): TestResponse
    {
        return $this->actingAs($c, 'customer')->post(route('store.submitOrder', $r), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Ada',
            'phone-number' => $c->phone,
            'ordering-type' => 'takeaway',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    public function test_total_spent_survives_a_concurrent_write(): void
    {
        $r = $this->makeRestaurant();
        $item = $this->makeMenuItem($r, 10);
        $c = $this->customer($r);

        // A competing order for the same diner lands right after the orders_count
        // increment and before the total_spent write.
        $fired = false;
        Customer::updated(function (Customer $m) use (&$fired, $c) {
            if ($fired || (int) $m->id !== (int) $c->id) {
                return;
            }
            $fired = true;
            DB::table('customers')->where('id', $c->id)->increment('total_spent_minor', 10000); // P1-05: 100.00
        });

        $this->placeOrder($r, $c, $item)->assertOk();

        // Pre-fix the RMW clobbers the concurrent +100 -> 10; post-fix the atomic
        // increment adds this order's 10 to the concurrent 100 -> 110.
        $this->assertEqualsWithDelta(110.0, (float) $c->fresh()->total_spent, 0.001);
    }

    public function test_total_spent_is_written_as_a_sql_expression(): void
    {
        $r = $this->makeRestaurant();
        $item = $this->makeMenuItem($r, 10);
        $c = $this->customer($r);

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $this->placeOrder($r, $c, $item)->assertOk();

        $selfRef = array_filter($sql, fn ($s) => preg_match('/update .*customers.* set .*total_spent.*=.*total_spent.*\+/i', $s));
        $this->assertNotEmpty($selfRef, 'total_spent must be a self-referencing SQL increment');
    }

    public function test_total_spent_equals_the_authoritative_order_total(): void
    {
        $r = $this->makeRestaurant();
        $item = $this->makeMenuItem($r, 10);
        $c = $this->customer($r);

        $this->placeOrder($r, $c, $item)->assertOk();

        $order = Order::where('customer_id', $c->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta((float) $order->fresh()->total, (float) $c->fresh()->total_spent, 0.001);
        $this->assertSame(1, (int) $c->fresh()->orders_count);
        $this->assertNotNull($c->fresh()->last_order_at);
    }

    public function test_derived_lifetime_spend_sums_the_order_history(): void
    {
        $r = $this->makeRestaurant();
        $item = $this->makeMenuItem($r, 10);
        $c = $this->customer($r);

        $totals = [];
        foreach (range(1, 3) as $i) {
            $o = Order::create(['store_id' => $r->id, 'customer_id' => $c->id, 'type' => 'takeaway', 'status' => 'completed']);
            OrderItem::create(['order_id' => $o->id, 'item_id' => $item->id, 'quantity' => $i, 'variation' => 0]);
            $totals[] = (float) $o->fresh()->total;
        }

        $this->assertEqualsWithDelta(array_sum($totals), $c->derivedLifetimeSpend(), 0.001);
    }

    public function test_recalculate_repairs_drift_and_is_audited(): void
    {
        $r = $this->makeRestaurant();
        $item = $this->makeMenuItem($r, 10);
        $c = $this->customer($r);
        $o = Order::create(['store_id' => $r->id, 'customer_id' => $c->id, 'type' => 'takeaway', 'status' => 'completed']);
        OrderItem::create(['order_id' => $o->id, 'item_id' => $item->id, 'quantity' => 2, 'variation' => 0]);

        // Deliberately wrong stored value.
        DB::table('customers')->where('id', $c->id)->update(['total_spent_minor' => 100]); // P1-05: 1.00

        $this->actingAs($r->user, 'web')
            ->post(route('customers.recalculate', $c))
            ->assertRedirect();

        $this->assertEqualsWithDelta($c->derivedLifetimeSpend(), (float) $c->fresh()->total_spent, 0.001);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.ltv_recalculated', 'subject_id' => $c->id]);
        $log = ActivityLog::where('action', 'customer.ltv_recalculated')->latest('id')->first();
        $this->assertEqualsWithDelta(1.0, (float) ($log->meta['from'] ?? -1), 0.001);
    }

    public function test_recalculate_is_tenant_scoped(): void
    {
        $r = $this->makeRestaurant();
        $item = $this->makeMenuItem($r, 10);
        $c = $this->customer($r);
        $o = Order::create(['store_id' => $r->id, 'customer_id' => $c->id, 'type' => 'takeaway', 'status' => 'completed']);
        OrderItem::create(['order_id' => $o->id, 'item_id' => $item->id, 'quantity' => 2, 'variation' => 0]);
        // A stored value that a recalculation WOULD change (the derived spend is the order total).
        DB::table('customers')->where('id', $c->id)->update(['total_spent_minor' => 100]); // 1.00
        $this->assertGreaterThan(1.0, $c->derivedLifetimeSpend());
        $other = $this->makeRestaurant();

        $this->actingAs($other->user, 'web')
            ->post(route('customers.recalculate', $c))
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame(100, (int) DB::table('customers')->where('id', $c->id)->value('total_spent_minor'), 'the victim\'s stored spend is unchanged');
        $this->assertDatabaseMissing('activity_logs', ['action' => 'customer.ltv_recalculated', 'subject_id' => $c->id]);
    }
}
