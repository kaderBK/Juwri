<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-294 Customer address book — shared field validation
 * (lat/lng range + both-or-neither, line must contain a non-whitespace
 * character) and the 20-addresses-per-customer cap, on BOTH the
 * storefront-web `customer.addresses.store` endpoint and the
 * standalone customer-app `api.v1.customer.addresses.store` endpoint.
 */
class CustomerAddressP6294Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // The web endpoint renders Blade views on a successful redirect;
        // the share mirrors the existing customer-side fixtures.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T',
            'social_share_image' => 'default.png', 'userlangsel' => 0,
            'include_language_code' => 0, 'site_favicon' => 'favicon.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id,
            'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeCustomer(Store $r): Customer
    {
        return Customer::create([
            'store_id' => $r->id,
            'name' => 'Diner',
            'email' => Str::random(6).'@example.com',
            'phone' => '555'.Str::random(4),
            'session_hash' => Str::random(32),
        ]);
    }

    /** Seed N addresses owned by $c (fill remaining slots up to the cap). */
    private function seedAddresses(Customer $c, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            CustomerAddress::create([
                'customer_id' => $c->id,
                'line' => 'Seed '.Str::random(4),
                'is_default' => $i === 0 ? 1 : 0,
            ]);
        }
    }

    // ── Web (`customer.addresses.store`) ─────────────────────────────────────

    public function test_web_rejects_lat_above_90(): void
    {
        $r = $this->makeRestaurant();
        $c = $this->makeCustomer($r);

        $this->actingAs($c, 'customer')
            ->postJson(route('customer.addresses.store', $r->id), [
                'line' => '42 High Street',
                'lat' => 999,
                'lng' => 50,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_web_rejects_lng_above_180(): void
    {
        $r = $this->makeRestaurant();
        $c = $this->makeCustomer($r);

        $this->actingAs($c, 'customer')
            ->postJson(route('customer.addresses.store', $r->id), [
                'line' => '42 High Street',
                'lat' => 50,
                'lng' => 9999,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_web_rejects_lat_without_lng(): void
    {
        $r = $this->makeRestaurant();
        $c = $this->makeCustomer($r);

        $this->actingAs($c, 'customer')
            ->postJson(route('customer.addresses.store', $r->id), [
                'line' => '42 High Street',
                'lat' => 24.7136,
                // lng omitted
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_web_rejects_whitespace_only_line(): void
    {
        $r = $this->makeRestaurant();
        $c = $this->makeCustomer($r);

        $this->actingAs($c, 'customer')
            ->postJson(route('customer.addresses.store', $r->id), [
                'line' => "   \t  ",
                'city' => 'London',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_web_rejects_21st_address(): void
    {
        $r = $this->makeRestaurant();
        $c = $this->makeCustomer($r);
        $this->seedAddresses($c, 20);

        $this->actingAs($c, 'customer')
            ->postJson(route('customer.addresses.store', $r->id), [
                'line' => '21st Street',
                'city' => 'London',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // Still exactly the seeded 20.
        $this->assertSame(20, CustomerAddress::where('customer_id', $c->id)->count());
    }

    public function test_web_accepts_valid_address(): void
    {
        $r = $this->makeRestaurant();
        $c = $this->makeCustomer($r);

        $this->actingAs($c, 'customer')
            ->postJson(route('customer.addresses.store', $r->id), [
                'line' => '  42 High Street  ',
                'city' => 'London',
                'country' => 'GB',
                'lat' => 51.5074,
                'lng' => -0.1278,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $c->id,
            'line' => '42 High Street', // trimmed
            'is_default' => 1,
        ]);
    }

    // ── API (`api.v1.customer.addresses.store`) ──────────────────────────────

    private function registerCustomer(Store $r): array
    {
        return $this->postJson(route('api.v1.customer.register', $r->id), [
            'name' => 'API '.Str::random(4),
            'email' => Str::lower(Str::random(6)).'@ex.com',
            'password' => 'secret123',
        ])->assertCreated()->json();
    }

    public function test_api_rejects_lat_above_90(): void
    {
        $r = $this->makeRestaurant();
        $token = $this->registerCustomer($r)['token'];

        $this->withToken($token)
            ->postJson(route('api.v1.customer.addresses.store'), [
                'line' => '42 High Street',
                'lat' => 999,
                'lng' => 50,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_api_rejects_lng_above_180(): void
    {
        $r = $this->makeRestaurant();
        $token = $this->registerCustomer($r)['token'];

        $this->withToken($token)
            ->postJson(route('api.v1.customer.addresses.store'), [
                'line' => '42 High Street',
                'lat' => 50,
                'lng' => 9999,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_api_rejects_lat_without_lng(): void
    {
        $r = $this->makeRestaurant();
        $token = $this->registerCustomer($r)['token'];

        $this->withToken($token)
            ->postJson(route('api.v1.customer.addresses.store'), [
                'line' => '42 High Street',
                'lat' => 24.7136,
                // lng omitted
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_api_rejects_whitespace_only_line(): void
    {
        $r = $this->makeRestaurant();
        $token = $this->registerCustomer($r)['token'];

        $this->withToken($token)
            ->postJson(route('api.v1.customer.addresses.store'), [
                'line' => "   \t  ",
                'city' => 'Dubai',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_api_rejects_21st_address(): void
    {
        $r = $this->makeRestaurant();
        $token = $this->registerCustomer($r)['token'];

        // The freshly-registered customer must already have 20 addresses
        // before the 21st is rejected. Seed via the API to keep the model
        // path identical.
        for ($i = 0; $i < 20; $i++) {
            $this->withToken($token)
                ->postJson(route('api.v1.customer.addresses.store'), [
                    'line' => 'Seed '.Str::random(4),
                ])->assertCreated();
        }

        $this->withToken($token)
            ->postJson(route('api.v1.customer.addresses.store'), [
                'line' => '21st Street',
                'city' => 'Dubai',
            ])
            ->assertStatus(422);

        $this->assertSame(20, CustomerAddress::count());
    }

    public function test_api_accepts_valid_address(): void
    {
        $r = $this->makeRestaurant();
        $token = $this->registerCustomer($r)['token'];

        $payload = [
            'line' => '  9 Cedar Road  ',
            'city' => 'Dubai',
            'country' => 'AE',
            'lat' => 25.2048,
            'lng' => 55.2708,
            'is_default' => true,
        ];

        $addr = $this->withToken($token)
            ->postJson(route('api.v1.customer.addresses.store'), $payload)
            ->assertCreated()
            ->json();

        $this->assertSame('9 Cedar Road', $addr['line']);
        $this->assertSame('Dubai', $addr['city']);
        $this->assertSame('25.20480000', (string) $addr['lat']);
        $this->assertSame('55.27080000', (string) $addr['lng']);
        $this->assertTrue($addr['is_default']);
    }
}
