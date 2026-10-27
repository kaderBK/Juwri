<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Storefront\DisplayRates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * MC-33 — the merchant can pick the display-rate source (manual/auto) and the
 * auto-mode currency list on the store settings screen; it persists, validates,
 * and drives DisplayRates.
 */
class StoreCurrencyRatesModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Store', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        return [$owner, $store];
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'color' => '#111', 'title' => 'Store', 'description' => 't', 'address' => '1 St',
            'store_template' => 'aurora', 'currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => '1',
        ];
    }

    private function opt(Store $store, string $key): ?string
    {
        return StoreSettings::legacy((int) $store->id, $key);
    }

    public function test_saves_mode_and_display_currencies_and_drives_display_rates(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->put(route('stores.update', $store), $this->payload([
            'currency_rates_mode' => 'auto',
            'display_currencies' => 'EUR, GBP',
        ]));

        $this->assertSame('auto', $this->opt($store, 'currency_rates_mode'));
        $this->assertSame('EUR, GBP', $this->opt($store, 'display_currencies'));
        $this->assertTrue(DisplayRates::forStore($store->id)->indicative, 'auto mode drives DisplayRates');
    }

    public function test_rejects_invalid_display_currencies(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->put(route('stores.update', $store), $this->payload([
            'currency_rates_mode' => 'auto',
            'display_currencies' => 'EU, XYZW',   // not 3-letter ISO tokens
        ]))->assertRedirect();

        $this->assertNull($this->opt($store, 'display_currencies'), 'invalid value must not persist');
    }

    public function test_rejects_invalid_mode(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->put(route('stores.update', $store), $this->payload([
            'currency_rates_mode' => 'sideways',
        ]))->assertRedirect();

        $this->assertNull($this->opt($store, 'currency_rates_mode'));
    }
}
