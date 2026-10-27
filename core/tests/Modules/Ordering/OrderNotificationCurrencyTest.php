<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Tests\TestCase;

/**
 * MC-21 — off-storefront money rendering (POS receipt, guest order-lookup) uses
 * the STORE's currency decimals, so 3-decimal currencies keep their fils instead
 * of the old hardcoded 2dp / platform-currency leak.
 */
class OrderNotificationCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin LTR: the API `total` uses store_money_plain, whose order is locale-sensitive
        // (RTL flips to number-first). This test pins the sign+decimals, not RTL position.
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_escpos_receipt_keeps_fils_on_three_decimal_currency(): void
    {
        $renderer = new EscPosRenderer;
        $money = new \ReflectionMethod($renderer, 'money');
        $money->setAccessible(true);

        $this->assertSame('1.234 OMR', $money->invoke($renderer, 1.234, 'OMR')); // fils kept
        $this->assertSame('1.23 USD', $money->invoke($renderer, 1.234, 'USD'));  // 2dp unchanged
    }

    public function test_guest_order_lookup_total_uses_store_decimals(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Shop', 'sub_title' => 'Sub', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'OMR');
        StoreOption::updateStoreOption($store->id, 'currency_sign', 'R');
        StoreOption::updateStoreOption($store->id, 'currency_pos', '1');

        Order::create([
            'store_id' => $store->id, 'phone_number' => '0912345678',
            'status' => 'pending', 'type' => 'takeaway', 'track_token' => 'TOK3DEC', 'created_at' => now(),
        ]);

        $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => '0912345678'])
            ->assertOk()
            ->assertJsonPath('orders.0.total', 'R 0.000'); // 3-decimal store → fils shown (store_money_plain: sign + space)
    }
}
