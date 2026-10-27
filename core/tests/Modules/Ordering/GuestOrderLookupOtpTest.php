<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Services\SmsService;
use Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController;
use Tests\TestCase;

/**
 * S4 — guest order-lookup OTP gate (dark). With the flag off the lookup returns
 * orders as before; with it on, a caller must have SMS-OTP-verified the phone
 * this session before any order data is returned.
 */
class GuestOrderLookupOtpTest extends TestCase
{
    use RefreshDatabase;

    private string $phone = '+96599112233';

    private function storeWithOrder(): Store
    {
        $owner = User::create([
            'name' => 'O', 'email' => Str::lower(Str::random(8)).'@t.dev', 'password' => bcrypt('x'),
        ]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Order::create([
            'store_id' => $store->id, 'track_token' => Str::random(12),
            'phone_number' => $this->phone, 'is_paid' => 1, 'status' => 'completed',
            'type' => 'takeaway', 'created_at' => now(),
        ]);

        return $store;
    }

    private function lookup(Store $store): TestResponse
    {
        return $this->withoutMiddleware([Installed::class])
            ->postJson(route('store.orderLookup', $store->slug), ['identifier' => $this->phone]);
    }

    public function test_flag_off_returns_orders(): void
    {
        config(['settings.order_lookup_require_otp' => false]);
        $store = $this->storeWithOrder();

        $this->lookup($store)->assertOk()->assertJson(['success' => true]);
    }

    public function test_flag_on_unverified_returns_otp_required(): void
    {
        config(['settings.order_lookup_require_otp' => true]);
        $store = $this->storeWithOrder();

        $res = $this->lookup($store)->assertOk();
        $res->assertJson(['success' => false, 'otp_required' => true]);
        $this->assertNull($res->json('orders'), 'no order data may leak when OTP is required');
    }

    public function test_flag_on_verified_phone_returns_orders(): void
    {
        config(['settings.order_lookup_require_otp' => true]);
        $store = $this->storeWithOrder();

        $normalized = app(SmsService::class)->normalizePhone($this->phone);
        $this->withSession([CheckoutOtpController::SESSION_KEY => [$normalized => [(int) $store->id => time()]]]);

        $this->lookup($store)->assertOk()->assertJson(['success' => true]);
    }
}
