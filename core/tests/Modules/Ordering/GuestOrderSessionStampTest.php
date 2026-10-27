<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreTable;
use App\Services\GuestOrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL A1 (rev/guest-order-linking): guest checkout stamps the order into the
 * browser session (both storefront pipelines); logged-in checkout does NOT.
 * The stamp is the P1 "same-browser" proof a later login/register claims on.
 */
class GuestOrderSessionStampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

        // Deterministic pipeline default; individual tests flip to 'unified'.
        config(['settings.storefront_order_pipeline' => 'legacy']);
        config(['settings.storefront_order_pipeline_stores' => '']);

        /* The owner-notification mailable renders inline on the sync queue and
           500s in the test env (empty template → DOMDocument ValueError, which
           escapes sendMail's \Exception catch). Fake mail — this suite asserts
           session stamping, not mail. */
        Mail::fake();
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeMenuItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1]);
    }

    private function submitOrder(Store $store, Menu $item, array $extra = []): TestResponse
    {
        StoreTable::firstOrCreate(
            ['store_id' => $store->id, 'table_no' => 5],
            ['key' => Str::random(10)]
        );

        return $this->post(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'extras' => []]]),
            'name' => 'Test Guest',
            'ordering-type' => 'on-table',
            'table' => '5',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    /** Guest checkout (legacy pipeline) stamps the order into the session. */
    public function test_guest_order_is_stamped_into_session_legacy_pipeline(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeMenuItem($store);

        $this->submitOrder($store, $item)->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $entries = session()->get(GuestOrderSession::KEY);

        $this->assertIsArray($entries);
        $this->assertCount(1, $entries);
        $this->assertSame($order->id, $entries[0]['order_id']);
        $this->assertSame($store->id, $entries[0]['store_id']);
        $this->assertNotEmpty($entries[0]['placed_at']);
    }

    /** Guest checkout (unified pipeline) stamps too — convergence-point coverage. */
    public function test_guest_order_is_stamped_into_session_unified_pipeline(): void
    {
        config(['settings.storefront_order_pipeline' => 'unified']);

        $store = $this->makeRestaurant();
        $item = $this->makeMenuItem($store);

        $this->submitOrder($store, $item)->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $entries = session()->get(GuestOrderSession::KEY);

        $this->assertIsArray($entries);
        $this->assertCount(1, $entries);
        $this->assertSame($order->id, $entries[0]['order_id']);
    }

    /** A logged-in customer's checkout is NOT stamped (order already owned). */
    public function test_logged_in_order_is_not_stamped(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeMenuItem($store);

        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'Member',
            'email' => 'member@test.com',
            'password' => bcrypt('secret-123'),
        ]);

        // One request per test (actingAs second-request trap).
        $this->actingAs($customer, 'customer')
            ->submitOrder($store, $item)
            ->assertJson(['success' => true]);

        $this->assertNull(session()->get(GuestOrderSession::KEY));

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame($customer->id, $order->customer_id);
    }

    /** The stamp list caps at MAX entries, dropping the oldest. */
    public function test_stamp_list_caps_and_drops_oldest(): void
    {
        $store = $this->makeRestaurant();

        $orders = [];
        for ($i = 0; $i < GuestOrderSession::MAX + 3; $i++) {
            $orders[] = Order::create(['store_id' => $store->id, 'customer_name' => 'G'.$i]);
        }

        foreach ($orders as $order) {
            GuestOrderSession::stamp($order);
        }

        $entries = GuestOrderSession::entries();
        $this->assertCount(GuestOrderSession::MAX, $entries);
        // Oldest 3 dropped; list starts at the 4th order.
        $this->assertSame($orders[3]->id, $entries[0]['order_id']);
        $this->assertSame(end($orders)->id, $entries[GuestOrderSession::MAX - 1]['order_id']);
    }

    /** forget() removes claimed ids and clears the key when empty. */
    public function test_forget_removes_entries(): void
    {
        $store = $this->makeRestaurant();
        $a = Order::create(['store_id' => $store->id, 'customer_name' => 'A']);
        $b = Order::create(['store_id' => $store->id, 'customer_name' => 'B']);

        GuestOrderSession::stamp($a);
        GuestOrderSession::stamp($b);

        GuestOrderSession::forget([$a->id]);
        $entries = GuestOrderSession::entries();
        $this->assertCount(1, $entries);
        $this->assertSame($b->id, $entries[0]['order_id']);

        GuestOrderSession::forget([$b->id]);
        $this->assertNull(session()->get(GuestOrderSession::KEY));
    }
}
