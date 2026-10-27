<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner-defined allergens: an owner can add their own allergens (store_id
 * set) on top of the global (superadmin, store_id null) catalog, and the
 * availableFor scope returns global + own only.
 */
class OwnerAllergenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([
            Authenticate::class, EnsureStaffDashboardPermission::class,
            Installed::class, CheckUserIsBanned::class, Locale::class,
        ]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create(['user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1']);
    }

    public function test_owner_creates_allergen_scoped_to_restaurant(): void
    {
        $global = Allergy::create(['title' => 'Peanuts', 'active' => '1', 'position' => 1]); // store_id null
        $r = $this->makeRestaurant();

        $this->actingAs($r->user)->postJson(route('stores.storeAllergen', $r->id), ['title' => 'Sesame'])
            ->assertJson(['success' => true, 'title' => 'Sesame']);

        $own = Allergy::where('store_id', $r->id)->first();
        $this->assertSame('Sesame', $own->title);

        // availableFor returns global + own
        $ids = Allergy::availableFor($r->id)->pluck('id');
        $this->assertContains($global->id, $ids);
        $this->assertContains($own->id, $ids);
    }

    public function test_owner_allergen_not_visible_to_other_restaurant(): void
    {
        $r1 = $this->makeRestaurant();
        $r2 = $this->makeRestaurant();
        $this->actingAs($r1->user)->postJson(route('stores.storeAllergen', $r1->id), ['title' => 'Mustard'])->assertOk();

        $r1Own = Allergy::where('store_id', $r1->id)->first();
        $this->assertNotContains($r1Own->id, Allergy::availableFor($r2->id)->pluck('id'), 'r1 allergen hidden from r2');
    }

    public function test_duplicate_title_reuses_existing(): void
    {
        $r = $this->makeRestaurant();
        $this->actingAs($r->user)->postJson(route('stores.storeAllergen', $r->id), ['title' => 'Soy'])->assertOk();
        $this->actingAs($r->user)->postJson(route('stores.storeAllergen', $r->id), ['title' => 'soy'])
            ->assertJson(['success' => true, 'existing' => true]);

        $this->assertSame(1, Allergy::where('store_id', $r->id)->count());
    }

    public function test_foreign_owner_rejected(): void
    {
        $r = $this->makeRestaurant();
        $other = $this->makeRestaurant();
        $this->actingAs($other->user)->postJson(route('stores.storeAllergen', $r->id), ['title' => 'Hack'])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame(0, Allergy::where('store_id', $r->id)->count());
    }

    public function test_owner_deletes_allergen_and_strips_it_from_items(): void
    {
        $r = $this->makeRestaurant();
        $own = Allergy::create(['store_id' => $r->id, 'title' => 'Lupin', 'active' => '1', 'position' => 1]);
        // Other ids that can never equal $own->id (hard-coded 3/7 collided when $own got id 7 on MySQL/PG).
        [$a, $b] = [$own->id + 1000, $own->id + 2000];
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'D', 'price' => 1, 'type' => 'veg', 'allergies' => $a.','.$own->id.','.$b]);

        $this->actingAs($r->user)->deleteJson(route('stores.destroyAllergen', [$r->id, $own->id]))
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('allergies', ['id' => $own->id]);
        $this->assertSame($a.','.$b, $item->fresh()->allergies); // id stripped, others kept
    }

    public function test_cannot_delete_global_allergen(): void
    {
        $global = Allergy::create(['title' => 'Wheat', 'active' => '1', 'position' => 1]); // store_id null
        $r = $this->makeRestaurant();

        $this->actingAs($r->user)->deleteJson(route('stores.destroyAllergen', [$r->id, $global->id]))
            ->assertStatus(404);
        $this->assertDatabaseHas('allergies', ['id' => $global->id]);
    }

    public function test_cannot_delete_other_restaurants_allergen(): void
    {
        $r1 = $this->makeRestaurant();
        $r2 = $this->makeRestaurant();
        $a = Allergy::create(['store_id' => $r1->id, 'title' => 'Celery', 'active' => '1', 'position' => 1]);

        $this->actingAs($r2->user)->deleteJson(route('stores.destroyAllergen', [$r2->id, $a->id]))
            ->assertStatus(404); // not in r2's scope
        $this->assertDatabaseHas('allergies', ['id' => $a->id]);
    }
}
