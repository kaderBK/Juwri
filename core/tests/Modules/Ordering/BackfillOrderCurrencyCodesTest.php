<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * MC-11 — the order-currency backfill stamps every legacy (null) order with its
 * store's currency, falls back to the platform currency, is idempotent, and
 * leaves zero nulls when a currency is resolvable.
 */
class BackfillOrderCurrencyCodesTest extends TestCase
{
    use RefreshDatabase;

    private function store(?string $currency): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        if ($currency !== null) {
            StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        }

        return $store;
    }

    /** Create a valid order, then blank its currency to simulate a pre-MC-10 row. */
    private function legacyOrder(Store $store): int
    {
        $order = Order::create([
            'store_id' => $store->id, 'status' => 'pending', 'type' => 'takeaway',
            'is_paid' => 0, 'track_token' => Str::random(12),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['currency_code' => null]);

        return $order->id;
    }

    public function test_backfills_from_store_currency_and_platform_fallback(): void
    {
        config(['settings.currency_code' => 'USD']);

        $omr = $this->store('OMR');
        $a = $this->legacyOrder($omr);
        $b = $this->legacyOrder($omr);

        $noCurrency = $this->store(null); // no store currency → platform fallback
        $c = $this->legacyOrder($noCurrency);

        $this->artisan('currency:backfill-order-codes')->assertSuccessful();

        $this->assertSame(0, DB::table('orders')->whereNull('currency_code')->count());
        $this->assertSame('OMR', DB::table('orders')->where('id', $a)->value('currency_code'));
        $this->assertSame('OMR', DB::table('orders')->where('id', $b)->value('currency_code'));
        $this->assertSame('USD', DB::table('orders')->where('id', $c)->value('currency_code'));
    }

    public function test_is_idempotent(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store('EUR');
        $this->legacyOrder($store);

        $this->artisan('currency:backfill-order-codes')->assertSuccessful();
        // Second run finds nothing to do.
        $this->artisan('currency:backfill-order-codes')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('orders')->whereNull('currency_code')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store('GBP');
        $id = $this->legacyOrder($store);

        $this->artisan('currency:backfill-order-codes --dry')->assertSuccessful();

        $this->assertNull(DB::table('orders')->where('id', $id)->value('currency_code'));
        $this->assertSame(1, DB::table('orders')->whereNull('currency_code')->count());
    }
}
