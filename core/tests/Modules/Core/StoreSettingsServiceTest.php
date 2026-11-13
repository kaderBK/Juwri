<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreSettingsService;
use Tests\TestCase;

/**
 * F0-1 (reseller-store): the shared store-settings write-seam.
 *
 * Pins the two behaviours the reseller Store console will lean on: the generic
 * option writer persists a post_option, and the currency-change guard blocks an
 * unconfirmed change on a store that already has orders (MC-12) while allowing
 * it once confirmed or when the store has no orders.
 */
class StoreSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    public function test_apply_options_writes_a_post_option(): void
    {
        $store = $this->store();
        $svc = app(StoreSettingsService::class);

        $svc->applyOptions($store, ['min_order_value' => '25']);

        $this->assertEquals('25', StoreOption::getStoreOption($store->id, 'min_order_value'));
    }

    public function test_apply_options_normalizes_sar_currency_sign(): void
    {
        $store = $this->store();
        $svc = app(StoreSettingsService::class);

        // A wrong riyal codepoint (U+20C0) is rewritten to canonical U+20C1 when
        // the currency in the same payload is SAR.
        $svc->applyOptions($store, ['currency_code' => 'SAR', 'currency_sign' => "\u{20C0}"]);

        $this->assertSame("\u{20C1}", StoreOption::getStoreOption($store->id, 'currency_sign'));
    }

    public function test_currency_guard_blocks_unconfirmed_change_when_orders_exist(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);

        $svc = app(StoreSettingsService::class);
        $error = $svc->guardCurrencyChange($store, ['currency_code' => 'EUR']);

        $this->assertNotNull($error);
    }

    public function test_currency_guard_allows_confirmed_change_when_orders_exist(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);

        $svc = app(StoreSettingsService::class);
        $error = $svc->guardCurrencyChange($store, [
            'currency_code' => 'EUR',
            'confirm_currency_change' => '1',
        ]);

        $this->assertNull($error);
    }

    public function test_currency_guard_allows_change_when_no_orders(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        $svc = app(StoreSettingsService::class);
        $error = $svc->guardCurrencyChange($store, ['currency_code' => 'EUR']);

        $this->assertNull($error);
    }
}
