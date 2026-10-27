<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\ExchangeRate;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\ExchangeRateService;
use Tests\TestCase;

/**
 * C9 — per-order FX stamp. Each order freezes its currency→platform-base
 * display-FX rate at creation (`orders.fx_rate_base`); cross-currency reporting
 * (`Order::revenueInBase`) prefers that frozen rate and falls back to the live
 * rate for legacy / feed-empty NULL rows. FX is seeded — no live HTTP.
 */
class OrderFxStampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic platform base for the crossing.
        config(['settings.currency_code' => 'USD']);
        ExchangeRateService::flush();
    }

    private function storeFixture(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $user->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Seed base=USD → quote rate (1 USD = $rate quote). */
    private function seedRate(string $quote, float $rate): void
    {
        ExchangeRate::updateOrCreate(
            ['base' => 'USD', 'quote' => strtoupper($quote), 'provider' => ExchangeRateService::PROVIDER],
            ['rate' => $rate, 'fetched_at' => now()]
        );
        ExchangeRateService::flush();
    }

    private function order(Store $r, string $currency, float $grandTotal, bool $paid = true): Order
    {
        return Order::create([
            'store_id' => $r->id,
            'track_token' => Str::random(12),
            'currency_code' => $currency,
            'grand_total' => $grandTotal,
            'is_paid' => $paid ? 1 : 0,
            'status' => 'pending',
            'type' => 'takeaway',
            'created_at' => now(),
        ]);
    }

    /** Stamp on create: a foreign order freezes the live rate at placement time. */
    public function test_stamps_fx_rate_on_create(): void
    {
        $this->seedRate('EUR', 0.90); // 1 USD = 0.90 EUR → 1 EUR = 1.1111.. USD
        $r = $this->storeFixture();

        $order = $this->order($r, 'EUR', 100);

        $this->assertNotNull($order->fx_rate_base);
        $this->assertEqualsWithDelta(1.11111111, (float) $order->fx_rate_base, 0.0001);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    /** A base-currency order stamps exactly 1.0 (no crossing needed). */
    public function test_same_currency_stamps_one(): void
    {
        $r = $this->storeFixture();

        $order = $this->order($r, 'USD', 100); // feed empty, but from===to short-circuits

        $this->assertSame(1.0, (float) $order->fx_rate_base);
    }

    /** Null-safe: with an empty feed a foreign order keeps NULL — no throw. */
    public function test_stamp_is_null_when_feed_empty(): void
    {
        // No exchange_rates rows seeded.
        $r = $this->storeFixture();

        $order = $this->order($r, 'EUR', 100);

        $this->assertNull($order->fx_rate_base);
    }

    /** revenueInBase prefers the frozen stamp even after the live feed moves. */
    public function test_revenue_in_base_prefers_stamp_over_live(): void
    {
        $this->seedRate('EUR', 0.90); // stamps 1.1111
        $r = $this->storeFixture();
        $this->order($r, 'EUR', 100);  // fx_rate_base ≈ 1.1111 frozen

        // Feed moves: EUR→USD would now be 2.0 if re-crossed live.
        $this->seedRate('EUR', 0.50);

        $base = Order::revenueInBase(Order::query());

        // Frozen rate wins: 100 × 1.1111 ≈ 111.11, NOT the live 200.
        $this->assertEqualsWithDelta(111.11, $base, 0.05);
        $this->assertLessThan(150, $base);
    }

    /** revenueInBase converts NULL-stamp (legacy / feed-down) rows at the live rate. */
    public function test_revenue_in_base_falls_back_to_live_for_null(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 'EUR', 100); // feed empty at create → NULL stamp
        $this->assertNull($order->fresh()->fx_rate_base);

        // Feed comes up later: 1 EUR = 2.0 USD.
        $this->seedRate('EUR', 0.50);

        $base = Order::revenueInBase(Order::query());

        $this->assertEqualsWithDelta(200.0, $base, 0.05);
    }
}
