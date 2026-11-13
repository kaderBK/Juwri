<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL B2 (rev/guest-order-linking): SMS-OTP login folds phone-matched
 * passwordless shadow rows in (P2), and the attacker cases stay fenced.
 */
class CustomerOtpClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        // OTP verification passes; matching/claiming is what we test.
        $this->mock(SmsOtpService::class, function ($m) {
            $m->shouldReceive('verify')->andReturn(true);
        });
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function verify(Store $r, string $phone): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('customer.otp.verify', $r), [
            'phone' => $phone, 'code' => '123456', 'store_id' => $r->id,
        ]);
    }

    public function test_otp_login_bridges_raw_formatted_shadow_into_login_row(): void
    {
        $r = $this->makeRestaurant();
        // Checkout capture stored the phone exactly as the diner typed it (spaces).
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'phone' => '555 123 4567', 'source' => 'checkout']);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Guest']);

        // OTP login: verifyOtp normalizes to "5551234567" and firstOrCreates a
        // DIFFERENT row than the raw-formatted shadow — so the bridge, not the
        // firstOrCreate reuse, is what must attach the order.
        $this->verify($r, '555 123 4567')
            ->assertOk()
            ->assertJson(['success' => true, 'claimed_count' => 1]);

        $loginRow = Customer::where('store_id', $r->id)->where('phone', '5551234567')->first();
        $this->assertNotNull($loginRow, 'OTP should have created the normalized login row');
        $this->assertNotSame($shadow->id, $loginRow->id, 'bridge case requires a distinct login row');
        // Shadow folded into the login row; its order followed.
        $this->assertDatabaseMissing('customers', ['id' => $shadow->id]);
        $this->assertSame($loginRow->id, $order->fresh()->customer_id);
    }

    /** ATTACKER: a passworded account sharing the phone is never merged away. */
    public function test_passworded_account_with_same_phone_not_merged(): void
    {
        $r = $this->makeRestaurant();
        $real = Customer::create(['store_id' => $r->id, 'name' => 'Real', 'phone' => '+15559999999', 'email' => 'real@test.com', 'password' => bcrypt('secret-123')]);
        $realOrder = Order::create(['store_id' => $r->id, 'customer_id' => $real->id, 'customer_name' => 'Real']);

        $this->verify($r, '+15559999999')->assertOk();

        // The real account and its order are untouched, still passworded.
        $this->assertDatabaseHas('customers', ['id' => $real->id]);
        $this->assertNotEmpty($real->fresh()->password);
        $this->assertSame($real->id, $realOrder->fresh()->customer_id);
    }

    /** ATTACKER: a shadow with the same phone at ANOTHER restaurant is not touched. */
    public function test_other_restaurant_shadow_not_merged(): void
    {
        $r = $this->makeRestaurant();
        $r2 = $this->makeRestaurant();
        $foreign = Customer::create(['store_id' => $r2->id, 'name' => 'Foreign', 'phone' => '+15551234567', 'source' => 'checkout']);
        $foreignOrder = Order::create(['store_id' => $r2->id, 'customer_id' => $foreign->id, 'customer_name' => 'Foreign']);

        $this->verify($r, '+15551234567')->assertOk();

        $this->assertDatabaseHas('customers', ['id' => $foreign->id]);
        $this->assertSame($foreign->id, $foreignOrder->fresh()->customer_id);
    }
}
