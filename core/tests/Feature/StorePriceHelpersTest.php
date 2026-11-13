<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * MC-20 — the store-scoped price helpers format in the STORE's own currency
 * (read from store_options), independent of the platform config, so off-storefront
 * contexts (push/SMS/WhatsApp/POS) stop leaking the platform currency.
 */
class StorePriceHelpersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin LTR: these assertions pin sign/decimals, not RTL position. currency_pos_effective()
        // is locale-sensitive (RTL flips non-USD to number-first), so a leaked 'ar' would flip them.
        app()->setLocale('en');
    }

    private function store(array $opts): int
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        foreach ($opts as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store->id;
    }

    public function test_store_helpers_use_the_stores_currency_not_the_platform(): void
    {
        // Platform config is USD; the store is OMR (3-decimal), symbol before price.
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$', 'settings.currency_pos' => 1]);

        $omr = $this->store(['currency_code' => 'OMR', 'currency_sign' => 'R.O ', 'currency_pos' => 1]);

        $this->assertSame('OMR', store_currency($omr));
        $this->assertSame('1.234', store_price_format(1.234, $omr));           // fils kept
        $this->assertSame('R.O 1.234', store_price_symbol(1.234, $omr));       // sign before
        $this->assertSame('OMR 1.234', store_price_code(1.234, $omr));         // code before
    }

    public function test_position_after_price(): void
    {
        // LTR: the store's currency_pos flag is honored verbatim (in RTL it is
        // overridden to sign-first — that is CurrencyPositionRtlTest's job).
        app()->setLocale('en');
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$', 'settings.currency_pos' => 1]);
        $usd = $this->store(['currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => 0]);

        $this->assertSame('10.00$', store_price_symbol(10, $usd));
        $this->assertSame('10.00 USD', store_price_code(10, $usd));
    }

    public function test_two_stores_do_not_leak_into_each_other(): void
    {
        config(['settings.currency_code' => 'USD']);
        $omr = $this->store(['currency_code' => 'OMR']);
        $usd = $this->store(['currency_code' => 'USD']);

        $this->assertSame('1.234', store_price_format(1.234, $omr)); // 3 decimals
        $this->assertSame('1.23', store_price_format(1.234, $usd));  // 2 decimals
        // Re-read the first store after the second — memo must not have leaked.
        $this->assertSame('OMR', store_currency($omr));
    }

    public function test_falls_back_to_platform_currency_when_store_has_none(): void
    {
        config(['settings.currency_code' => 'GBP', 'settings.currency_sign' => '£', 'settings.currency_pos' => 1]);
        $bare = $this->store([]); // no currency options

        $this->assertSame('GBP', store_currency($bare));
        $this->assertSame('£5.00', store_price_symbol(5, $bare));
    }
}
