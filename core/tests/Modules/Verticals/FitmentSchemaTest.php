<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Models\VehicleFitment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane E1 — the `vehicle_fitments` schema (DECISIONS A8):
 * a part (`menu` row) can fit MANY vehicles. Additive-only table, so it
 * carries no risk to any other vertical/table.
 */
class FitmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function makeStore(string $type = 'carparts'): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_attach_three_fitments_to_a_part(): void
    {
        $store = $this->makeStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);
        $part = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set', 'price' => 39.99, 'active' => 1]);

        foreach ([2014, 2015, 2016] as $year) {
            VehicleFitment::create([
                'menu_id' => $part->id, 'store_id' => $store->id,
                'year' => $year, 'make' => 'Honda', 'model' => 'Civic',
            ]);
        }

        $this->assertSame(3, $part->fitments()->count());
        $this->assertSame([2014, 2015, 2016], $part->fitments()->pluck('year')->all());
    }

    public function test_a_fitment_carries_submodel_engine_and_notes(): void
    {
        $store = $this->makeStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);
        $part = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set', 'price' => 39.99, 'active' => 1]);

        $fitment = VehicleFitment::create([
            'menu_id' => $part->id, 'store_id' => $store->id,
            'year' => 2020, 'make' => 'Toyota', 'model' => 'Corolla',
            'submodel' => 'LE', 'engine' => '1.8L', 'notes' => 'Front axle only',
        ]);

        $this->assertSame('2020 Toyota Corolla LE (1.8L)', $fitment->label);
        $this->assertSame('Front axle only', $fitment->fresh()->notes);
    }

    public function test_a_part_with_no_fitments_has_an_empty_relation(): void
    {
        $store = $this->makeStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);
        $part = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Rotor', 'price' => 20, 'active' => 1]);

        $this->assertSame(0, $part->fitments()->count());
    }
}
