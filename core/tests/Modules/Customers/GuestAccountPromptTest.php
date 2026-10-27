<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL E1 (rev/guest-order-linking): the post-checkout signup prompt on the
 * track page shows to guests (prefilled from the order) and hides for a
 * logged-in customer.
 */
class GuestAccountPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function makeOrder(): Order
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        return Order::create(['store_id' => $store->id, 'customer_name' => 'Jane Diner', 'phone_number' => '555-7788']);
    }

    private function render(Order $order): string
    {
        return view('store_templates._partials.guest-account-prompt', ['order' => $order])->render();
    }

    public function test_prompt_shows_for_guest_prefilled_and_targets_register(): void
    {
        $order = $this->makeOrder();
        $html = $this->render($order);

        $this->assertStringContainsString('golSignupForm', $html);
        $this->assertStringContainsString('Jane Diner', $html);                 // name prefilled
        // The register URL is emitted via @json (slashes escaped) into the JS config.
        $this->assertStringContainsString(trim(json_encode(route('customer.register', $order->store_id)), '"'), $html);
        $this->assertStringContainsString('555-7788', $html);                    // phone carried to the payload
    }

    public function test_prompt_hidden_for_logged_in_customer(): void
    {
        $order = $this->makeOrder();
        $customer = Customer::create(['store_id' => $order->store_id, 'name' => 'M', 'email' => 'm@test.com', 'password' => bcrypt('secret-123')]);
        Auth::guard('customer')->login($customer);

        $html = trim($this->render($order));
        $this->assertStringNotContainsString('golSignupForm', $html);
    }
}
