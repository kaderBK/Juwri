<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Security (same rule as the SMS OTP scoping): customer email/password sign-in and sign-up happen in the store of the
 * `{store}` URL. The body `store_id` (every theme sends it) must name that same store, else 422 — a page of
 * store A can never authenticate or create a customer of store B.
 */
class CustomerAuthUrlStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function makeStore(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function customerOf(Store $store, string $email = 'jane@test.com'): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => 'Jane', 'email' => $email, 'password' => Hash::make('secret-pass-123')]);
    }

    /** @return array<string, mixed> */
    private function registration(int $storeId, array $over = []): array
    {
        return array_merge([
            'name' => 'Jane', 'email' => 'new@test.com', 'password' => 'Str0ng-Passw0rd!x',
            'password_confirmation' => 'Str0ng-Passw0rd!x', 'store_id' => $storeId,
        ], $over);
    }

    public function test_login_with_body_store_b_on_store_a_url_is_refused(): void
    {
        $a = $this->makeStore();
        $b = $this->makeStore();
        $this->customerOf($b);

        $this->postJson(route('customer.login', $a), ['email' => 'jane@test.com', 'password' => 'secret-pass-123', 'store_id' => $b->id])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertGuest('customer');
    }

    public function test_a_customer_of_store_b_cannot_sign_in_on_store_a_url(): void
    {
        $a = $this->makeStore();
        $b = $this->makeStore();
        $this->customerOf($b);

        // Valid B credentials, honest body (store A) — no such customer at A.
        $this->postJson(route('customer.login', $a), ['email' => 'jane@test.com', 'password' => 'secret-pass-123', 'store_id' => $a->id])
            ->assertStatus(401);
        $this->assertGuest('customer');
    }

    public function test_login_on_the_matching_store_is_unchanged(): void
    {
        $a = $this->makeStore();
        $jane = $this->customerOf($a);

        $this->postJson(route('customer.login', $a->slug), ['email' => 'jane@test.com', 'password' => 'secret-pass-123', 'store_id' => $a->id])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertAuthenticatedAs($jane, 'customer');
    }

    public function test_register_with_body_store_b_on_store_a_url_is_refused(): void
    {
        $a = $this->makeStore();
        $b = $this->makeStore();

        $this->postJson(route('customer.register', $a), $this->registration((int) $b->id))
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, Customer::count());
        $this->assertGuest('customer');
    }

    public function test_register_on_the_matching_store_creates_the_customer_there(): void
    {
        $a = $this->makeStore();
        $b = $this->makeStore();

        $this->postJson(route('customer.register', $a), $this->registration((int) $a->id))
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, Customer::where('store_id', $a->id)->where('email', 'new@test.com')->count());
        $this->assertSame(0, Customer::where('store_id', $b->id)->count());
        $this->assertAuthenticated('customer');
    }

    public function test_an_unknown_store_url_is_refused(): void
    {
        $a = $this->makeStore();

        $this->postJson('/no-such-store-xyz/customer/login', ['email' => 'jane@test.com', 'password' => 'x', 'store_id' => $a->id])
            ->assertStatus(422);
        $this->postJson('/no-such-store-xyz/customer/register', $this->registration((int) $a->id))
            ->assertStatus(422);
        $this->assertSame(0, Customer::count());
    }
}
