<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'Test description', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

        $plan = TestPlans::create([
            'name' => 'Test Plan',
            'description' => 'Test plan',
            'monthly_price' => 0,
            'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => [
                'allow_viewonly' => '1',
                'allow_pro' => '1',
                'restaurant_limit' => 999,
            ],
            'status' => 1,
        ]);

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'plan' => $plan,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function createRestaurant(string $title = 'Pizza Palace'): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#ff5500',
            'title' => $title,
            'sub_title' => 'Test restaurant',
            'description' => 'Test description',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test Street',
            'phone' => '1234567890',
        ]);

        StoreOption::updateStoreOption($store->id, 'store_template', 'aurora');

        return $store;
    }

    private function createCustomer(Store $store, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'store_id' => $store->id,
            'name' => 'Alice Diner',
            'email' => 'alice@example.test',
            'phone' => '555100200',
            'password' => Hash::make('secret123'),
        ], $overrides));
    }

    private function createMenuFixture(Store $store, array $menuOverrides = []): array
    {
        $category = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Burgers',
            'parent' => null,
            'position' => 1,
        ]);

        $menu = Menu::create(array_merge([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Classic Burger',
            'description' => 'Juicy burger',
            'price' => 10,
            'image' => 'default.png',
            'type' => 'veg',
            'active' => '1',
            'position' => 1,
            'is_sold_out' => 0,
        ], $menuOverrides));

        $variantOption = MenuVariantOption::create([
            'menu_id' => $menu->id,
            'title' => 'Size',
            'options' => ['Small', 'Large'],
            'position' => 1,
            'active' => 1,
        ]);

        $variant = MenuVariant::create([
            'menu_id' => $menu->id,
            'price' => 14,
            'options' => [$variantOption->id => 1],
            'active' => 1,
            'position' => 1,
        ]);

        $extra = MenuExtra::create([
            'menu_id' => $menu->id,
            'title' => 'Extra Cheese',
            'price' => 2,
            'position' => 1,
            'active' => 1,
        ]);

        return compact('category', 'menu', 'variantOption', 'variant', 'extra');
    }

    private function createCustomerOrder(Store $store, Customer $customer, array $fixture, array $orderOverrides = [], array $itemOverrides = []): Order
    {
        $order = Order::create(array_merge([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone_number' => $customer->phone,
            'status' => 'completed',
            'type' => 'delivery',
            'created_at' => now(),
        ], $orderOverrides));

        $item = OrderItem::create(array_merge([
            'order_id' => $order->id,
            'item_id' => $fixture['menu']->id,
            'variation' => $fixture['variant']->id,
            'quantity' => 2,
            'order_note' => 'Extra crispy',
        ], $itemOverrides));

        OrderItemExtra::create([
            'order_item_id' => $item->id,
            'extra_id' => $fixture['extra']->id,
            'quantity' => 1,
        ]);

        return $order->fresh(['items.menu', 'items.variant', 'items.itemExtras.extra']);
    }

    public function test_customer_can_update_profile_and_add_default_address(): void
    {
        $store = $this->createRestaurant();
        $customer = $this->createCustomer($store);

        $this->actingAs($customer, 'customer');

        $profileResponse = $this->post("/{$store->id}/customer/profile", [
            'name' => 'Alice Updated',
            'email' => 'alice.updated@example.test',
            'phone' => '555999000',
            'marketing_consent' => '1',
        ]);

        $profileResponse->assertRedirect(route('customer.account', $store->id));

        $customer->refresh();
        $this->assertSame('Alice Updated', $customer->name);
        // P6-304: a new e-mail / phone is parked until proven; the live values stay until then.
        $this->assertSame('alice.updated@example.test', $customer->pending_email);
        $this->assertNotSame('alice.updated@example.test', $customer->email);
        $this->assertNotNull($customer->pending_phone);
        $this->assertTrue($customer->marketing_consent);

        $addressResponse = $this->post("/{$store->id}/customer/addresses", [
            'label' => 'Home',
            'line' => '42 Test Avenue',
            'city' => 'Riyadh',
            'state' => 'Riyadh Province',
            'zip' => '12345',
            'country' => 'Saudi Arabia',
            'is_default' => '1',
        ]);

        // Address add/remove now redirects to the profile page — the saved-address
        // manager lives there after the profile/orders split, not on the orders page.
        $addressResponse->assertRedirect(route('customer.profile', $store->id));

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'label' => 'Home',
            'line' => '42 Test Avenue',
            'city' => 'Riyadh',
            'is_default' => 1,
        ]);
    }

    public function test_customer_can_edit_a_saved_address(): void
    {
        $store = $this->createRestaurant();
        $customer = $this->createCustomer($store);
        $this->actingAs($customer, 'customer');

        $address = $customer->addresses()->create([
            'label' => 'Home', 'line' => 'Old Street 1', 'city' => 'Riyadh',
            'lat' => 24.5, 'lng' => 46.5, 'is_default' => 1,
        ]);

        $response = $this->put("/{$store->id}/customer/addresses/{$address->id}", [
            'label' => 'Work',
            'line' => 'New Boulevard 99',
            'city' => 'Jeddah',
            'lat' => '21.4225',
            'lng' => '39.8262',
            'is_default' => '1',
        ]);

        $response->assertRedirect(route('customer.profile', $store->id));

        $address->refresh();
        $this->assertSame('Work', $address->label);
        $this->assertSame('New Boulevard 99', $address->line);
        $this->assertSame('Jeddah', $address->city);
        $this->assertEqualsWithDelta(21.4225, (float) $address->lat, 0.0001);
        $this->assertEqualsWithDelta(39.8262, (float) $address->lng, 0.0001);
    }

    public function test_customer_cannot_edit_another_customers_address(): void
    {
        $store = $this->createRestaurant();
        $mine = $this->createCustomer($store);
        $other = $this->createCustomer($store, ['email' => 'other@example.test', 'phone' => '555000111']);

        $victimAddress = $other->addresses()->create(['label' => 'Theirs', 'line' => 'Private Rd 5', 'is_default' => 1]);

        $this->actingAs($mine, 'customer');
        $this->put("/{$store->id}/customer/addresses/{$victimAddress->id}", [
            'line' => 'Hijacked Ave 1',
        ])->assertForbidden();

        $this->assertSame('Private Rd 5', $victimAddress->fresh()->line);
    }

    public function test_customer_account_updates_are_scoped_to_their_restaurant(): void
    {
        $store = $this->createRestaurant('Pizza Palace');
        $otherRestaurant = $this->createRestaurant('Burger House');
        $customer = $this->createCustomer($store);

        $this->actingAs($customer, 'customer');

        $profileResponse = $this->post("/{$otherRestaurant->id}/customer/profile", [
            'name' => 'Cross Tenant',
            'email' => 'cross@example.test',
            'phone' => '555111222',
        ]);

        $profileResponse->assertForbidden();
        $this->assertSame('Alice Diner', $customer->fresh()->name);

        $addressResponse = $this->post("/{$otherRestaurant->id}/customer/addresses", [
            'label' => 'Office',
            'line' => 'Other Tenant Street',
        ]);

        $addressResponse->assertForbidden();
        $this->assertSame(0, CustomerAddress::count());
    }

    /**
     * Universal-gaps contract: every modern theme's account page must surface
     * edit-profile, saved-address add/remove, the three preference toggles
     * (notifications / marketing / SMS) and links to owner info pages. Renders
     * each theme's real account view and reports every missing control at once.
     */
    public function test_every_theme_account_surfaces_management_controls(): void
    {
        $themes = KeptTemplates::MODERN;

        $failures = [];

        // Real requests get an (empty) ViewErrorBag from ShareErrorsFromSession;
        // a direct view()->render() does not, so provide one for the whole loop.
        view()->share('errors', new ViewErrorBag);

        // Render every theme WITH >1 language so the language-switcher block is
        // exercised on all of them (a bad locale URL there would 500 the page).
        $langs = collect([
            (object) ['code' => 'en', 'name' => 'English'],
            (object) ['code' => 'ar', 'name' => 'Arabic'],
        ]);

        foreach ($themes as $theme) {
            $store = $this->createRestaurant('Store '.$theme);
            StoreOption::updateStoreOption($store->id, 'store_template', $theme);
            StoreOption::updateStoreOption($store->id, 'sf_page_about', 'About this store.');

            $customer = $this->createCustomer($store, ['email' => $theme.'@example.test', 'phone' => UniquePhone::next('55')]);
            CustomerAddress::create([
                'customer_id' => $customer->id, 'label' => 'Home',
                'line' => '1 Main St', 'city' => 'Town', 'is_default' => 1,
            ]);
            $customer->load('addresses');

            // Render the theme's real account view with the same data account()
            // builds — deterministic, and free of the multi-request session-guard
            // flakiness that plagues 17 sequential authenticated GETs.
            $postOptions = store_options($store->id);
            $postOptions->store_template = $theme;
            try {
                $html = view("store_templates.$theme.profile", [
                    'store' => $store,
                    'customer' => $customer,
                    'orders' => collect(),
                    'postOptions' => $postOptions,
                    'theme' => $theme,
                    'languages' => $langs,
                    'plan' => $store->user->plan(),
                    'storeCredit' => 0,
                    'infoPages' => storefront_info_pages($store->id),
                ])->render();
            } catch (\Throwable $e) {
                $failures[] = "$theme: profile render error: ".$e->getMessage();

                continue;
            }

            // The orders page (account.blade) must still render after the split,
            // and must NOT carry the settings form (it moved to profile.blade).
            try {
                $acctHtml = view("store_templates.$theme.account", [
                    'store' => $store, 'customer' => $customer, 'orders' => collect(),
                    'postOptions' => $postOptions, 'theme' => $theme, 'languages' => $langs,
                    'plan' => $store->user->plan(), 'storeCredit' => 0,
                    'infoPages' => storefront_info_pages($store->id),
                ])->render();
                if (str_contains($acctHtml, 'name="marketing_consent"')) {
                    $failures[] = "$theme: orders page still carries the settings form";
                }
            } catch (\Throwable $e) {
                $failures[] = "$theme: orders render error: ".$e->getMessage();
            }

            $markers = [
                'edit-profile' => $store->id.'/customer/profile',
                'address-crud' => $store->id.'/customer/addresses',
                'notifications-toggle' => 'name="notifications_enabled"',
                'marketing-optin' => 'name="marketing_consent"',
                'sms-optin' => 'name="sms_consent"',
                'info-link' => '/page/about',
            ];
            foreach ($markers as $label => $needle) {
                if (! str_contains($html, $needle)) {
                    $failures[] = "$theme: missing $label ($needle)";
                }
            }
        }

        $this->assertSame([], $failures, "Themes missing account controls:\n".implode("\n", $failures));
    }

    public function test_customer_can_fetch_reorder_payload_for_their_own_order(): void
    {
        $store = $this->createRestaurant();
        $customer = $this->createCustomer($store);
        $fixture = $this->createMenuFixture($store);
        $order = $this->createCustomerOrder($store, $customer, $fixture);

        $this->actingAs($customer, 'customer');

        $response = $this->post(route('customer.orders.reorder', [$store->id, $order->id]));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Order added to cart.',
                'missing_items' => [],
            ]);

        $payload = $response->json('items.0');
        $this->assertSame($fixture['menu']->id, $payload['id']);
        $this->assertSame(2, $payload['qty']);
        $this->assertSame($fixture['variant']->id, $payload['variantId']);
        $this->assertSame('Large', $payload['variantLabel']);
        $this->assertSame('Extra crispy', $payload['order_note']);
        $this->assertSame($fixture['extra']->id, $payload['extras'][0]['id']);
    }

    public function test_customer_reorder_skips_unavailable_items(): void
    {
        $store = $this->createRestaurant();
        $customer = $this->createCustomer($store);
        $fixture = $this->createMenuFixture($store, ['is_sold_out' => 1]);
        $order = $this->createCustomerOrder($store, $customer, $fixture);

        $this->actingAs($customer, 'customer');

        $response = $this->post(route('customer.orders.reorder', [$store->id, $order->id]));

        $response->assertOk()
            ->assertJson([
                'success' => false,
                'items' => [],
                'missing_items' => ['Classic Burger'],
            ]);
    }

    public function test_customer_cannot_fetch_reorder_payload_for_another_customers_order(): void
    {
        $store = $this->createRestaurant();
        $customer = $this->createCustomer($store);
        $otherCustomer = $this->createCustomer($store, [
            'email' => 'other@example.test',
            'phone' => '555777888',
        ]);
        $fixture = $this->createMenuFixture($store);
        $order = $this->createCustomerOrder($store, $otherCustomer, $fixture);

        $this->actingAs($customer, 'customer');

        $response = $this->post(route('customer.orders.reorder', [$store->id, $order->id]));

        $response->assertForbidden();
    }

    /**
     * F3 / WI-03: a store still saved on a removed legacy template (old-2-theme)
     * renders the fallback template's account page (200), not a silent redirect
     * of the signed-in customer to the menu.
     */
    public function test_removed_template_account_renders_the_fallback_not_redirect(): void
    {
        $store = $this->createRestaurant();
        StoreOption::updateStoreOption($store->id, 'store_template', 'old-2-theme');
        $customer = $this->createCustomer($store);

        $this->actingAs($customer, 'customer');
        $response = $this->get(route('customer.account', $store->id));

        $response->assertOk();
        $response->assertViewIs('store_templates.'.resolve_store_theme('old-2-theme').'.account');
    }

    /** Account order history is cursor-paginated: the blade page renders the first page only. */
    public function test_account_order_history_is_capped_at_twenty(): void
    {
        $store = $this->createRestaurant();
        $customer = $this->createCustomer($store);

        for ($i = 0; $i < 25; $i++) {
            Order::create([
                'store_id' => $store->id, 'customer_id' => $customer->id,
                'customer_name' => $customer->name, 'phone_number' => $customer->phone,
                'status' => 'completed', 'type' => 'delivery', 'created_at' => now(),
            ]);
        }

        $this->actingAs($customer, 'customer');
        $response = $this->get(route('customer.account', $store->id))->assertOk();

        $this->assertCount(20, $response->viewData('orders'), 'account history renders the first 20 orders');
        $this->assertNotNull($response->viewData('nextBefore'), 'a next-page cursor is exposed to the account view');
        $response->assertSee('data-orders-older', false);
        $response->assertSee('?before='.$response->viewData('nextBefore'), false);
    }

    /** A theme that DOES ship account.blade keeps using its own view (no regression). */
    public function test_theme_with_account_view_uses_its_own(): void
    {
        $store = $this->createRestaurant(); // aurora ships account.blade
        $customer = $this->createCustomer($store);

        $this->actingAs($customer, 'customer');
        $this->get(route('customer.account', $store->id))->assertOk()->assertSeeHtml($customer->name);
    }

    public function test_password_login_persists_customer_session_on_account_navigation(): void
    {
        $store = $this->createRestaurant();
        $customer = $this->createCustomer($store);

        $this->postJson(route('customer.login', $store->slug), [
            'email' => $customer->email,
            'password' => 'secret123',
            'store_id' => $store->id,
        ])->assertOk()->assertJson(['success' => true]);

        $this->get(route('customer.account', $store->slug))->assertOk()->assertSeeHtml($customer->name);

        $this->assertAuthenticatedAs($customer, 'customer');
    }
}
