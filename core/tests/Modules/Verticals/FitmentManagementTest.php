<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\VehicleFitment;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane E3 — the fitment management dashboard
 * (FitmentController): add/remove one fitment, bulk-add a model-year range,
 * and the tenancy/vertical-capability fence. A brand-new surface (its own
 * controller/routes/views), never layered onto Lane A's RetailItemController/
 * retail item form — every route here 404s for a non-carparts store exactly
 * like RetailItemController's routes 404 for a restaurant.
 */
class FitmentManagementTest extends TestCase
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

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1]);

        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function makeStore(User $owner, string $type = 'carparts'): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makePart(Store $store, string $name = 'Brake Pad Set'): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'price' => 39.99, 'active' => 1]);
    }

    public function test_the_fitments_landing_page_lists_the_stores_parts(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner);
        $this->makePart($store);

        $this->actingAs($owner)->get(route('retail.fitments.index', $store))
            ->assertOk()->assertSee('Brake Pad Set');
    }

    public function test_owner_can_add_and_remove_a_fitment_via_the_dashboard(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner);
        $part = $this->makePart($store);

        $this->actingAs($owner)->get(route('retail.fitments.show', [$store, $part]))->assertOk();

        $this->actingAs($owner)->post(route('retail.fitments.store', [$store, $part]), [
            'year' => 2018, 'make' => 'Toyota', 'model' => 'Corolla', 'submodel' => 'LE', 'engine' => '1.8L',
        ])->assertRedirect();

        $fitment = VehicleFitment::where('menu_id', $part->id)->first();
        $this->assertNotNull($fitment);
        $this->assertSame(2018, $fitment->year);
        $this->assertSame('Toyota', $fitment->make);

        $this->actingAs($owner)->delete(route('retail.fitments.destroy', [$store, $part, $fitment]))->assertRedirect();
        $this->assertSame(0, VehicleFitment::where('menu_id', $part->id)->count());
    }

    public function test_bulk_add_by_model_range_creates_one_row_per_year_and_is_idempotent(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner);
        $part = $this->makePart($store);

        $this->actingAs($owner)->post(route('retail.fitments.bulkStore', [$store, $part]), [
            'year_from' => 2015, 'year_to' => 2018, 'make' => 'Honda', 'model' => 'Civic',
        ])->assertRedirect();

        $this->assertSame(4, VehicleFitment::where('menu_id', $part->id)->count());
        $this->assertSame([2015, 2016, 2017, 2018], VehicleFitment::where('menu_id', $part->id)->orderBy('year')->pluck('year')->all());

        // Resubmitting an overlapping range never duplicates already-covered years.
        $this->actingAs($owner)->post(route('retail.fitments.bulkStore', [$store, $part]), [
            'year_from' => 2017, 'year_to' => 2019, 'make' => 'Honda', 'model' => 'Civic',
        ])->assertRedirect();

        $this->assertSame(5, VehicleFitment::where('menu_id', $part->id)->count());
    }

    public function test_bulk_add_rejects_a_range_over_sixty_years(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner);
        $part = $this->makePart($store);

        $this->actingAs($owner)->post(route('retail.fitments.bulkStore', [$store, $part]), [
            'year_from' => 1950, 'year_to' => 2020, 'make' => 'Honda', 'model' => 'Civic',
        ])->assertRedirect();

        $this->assertSame(0, VehicleFitment::where('menu_id', $part->id)->count());
    }

    public function test_a_non_carparts_store_cannot_reach_fitment_routes(): void
    {
        $owner = $this->makeUser();
        $retail = $this->makeStore($owner, 'retail');
        $part = $this->makePart($retail, 'Face Cream');

        $this->actingAs($owner)->get(route('retail.fitments.index', $retail))->assertNotFound();
        $this->actingAs($owner)->get(route('retail.fitments.show', [$retail, $part]))->assertNotFound();
        $this->actingAs($owner)->post(route('retail.fitments.store', [$retail, $part]), [
            'year' => 2020, 'make' => 'X', 'model' => 'Y',
        ])->assertNotFound();
    }

    public function test_a_restaurant_cannot_reach_fitment_routes(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'restaurant');

        $this->actingAs($owner)->get(route('retail.fitments.index', $store))->assertNotFound();
    }

    public function test_a_stranger_cannot_manage_another_owners_fitments(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $store = $this->makeStore($owner);
        $part = $this->makePart($store);

        $this->actingAs($stranger)->get(route('retail.fitments.show', [$store, $part]))->assertNotFound();
    }

    public function test_deleting_a_fitment_belonging_to_another_item_404s(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner);
        $partA = $this->makePart($store, 'Part A');
        $partB = $this->makePart($store, 'Part B');
        $fitment = VehicleFitment::create(['menu_id' => $partA->id, 'store_id' => $store->id, 'year' => 2020, 'make' => 'X', 'model' => 'Y']);

        $this->actingAs($owner)->delete(route('retail.fitments.destroy', [$store, $partB, $fitment]))->assertNotFound();
        $this->assertSame(1, VehicleFitment::where('menu_id', $partA->id)->count());
    }
}
