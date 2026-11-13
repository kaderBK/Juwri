<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
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
 * rev/multi-vertical Lane E5 — OEM / cross-reference numbers. Stored in the
 * SHARED `menu.attributes` JSON column (Lane A's seam — consumed, never
 * forked); dashboard save (FitmentController::updateOem) + storefront
 * display + the public OEM-search endpoint (FitmentPublicController).
 */
class FitmentOemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function makeStoreForOwner(User $owner): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => 'carparts', 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makePart(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set', 'price' => 39.99, 'active' => 1]);
    }

    // ── Dashboard save ───────────────────────────────────────────────────

    public function test_owner_can_save_oem_and_cross_reference_numbers(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $owner = $this->makeUser();
        $store = $this->makeStoreForOwner($owner);
        $part = $this->makePart($store);
        $part->update(['attributes' => ['material' => 'ceramic']]);

        $this->actingAs($owner)->post(route('retail.fitments.updateOem', [$store, $part]), [
            'oem_numbers' => "ABC-123\nDEF456",
            'cross_refs' => 'XYZ-999, XYZ-1000',
        ])->assertRedirect();

        $fresh = $part->fresh();
        $this->assertSame(['ABC-123', 'DEF456'], $fresh->attributes['oem_numbers']);
        $this->assertSame(['XYZ-999', 'XYZ-1000'], $fresh->attributes['cross_refs']);
        // Merge-safe: an unrelated attribute key set earlier is preserved.
        $this->assertSame('ceramic', $fresh->attributes['material']);
    }

    public function test_a_non_carparts_store_cannot_save_oem_numbers(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $owner = $this->makeUser();
        $store = Store::create(['user_id' => $owner->id, 'store_type' => 'retail', 'slug' => Str::slug('R'.Str::random(6)).'-'.Str::random(5), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $part = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10, 'active' => 1]);

        $this->actingAs($owner)->post(route('retail.fitments.updateOem', [$store, $part]), [
            'oem_numbers' => 'ABC-123',
        ])->assertNotFound();
    }

    // ── Storefront: display + search ────────────────────────────────────

    private function makeShelfStore(string $storeType): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Store '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $storeType,
            'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#1f6feb',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'shelf');

        return $store;
    }

    public function test_oem_numbers_render_on_the_storefront_item_detail(): void
    {
        $store = $this->makeShelfStore('carparts');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set',
            'price' => 39.99, 'active' => 1,
            'attributes' => ['oem_numbers' => ['ABC-123']],
        ]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('ABC-123');
    }

    public function test_oem_search_endpoint_finds_a_part_by_oem_or_cross_reference_number(): void
    {
        $store = $this->makeShelfStore('carparts');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);
        $part = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set',
            'price' => 39.99, 'active' => 1,
            'attributes' => ['oem_numbers' => ['ABC-123'], 'cross_refs' => ['XREF-999']],
        ]);
        $other = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Other Part', 'price' => 5, 'active' => 1]);

        $byOem = $this->getJson(route('storefront.fitment.oemSearch', $store->slug).'?q=abc-123');
        $byOem->assertOk()->assertJson(['enabled' => true]);
        $this->assertSame([$part->id], $byOem->json('menu_ids'));

        $byCrossRef = $this->getJson(route('storefront.fitment.oemSearch', $store->slug).'?q=xref');
        $this->assertSame([$part->id], $byCrossRef->json('menu_ids'));

        $noMatch = $this->getJson(route('storefront.fitment.oemSearch', $store->slug).'?q=zzz');
        $this->assertSame([], $noMatch->json('menu_ids'));

        $this->assertNotContains($other->id, $byOem->json('menu_ids'));
    }

    public function test_oem_search_endpoint_disabled_for_a_non_carparts_store(): void
    {
        $store = $this->makeShelfStore('retail');

        $response = $this->getJson(route('storefront.fitment.oemSearch', $store->slug).'?q=abc');

        $response->assertOk()->assertJson(['enabled' => false, 'menu_ids' => []]);
    }
}
