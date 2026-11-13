<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Maps\MapsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * theme-pack-blade F4: nearest-branch geo feed + Haversine sort.
 */
class BranchGeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    }

    private function makeRestaurant(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function branch(Store $r, string $name, ?float $lat, ?float $lng, bool $active = true, int $pos = 0): Branch
    {
        return Branch::create([
            'store_id' => $r->id, 'name' => $name, 'address' => $name . ' Rd',
            'active' => $active, 'position' => $pos, 'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    public function test_returns_only_active_branches_with_coords(): void
    {
        $r = $this->makeRestaurant();
        // A default "Main" branch is auto-created; remove it for a clean assertion.
        Branch::where('store_id', $r->id)->delete();

        $this->branch($r, 'Alpha', 24.7, 46.7, true, 1);
        $this->branch($r, 'Hidden', 24.8, 46.8, false, 2);

        $res = $this->getJson("/r/{$r->slug}/branches/geo")->assertOk();

        $names = collect($res->json('branches'))->pluck('name')->all();
        $this->assertContains('Alpha', $names);
        $this->assertNotContains('Hidden', $names);
        $this->assertEquals(24.7, $res->json('branches.0.latitude'));
    }

    public function test_haversine_sorts_nearest_first(): void
    {
        $r = $this->makeRestaurant();
        Branch::where('store_id', $r->id)->delete();

        // Customer near Riyadh (24.71, 46.67).
        $this->branch($r, 'Far',  26.42, 50.09, true, 1); // Dammam (~400km)
        $this->branch($r, 'Near', 24.72, 46.68, true, 2); // next door

        $res = $this->getJson("/r/{$r->slug}/branches/geo?lat=24.71&lng=46.67")->assertOk();

        $this->assertEquals('Near', $res->json('branches.0.name'));
        $this->assertEquals('Far', $res->json('branches.1.name'));
        $this->assertLessThan(
            $res->json('branches.1.distance_km'),
            $res->json('branches.0.distance_km')
        );
    }

    public function test_branches_without_coords_sink_to_bottom(): void
    {
        $r = $this->makeRestaurant();
        Branch::where('store_id', $r->id)->delete();

        $this->branch($r, 'NoCoords', null, null, true, 1);
        $this->branch($r, 'HasCoords', 24.72, 46.68, true, 2);

        $res = $this->getJson("/r/{$r->slug}/branches/geo?lat=24.71&lng=46.67")->assertOk();

        $this->assertEquals('HasCoords', $res->json('branches.0.name'));
        $this->assertNull($res->json('branches.1.distance_km'));
    }

    public function test_no_customer_position_omits_distance(): void
    {
        $r = $this->makeRestaurant();
        Branch::where('store_id', $r->id)->delete();
        $this->branch($r, 'Solo', 24.7, 46.7, true, 1);

        $res = $this->getJson("/r/{$r->slug}/branches/geo")->assertOk();
        $this->assertNull($res->json('branches.0.distance_km'));
    }

    public function test_haversine_helper_math(): void
    {
        // Riyadh → Dammam is roughly 380–400 km.
        $km = MapsManager::haversineKm(24.7136, 46.6753, 26.4207, 50.0888);
        $this->assertGreaterThan(300, $km);
        $this->assertLessThan(450, $km);
    }
}
