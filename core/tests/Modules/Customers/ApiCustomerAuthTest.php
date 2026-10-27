<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Standalone customer app: token issuance (register/login), token-scoped profile,
 * order placement, addresses, logout/revocation, and public menu browsing.
 */
class ApiCustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin LTR: app-API money (store_money_plain) order is locale-sensitive (RTL flips
        // to number-first); these assertions pin the sign+decimals, not RTL position.
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $r, float $price = 10): Menu
    {
        return Menu::create(['store_id' => $r->id, 'name' => 'Dish '.Str::random(4), 'price' => $price, 'active' => 1]);
    }

    private function register(Store $r, ?string $email = null): array
    {
        $email = $email ?: Str::lower(Str::random(6)).'@ex.com';

        return $this->postJson(route('api.v1.customer.register', $r->id), [
            'name' => 'Sam', 'email' => $email, 'password' => 'secret123',
        ])->assertCreated()->json();
    }

    // ── Auth ───────────────────────────────────────────────────────────────────

    public function test_register_issues_token(): void
    {
        $r = $this->storeFixture();
        $out = $this->register($r);
        $this->assertNotEmpty($out['token']);
        $this->assertDatabaseHas('customers', ['id' => $out['customer']['id'], 'store_id' => $r->id]);
        $this->assertDatabaseHas('customer_tokens', ['customer_id' => $out['customer']['id']]);
    }

    public function test_customer_registration_uses_the_shared_strict_password_policy(): void
    {
        config(['auth.password_policy_strict' => true]);
        $store = $this->storeFixture();

        $this->postJson(route('api.v1.customer.register', $store->id), [
            'name' => 'Weak Password',
            'email' => 'weak@example.com',
            'password' => 'abcdef',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('customers', [
            'store_id' => $store->id,
            'email' => 'weak@example.com',
        ]);
    }

    public function test_duplicate_email_rejected(): void
    {
        $r = $this->storeFixture();
        $this->register($r, 'dup@ex.com');
        $this->postJson(route('api.v1.customer.register', $r->id), [
            'name' => 'X', 'email' => 'dup@ex.com', 'password' => 'secret123',
        ])->assertStatus(409);
    }

    public function test_login_ok_and_bad_password(): void
    {
        $r = $this->storeFixture();
        $this->register($r, 'log@ex.com');

        $this->postJson(route('api.v1.customer.login', $r->id), ['email' => 'log@ex.com', 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['token', 'customer' => ['id']]);

        $this->postJson(route('api.v1.customer.login', $r->id), ['email' => 'log@ex.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    public function test_me_requires_valid_token(): void
    {
        $r = $this->storeFixture();
        $token = $this->register($r)['token'];

        $this->getJson(route('api.v1.customer.me'))->assertUnauthorized();
        $this->withToken('bad')->getJson(route('api.v1.customer.me'))->assertUnauthorized();
        $this->withToken($token)->getJson(route('api.v1.customer.me'))->assertOk()->assertJsonPath('name', 'Sam');
    }

    /**
     * Money strings on the app-API (me.store_credit, store-credit) go through
     * store_money_plain: the STORE's own sign + decimals + a plain space, and NO
     * bidi isolates (which would leak as junk into a native app / JSON channel).
     * A 3-decimal store (OMR) keeps its fils rather than the old hardcoded 2dp.
     */
    public function test_store_credit_formatted_uses_store_decimals_and_is_isolate_free(): void
    {
        $r = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'currency_code', 'OMR'); // 3 decimals (fils)
        StoreOption::updateStoreOption($r->id, 'currency_sign', 'R');
        StoreOption::updateStoreOption($r->id, 'currency_pos', '1'); // sign before number

        $token = $this->register($r)['token'];

        foreach ([
            $this->withToken($token)->getJson(route('api.v1.customer.me')),
            $this->withToken($token)->getJson(route('api.v1.customer.store-credit')),
        ] as $resp) {
            $formatted = $resp->assertOk()->json('store_credit.formatted') ?? $resp->json('formatted');
            $this->assertSame('R 0.000', $formatted, 'app-API money must be store-scoped, spaced, 3-decimal');
            foreach (["\u{2066}", "\u{2068}", "\u{2069}"] as $iso) {
                $this->assertStringNotContainsString($iso, (string) $formatted, 'app-API money must be isolate-free');
            }
        }
    }

    // ── Order placement scoped to the customer ─────────────────────────────────

    public function test_customer_places_and_lists_own_order(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r, 8);
        $out = $this->register($r);
        $token = $out['token'];
        $customerId = $out['customer']['id'];

        $order = $this->withToken($token)
            ->postJson(route('api.v1.customer.orders.place'), [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 2]],
            ])->assertCreated()->json();

        // Order is stamped with this customer's id, not spoofable.
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'customer_id' => $customerId, 'store_id' => $r->id]);
        $this->assertEquals(16, $order['total']);

        $this->withToken($token)->getJson(route('api.v1.customer.orders'))
            ->assertOk()->assertJsonPath('data.0.id', $order['id']);
    }

    public function test_customer_cannot_order_foreign_item(): void
    {
        $r = $this->storeFixture();
        $foreign = $this->item($this->storeFixture(), 10);
        $token = $this->register($r)['token'];

        $this->withToken($token)
            ->postJson(route('api.v1.customer.orders.place'), [
                'type' => 'takeaway',
                'items' => [['item_id' => $foreign->id, 'quantity' => 1]],
            ])->assertStatus(422);
    }

    // ── Addresses ──────────────────────────────────────────────────────────────

    public function test_address_crud(): void
    {
        $r = $this->storeFixture();
        $token = $this->register($r)['token'];

        $addr = $this->withToken($token)->postJson(route('api.v1.customer.addresses.store'), [
            'line' => '9 Cedar Road', 'city' => 'Dubai',
        ])->assertCreated()->json();
        $this->assertTrue($addr['is_default']); // first address is default

        $this->withToken($token)->getJson(route('api.v1.customer.addresses'))
            ->assertOk()->assertJsonPath('data.0.line', '9 Cedar Road');

        $this->withToken($token)->deleteJson(route('api.v1.customer.addresses.destroy', $addr['id']))
            ->assertOk();
        $this->assertDatabaseMissing('customer_addresses', ['id' => $addr['id']]);
    }

    // ── Logout revokes the token ────────────────────────────────────────────────

    public function test_logout_revokes_token(): void
    {
        $r = $this->storeFixture();
        $token = $this->register($r)['token'];

        $this->withToken($token)->postJson(route('api.v1.customer.logout'))->assertOk();
        $this->withToken($token)->getJson(route('api.v1.customer.me'))->assertUnauthorized();
    }

    // ── Public menu ──────────────────────────────────────────────────────────────

    public function test_public_menu_browse(): void
    {
        $r = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        $this->getJson(route('api.v1.public.menu', $r->id))
            ->assertOk()
            ->assertJsonPath('categories.0.name', 'Mains')
            ->assertJsonPath('categories.0.items.0.name', 'Pizza');
    }
}
