<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Models\VehicleFitment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/multi-vertical — the torque (car-parts) theme's "Scan your VIN" endpoint.
 * Delegates to the bound VinDecodeProvider (mock today). Contract: enabled:false
 * (200, not 404) for a non-fitment store so a stray call degrades quietly; a
 * blank VIN is 422; a resolvable VIN returns a vehicle the store stocks parts for.
 */
class VinDecodeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function carPartsStore(): Store
    {
        $owner = User::factory()->create();

        return Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'carparts']);
    }

    public function test_decode_resolves_to_a_vehicle_the_store_stocks_parts_for(): void
    {
        $store = $this->carPartsStore();
        $part = Menu::create(['store_id' => $store->id, 'name' => 'Brake pad', 'price' => 40, 'active' => 1]);
        VehicleFitment::create([
            'menu_id' => $part->id, 'store_id' => $store->id, 'year' => 2020, 'make' => 'Toyota', 'model' => 'Corolla', 'engine' => '1.8L',
        ]);

        $res = $this->postJson(route('vin.decode', ['slug' => $store->slug]), ['vin' => 'JTDBR32E120123456']);

        $res->assertOk()->assertJson([
            'enabled' => true,
            'found' => true,
            'vehicle' => ['year' => 2020, 'make' => 'Toyota', 'model' => 'Corolla', 'engine' => '1.8L'],
        ]);
    }

    public function test_decode_is_deterministic_for_the_same_vin(): void
    {
        $store = $this->carPartsStore();
        $part = Menu::create(['store_id' => $store->id, 'name' => 'Oil filter', 'price' => 15, 'active' => 1]);
        VehicleFitment::create(['menu_id' => $part->id, 'store_id' => $store->id, 'year' => 2018, 'make' => 'Honda', 'model' => 'Civic']);
        VehicleFitment::create(['menu_id' => $part->id, 'store_id' => $store->id, 'year' => 2021, 'make' => 'Mazda', 'model' => 'CX-5']);

        $vin = 'JHMFA16576S000111';
        $first = $this->postJson(route('vin.decode', ['slug' => $store->slug]), ['vin' => $vin])->json('vehicle');
        $second = $this->postJson(route('vin.decode', ['slug' => $store->slug]), ['vin' => $vin])->json('vehicle');

        $this->assertSame($first, $second);
    }

    public function test_a_blank_vin_is_422_but_still_enabled(): void
    {
        $store = $this->carPartsStore();

        $res = $this->postJson(route('vin.decode', ['slug' => $store->slug]), ['vin' => '   ']);

        $res->assertStatus(422)->assertJson(['enabled' => true, 'found' => false, 'vehicle' => null]);
    }

    public function test_a_non_carparts_store_degrades_to_enabled_false_200(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);

        $res = $this->postJson(route('vin.decode', ['slug' => $store->slug]), ['vin' => 'JTDBR32E120123456']);

        $res->assertOk()->assertJson(['enabled' => false, 'found' => false, 'vehicle' => null]);
    }

    public function test_decode_404s_for_an_unknown_store(): void
    {
        $this->postJson(route('vin.decode', ['slug' => 'no-such-store']), ['vin' => 'JTDBR32E120123456'])->assertNotFound();
    }
}
