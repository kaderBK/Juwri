<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane V — INTEGRATION FIX 2: RetailItemController's item
 * form must MERGE its own custom attr_key[]/attr_value[] rows into
 * `menu.attributes` rather than replacing the whole JSON column.
 *
 * Before this fix, RetailItemController::validated() built `attributes`
 * from scratch out of whatever attr_key[]/attr_value[] rows the generic
 * retail form posted, then RetailItemController::update() wrote that as the
 * item's ENTIRE `attributes` column. Since the form has no idea about
 * vertical-specific keys another surface writes into that SAME shared
 * `menu.attributes` seam (A1) — e.g. Lane E's `oem_numbers`/`cross_refs`
 * (car-parts fitment) — saving an unrelated custom attribute through this
 * form silently wiped them (see PROGRESS.md's Lane E integration note and
 * WORK-ITEMS.md's "attributes merge-not-replace" fix).
 */
class RetailAttributesMergeNotReplaceTest extends TestCase
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
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function makeCarPartsStore(User $owner): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => 'carparts', 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_editing_an_unrelated_attribute_via_the_retail_form_preserves_oem_numbers(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeCarPartsStore($owner);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);
        $part = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set', 'price' => 39.99, 'active' => 1]);

        // Set oem_numbers/cross_refs via Lane E's own manager — mirrors
        // FitmentOemTest's save.
        $this->actingAs($owner)->post(route('retail.fitments.updateOem', [$store, $part]), [
            'oem_numbers' => "ABC-123\nDEF456",
            'cross_refs' => 'XYZ-999',
        ])->assertRedirect();
        $this->assertSame(['ABC-123', 'DEF456'], $part->fresh()->attributes['oem_numbers']);

        // Now edit a COMPLETELY UNRELATED custom attribute through
        // RetailItemController's own generic item form — it knows nothing
        // about oem_numbers/cross_refs.
        $this->actingAs($owner)->put(route('retail.products.update', [$store, $part]), [
            'name' => 'Brake Pad Set',
            'price' => 39.99,
            'attr_key' => ['material'],
            'attr_value' => ['ceramic'],
        ])->assertRedirect();

        $fresh = $part->fresh();
        $this->assertSame('ceramic', $fresh->attributes['material'] ?? null, 'the newly submitted attribute should be saved');
        $this->assertSame(['ABC-123', 'DEF456'], $fresh->attributes['oem_numbers'] ?? null, 'oem_numbers must survive an unrelated attribute edit');
        $this->assertSame(['XYZ-999'], $fresh->attributes['cross_refs'] ?? null, 'cross_refs must survive an unrelated attribute edit');
    }

    public function test_re_saving_the_same_custom_attribute_key_overwrites_only_that_key(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeCarPartsStore($owner);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);
        $part = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set',
            'price' => 39.99, 'active' => 1,
            'attributes' => ['material' => 'ceramic', 'oem_numbers' => ['ABC-123']],
        ]);

        $this->actingAs($owner)->put(route('retail.products.update', [$store, $part]), [
            'name' => 'Brake Pad Set',
            'price' => 39.99,
            'attr_key' => ['material'],
            'attr_value' => ['semi-metallic'],
        ])->assertRedirect();

        $fresh = $part->fresh();
        $this->assertSame('semi-metallic', $fresh->attributes['material']);
        $this->assertSame(['ABC-123'], $fresh->attributes['oem_numbers']);
    }

    public function test_omitting_attr_fields_entirely_leaves_existing_attributes_untouched(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeCarPartsStore($owner);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);
        $part = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set',
            'price' => 39.99, 'active' => 1,
            'attributes' => ['oem_numbers' => ['ABC-123']],
        ]);

        // A plain price change, no attr_key[]/attr_value[] posted at all.
        $this->actingAs($owner)->put(route('retail.products.update', [$store, $part]), [
            'name' => 'Brake Pad Set',
            'price' => 44.99,
        ])->assertRedirect();

        $fresh = $part->fresh();
        $this->assertEqualsWithDelta(44.99, (float) $fresh->price, 0.001);
        $this->assertSame(['ABC-123'], $fresh->attributes['oem_numbers']);
    }
}
