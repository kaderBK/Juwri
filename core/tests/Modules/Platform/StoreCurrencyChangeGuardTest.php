<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * MC-12 — a store that already has orders must explicitly confirm a currency
 * change (it converts nothing) and the change is audit-logged. Stores with no
 * orders change freely.
 */
class StoreCurrencyChangeGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $currency): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Store', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$owner, $store];
    }

    private function payload(Store $store, string $currency, bool $confirm = false): array
    {
        return array_filter([
            'color' => '#111', 'title' => $store->title, 'description' => $store->description,
            'address' => $store->address, 'store_template' => 'aurora',
            'currency_code' => $currency, 'currency_sign' => '$', 'currency_pos' => '1',
            'confirm_currency_change' => $confirm ? '1' : null,
        ], fn ($v) => $v !== null);
    }

    private function order(Store $store): void
    {
        Order::create([
            'store_id' => $store->id, 'status' => 'pending', 'type' => 'takeaway',
            'is_paid' => 0, 'track_token' => Str::random(12),
        ]);
    }

    private function storedCurrency(Store $store): ?string
    {
        return StoreSettings::legacy((int) $store->id, 'currency_code');
    }

    public function test_blocks_currency_change_without_confirm_when_orders_exist(): void
    {
        [$owner, $store] = $this->store('USD');
        $this->order($store);

        $this->actingAs($owner)
            ->put(route('stores.update', $store), $this->payload($store, 'EUR'))
            ->assertRedirect();

        $this->assertSame('USD', $this->storedCurrency($store), 'currency must NOT change without confirmation');
    }

    public function test_allows_with_confirm_and_writes_audit_log(): void
    {
        [$owner, $store] = $this->store('USD');
        $this->order($store);

        $this->actingAs($owner)
            ->put(route('stores.update', $store), $this->payload($store, 'EUR', confirm: true));

        $this->assertSame('EUR', $this->storedCurrency($store));
        $this->assertDatabaseHas('activity_logs', [
            'store_id' => $store->id,
            'action' => 'store.currency_changed',
        ]);
        $log = ActivityLog::where('action', 'store.currency_changed')->first();
        $this->assertSame('USD', $log->meta['from']);
        $this->assertSame('EUR', $log->meta['to']);
    }

    public function test_allows_change_without_confirm_when_no_orders(): void
    {
        [$owner, $store] = $this->store('USD');

        $this->actingAs($owner)
            ->put(route('stores.update', $store), $this->payload($store, 'EUR'));

        $this->assertSame('EUR', $this->storedCurrency($store), 'a store with no orders changes freely');
    }
}
