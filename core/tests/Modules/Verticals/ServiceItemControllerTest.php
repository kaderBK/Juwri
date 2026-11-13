<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane D — D1: the service item model (DECISIONS A9).
 *
 * A laundry store creates "Shirt — iron" (each) and "Wash & fold" (per-kg)
 * through its OWN item form (never RetailItemController — a service has no
 * brand/stock/variant-matrix concepts) and every food-only field is absent.
 */
class ServiceItemControllerTest extends TestCase
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

    private function laundryOwner(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        // rev/multi-vertical Lane V (V4): ServiceItemController now requires
        // the multi_vertical entitlement to manage a laundry catalog.
        EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'laundry']);

        return [$owner, $store];
    }

    public function test_create_form_is_reachable_for_a_laundry_store(): void
    {
        [$owner, $store] = $this->laundryOwner();

        $res = $this->actingAs($owner)->get(route('laundry.services.create', $store->id));

        $res->assertOk();
        $res->assertSeeHtml('name="pricing_unit"');
        $res->assertSeeHtml('name="turnaround_hours"');
        // No goods-only or food-only fields ever appear on the service form.
        $res->assertDontSeeHtml('name="brand_id"');
        $res->assertDontSeeHtml('name="track_inventory"');
    }

    /**
     * Found by the e2eA laundry journey: the price field had no `step`, so the browser refused any decimal
     * (3.50) and an owner could not enter cents. Both the create and the edit form must allow two decimals.
     */
    public function test_the_price_field_accepts_cents_in_the_browser(): void
    {
        [$owner, $store] = $this->laundryOwner();

        $html = $this->actingAs($owner)->get(route('laundry.services.create', $store->id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="price"[^>]*step="0\.01"|<input[^>]*step="0\.01"[^>]*name="price"/', $html);
    }

    /**
     * Found by the e2eA laundry journey: a laundry owner had no way to create a category (the category routes were
     * retail-only and the services page had no form), yet the storefront lists services only under a category — so a
     * laundry store built in the dashboard showed "Nothing here yet". The services page now carries the category form.
     */
    public function test_a_laundry_owner_creates_categories_on_the_services_page(): void
    {
        [$owner, $store] = $this->laundryOwner();

        $this->actingAs($owner)->get(route('laundry.services.index', $store->id))->assertOk()
            ->assertSeeHtml('action="'.route('retail.categories.store', $store->id).'"');

        $this->actingAs($owner)->post(route('retail.categories.store', $store->id), ['name' => 'Wash & fold'])->assertRedirect();

        $category = MenuCategory::where('store_id', $store->id)->where('name', 'Wash & fold')->first();
        $this->assertNotNull($category);
        $this->actingAs($owner)->get(route('laundry.services.create', $store->id))->assertOk()->assertSee('Wash &amp; fold', false);

        // a service can then be filed under it
        $this->actingAs($owner)->post(route('laundry.services.store', $store->id), [
            'name' => 'Duvet', 'price' => 12, 'pricing_unit' => 'each', 'category_id' => $category->id, 'active' => 1,
        ])->assertRedirect();
        $this->assertSame($category->id, Menu::where('store_id', $store->id)->where('name', 'Duvet')->first()->category_id);

        // another owner cannot add to this store's categories, and a restaurant has no service categories at all
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $stranger->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();
        $this->actingAs($stranger)->post(route('retail.categories.store', $store->id), ['name' => 'X'])->assertNotFound();
        $otherStore = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'restaurant']);
        $this->actingAs($owner)->post(route('retail.categories.store', $otherStore->id), ['name' => 'X'])->assertNotFound();
    }

    /** Same defect as the retail form (see RetailCatalogDashboardTest): "Wash & fold" came back as `&amp;amp;`. */
    public function test_the_service_edit_form_does_not_double_escape_special_characters(): void
    {
        [$owner, $store] = $this->laundryOwner();
        $service = Menu::create(['store_id' => $store->id, 'name' => 'Wash & fold', 'price' => 3, 'active' => 1, 'pricing_unit' => 'kg']);

        $html = $this->actingAs($owner)->get(route('laundry.services.edit', [$store->id, $service->id]))->assertOk()->getContent();

        $this->assertStringContainsString('value="Wash &amp; fold"', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_it_creates_an_each_priced_service(): void
    {
        [$owner, $store] = $this->laundryOwner();

        $res = $this->actingAs($owner)->post(route('laundry.services.store', $store->id), [
            'name' => 'Shirt — iron',
            'price' => 3.5,
            'pricing_unit' => 'each',
            'turnaround_hours' => 24,
        ]);

        $res->assertRedirect();
        $item = Menu::where('store_id', $store->id)->where('name', 'Shirt — iron')->first();
        $this->assertNotNull($item);
        $this->assertSame('each', $item->pricing_unit);
        $this->assertSame(24, $item->turnaround_hours);
        $this->assertEqualsWithDelta(3.5, (float) $item->price, 0.001);
    }

    public function test_it_creates_a_per_kg_priced_service(): void
    {
        [$owner, $store] = $this->laundryOwner();

        $res = $this->actingAs($owner)->post(route('laundry.services.store', $store->id), [
            'name' => 'Wash & fold',
            'price' => 5,
            'pricing_unit' => 'kg',
            'turnaround_hours' => 48,
        ]);

        $res->assertRedirect();
        $item = Menu::where('store_id', $store->id)->where('name', 'Wash & fold')->first();
        $this->assertNotNull($item);
        $this->assertSame('kg', $item->pricing_unit);
        $this->assertSame(48, $item->turnaround_hours);
    }

    public function test_a_restaurant_store_cannot_reach_the_service_form(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'restaurant']);

        $res = $this->actingAs($owner)->get(route('laundry.services.create', $store->id));

        $res->assertNotFound();
    }

    public function test_a_retail_store_cannot_reach_the_service_form(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);

        $res = $this->actingAs($owner)->get(route('laundry.services.create', $store->id));

        $res->assertNotFound();
    }

    public function test_an_owner_cannot_manage_another_owners_laundry_store(): void
    {
        [, $store] = $this->laundryOwner();
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $res = $this->actingAs($intruder)->get(route('laundry.services.index', $store->id));

        $res->assertNotFound();
    }
}
