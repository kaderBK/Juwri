<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreSettingsService;
use Tests\TestCase;

/**
 * P6-108b — a new store takes its country's currency (fiscal profile) instead of the platform default, unless the owner
 * chose one. Stores without the creation marker (every store made before) never change.
 */
class StoreCountryCurrencyDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function store(?string $marker = 'USD', string $currency = 'USD'): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'c-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'C', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($store->id, 'currency_sign', '$');
        if ($marker !== null) {
            StoreOption::updateStoreOption($store->id, StoreSettingsService::CURRENCY_DEFAULT_MARKER, $marker);
        }

        return $store;
    }

    /** @param  array<string, mixed>  $input */
    private function save(Store $store, array $input): Request
    {
        $request = Request::create('/x', 'PUT', $input);
        app(StoreSettingsService::class)->applyCountryCurrencyDefault($store, $request);

        return $request;
    }

    public function test_each_launch_country_gives_its_currency(): void
    {
        foreach (['SA' => 'SAR', 'AE' => 'AED', 'JO' => 'JOD', 'EG' => 'EGP', 'LY' => 'LYD', 'OM' => 'OMR', 'KW' => 'KWD', 'BH' => 'BHD', 'QA' => 'QAR', 'MA' => 'MAD', 'TN' => 'TND', 'DZ' => 'DZD', 'IQ' => 'IQD'] as $cc => $cur) {
            $this->assertSame($cur, StoreSettingsService::countryCurrency($cc), $cc);
            $request = $this->save($this->store(), ['fiscal_country' => $cc, 'currency_code' => 'USD', 'currency_sign' => '$']);
            $this->assertSame($cur, $request->input('currency_code'), $cc);
            $this->assertSame(currency_symbol_for($cur), $request->input('currency_sign'), $cc);
        }
        $this->assertNull(StoreSettingsService::countryCurrency('US'));
        $this->assertNull(StoreSettingsService::countryCurrency(''));
    }

    public function test_the_default_applies_once_then_the_marker_is_gone(): void
    {
        $store = $this->store();
        $this->save($store, ['fiscal_country' => 'EG', 'currency_code' => 'USD']);
        $this->assertSame('', (string) StoreOption::getStoreOption($store->id, StoreSettingsService::CURRENCY_DEFAULT_MARKER));

        StoreOption::updateStoreOption($store->id, 'currency_code', 'EGP');
        $request = $this->save($store, ['fiscal_country' => 'SA', 'currency_code' => 'EGP']);
        $this->assertSame('EGP', $request->input('currency_code'), 'a later country change never moves the currency');
    }

    public function test_a_currency_the_owner_chose_is_kept(): void
    {
        $store = $this->store();
        $request = $this->save($store, ['fiscal_country' => 'EG', 'currency_code' => 'EUR']);

        $this->assertSame('EUR', $request->input('currency_code'));
        $this->assertSame('', (string) StoreOption::getStoreOption($store->id, StoreSettingsService::CURRENCY_DEFAULT_MARKER));
    }

    public function test_no_country_keeps_waiting(): void
    {
        $store = $this->store();
        $request = $this->save($store, ['fiscal_country' => '', 'currency_code' => 'USD']);

        $this->assertSame('USD', $request->input('currency_code'));
        $this->assertSame('USD', (string) StoreOption::getStoreOption($store->id, StoreSettingsService::CURRENCY_DEFAULT_MARKER));
    }

    public function test_an_existing_store_without_the_marker_never_changes(): void
    {
        $request = $this->save($this->store(null), ['fiscal_country' => 'EG', 'currency_code' => 'USD']);

        $this->assertSame('USD', $request->input('currency_code'));
    }

    public function test_a_currency_changed_elsewhere_is_not_overridden(): void
    {
        $store = $this->store('USD', 'AED'); // e.g. the reseller console set AED after creation

        $request = $this->save($store, ['fiscal_country' => 'EG', 'currency_code' => 'AED']);

        $this->assertSame('AED', $request->input('currency_code'));
    }

    public function test_a_store_with_items_keeps_its_currency(): void
    {
        $store = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C', 'active' => 1]);
        DB::table('items')->insert(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'I', 'price_minor' => 100, 'currency_code' => 'USD', 'active' => 1, 'public_id' => (string) Str::uuid7()]);

        $request = $this->save($store, ['fiscal_country' => 'EG', 'currency_code' => 'USD']);

        $this->assertSame('USD', $request->input('currency_code'));
    }
}
