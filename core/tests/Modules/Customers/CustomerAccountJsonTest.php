<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Tests\TestCase;

/**
 * The logged-in account panel (Liquid themes) reads/writes the diner's own
 * profile + addresses as JSON through the existing customer.* controllers
 * (AJAX branch added for the runtime).
 */
class CustomerAccountJsonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makePost(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Shop', 'sub_title' => 'Sub', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeCustomer(Store $store): Customer
    {
        return Customer::create([
            'store_id' => $store->id, 'name' => 'Sam', 'email' => 'sam@ex.co', 'phone' => '0912',
        ]);
    }

    public function test_account_returns_json_for_the_logged_in_diner(): void
    {
        $store = $this->makePost();
        $customer = $this->makeCustomer($store);
        CustomerAddress::create(['customer_id' => $customer->id, 'line' => '5 Palm St', 'city' => 'Tripoli', 'is_default' => 1]);

        $resp = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.account', $store->id));

        $resp->assertOk()
            ->assertJsonPath('customer.name', 'Sam')
            ->assertJsonPath('customer.email', 'sam@ex.co')
            ->assertJsonPath('addresses.0.line', '5 Palm St')
            ->assertJsonPath('addresses.0.is_default', true);
    }

    public function test_profile_and_addresses_update_via_ajax(): void
    {
        $store = $this->makePost();
        $customer = $this->makeCustomer($store);

        // update profile
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.profile.update', $store->id), ['name' => 'Sam Renamed', 'email' => 'sam2@ex.co'])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame('Sam Renamed', $customer->fresh()->name);

        // add address
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.addresses.store', $store->id), ['line' => '9 Sea Rd', 'city' => 'Benghazi'])
            ->assertOk()->assertJson(['success' => true]);
        $address = $customer->addresses()->first();
        $this->assertSame('9 Sea Rd', $address->line);

        // delete address
        $this->actingAs($customer, 'customer')
            ->deleteJson(route('customer.addresses.destroy', [$store->id, $address->id]))
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame(0, $customer->addresses()->count());
    }
}
