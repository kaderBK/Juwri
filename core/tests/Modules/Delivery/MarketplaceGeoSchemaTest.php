<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use Juwri\Core\Models\City;
use Juwri\Core\Models\Country;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marketplace P0-1: geo schema, factories, relations, zone containment.
 */
class MarketplaceGeoSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_city_zone_relations(): void
    {
        $country = Country::factory()->create(['mk_active' => true]);
        $city = City::factory()->create(['country_id' => $country->id]);
        $zone = Zone::factory()->create(['city_id' => $city->id]);

        $this->assertTrue($country->cities->contains($city));
        $this->assertTrue($city->zones->contains($zone));
        $this->assertSame($country->id, $city->country->id);
        $this->assertSame($city->id, $zone->city->id);
    }

    public function test_country_marketplace_aliases_and_scope(): void
    {
        $on = Country::factory()->create(['code' => 'SA', 'currency' => 'SAR', 'phone' => '+966', 'mk_active' => true]);
        Country::factory()->create(['mk_active' => false]);

        $this->assertSame('SA', $on->iso2);
        $this->assertSame('SAR', $on->currency_code);
        $this->assertSame('+966', $on->phone_prefix);
        $this->assertTrue(Country::mkActive()->pluck('id')->contains($on->id));
        $this->assertCount(1, Country::mkActive()->whereIn('id', Country::query()->latest('id')->limit(2)->pluck('id'))->get());
    }

    public function test_zone_contains_point_via_stored_polygon(): void
    {
        $zone = Zone::factory()->create([
            'polygon' => [[24.0, 46.0], [25.0, 46.0], [25.0, 47.0], [24.0, 47.0]],
        ]);

        $fresh = Zone::find($zone->id);
        $this->assertTrue($fresh->containsPoint(24.5, 46.5));
        $this->assertFalse($fresh->containsPoint(26.0, 46.5));
    }

    public function test_zone_scope_active(): void
    {
        $active = Zone::factory()->create(['active' => true]);
        $inactive = Zone::factory()->create(['active' => false]);

        $ids = Zone::active()->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }
}
