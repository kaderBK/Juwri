<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane C — C4: theme selection scoped per vertical.
 *
 * A retail/service store can only pick from its OWN theme set (shelf/bookla);
 * a restaurant is completely unaffected — it never sees, and can never
 * persist, a non-food theme, and every existing food theme still works for it
 * exactly as before. Enforcement is checked at BOTH entry points: the
 * create-store wizard (StoreController::store) and the theme customizer's
 * "switch template" endpoint (ThemeCustomizerController::setTemplate) — the
 * one real "template picker" left after Phase 3 moved it there.
 */
class VerticalThemeSelectionTest extends TestCase
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
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 200, 'settings' => (object) ['restaurant_limit' => 5], 'status' => 1]);

        return User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => (string) $plan->id]);
    }

    private function grantMultiVertical(User $user): void
    {
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();
    }

    private function makeStore(User $owner, string $storeType, string $template): Store
    {
        $store = Store::create([
            'user_id' => $owner->id,
            'store_type' => $storeType,
            'slug' => 'store-'.Str::random(8),
            'color' => '#111',
            'title' => 'T'.Str::random(4),
            'sub_title' => 'T',
            'description' => 'T',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return $store;
    }

    // ---- Create-wizard enforcement (StoreController::store) ----

    public function test_retail_store_can_be_created_on_shelf(): void
    {
        $user = $this->makeUser();
        $this->grantMultiVertical($user);

        $this->actingAs($user)->post(route('stores.store'), [
            'color' => '#111111',
            'title' => 'Corner Store',
            'description' => 'x',
            'address' => '1 St',
            'store_template' => 'shelf',
            'store_type' => 'retail',
            'cover_image' => UploadedFile::fake()->image('c.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('l.jpg', 500, 500),
        ]);

        $store = Store::where('title', 'Corner Store')->first();
        $this->assertNotNull($store, 'a retail store on shelf should have been created');
        $this->assertSame('shelf', StoreOption::getStoreOption($store->id, 'store_template'));
    }

    public function test_retail_store_cannot_be_created_on_a_food_theme(): void
    {
        $user = $this->makeUser();
        $this->grantMultiVertical($user);

        $this->actingAs($user)->post(route('stores.store'), [
            'color' => '#111111',
            'title' => 'Corner Store 2',
            'description' => 'x',
            'address' => '1 St',
            'store_template' => 'crave',
            'store_type' => 'retail',
            'cover_image' => UploadedFile::fake()->image('c.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('l.jpg', 500, 500),
        ]);

        $this->assertNull(Store::where('title', 'Corner Store 2')->first(), 'a retail store should not be able to persist a food theme');
    }

    public function test_restaurant_cannot_be_created_on_shelf(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('stores.store'), [
            'color' => '#111111',
            'title' => 'Olive Table 2',
            'description' => 'x',
            'address' => '1 St',
            'store_template' => 'shelf',
            // store_type omitted → defaults to 'restaurant'
            'cover_image' => UploadedFile::fake()->image('c.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('l.jpg', 500, 500),
        ]);

        $this->assertNull(Store::where('title', 'Olive Table 2')->first(), 'a restaurant should not be able to persist the retail theme');
    }

    public function test_restaurant_creation_on_a_food_theme_is_unaffected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('stores.store'), [
            'color' => '#111111',
            'title' => 'Olive Table 3',
            'description' => 'x',
            'address' => '1 St',
            'store_template' => 'crave',
            'cover_image' => UploadedFile::fake()->image('c.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('l.jpg', 500, 500),
        ]);

        $store = Store::where('title', 'Olive Table 3')->first();
        $this->assertNotNull($store, 'restaurant creation on an existing food theme must be unaffected');
    }

    // ---- Theme-customizer "switch template" enforcement ----

    public function test_retail_store_can_switch_to_shelf_via_the_customizer(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail', 'shelf');

        $res = $this->actingAs($owner)->postJson(route('theme.customizer.setTemplate', $store->id), ['template' => 'shelf']);

        $res->assertOk();
        $res->assertJson(['ok' => true]);
    }

    public function test_retail_store_cannot_switch_to_a_food_theme_via_the_customizer(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail', 'shelf');

        $res = $this->actingAs($owner)->postJson(route('theme.customizer.setTemplate', $store->id), ['template' => 'crave']);

        $res->assertStatus(422);
        $res->assertJson(['ok' => false]);
        $this->assertSame('shelf', StoreOption::getStoreOption($store->id, 'store_template'), 'the template must not have changed');
    }

    public function test_restaurant_cannot_switch_to_shelf_via_the_customizer(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'restaurant', 'crave');

        $res = $this->actingAs($owner)->postJson(route('theme.customizer.setTemplate', $store->id), ['template' => 'shelf']);

        $res->assertStatus(422);
    }

    public function test_restaurant_switching_between_food_themes_is_unaffected(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'restaurant', 'crave');

        $res = $this->actingAs($owner)->postJson(route('theme.customizer.setTemplate', $store->id), ['template' => 'matcha']);

        $res->assertOk();
        $res->assertJson(['ok' => true]);
    }

    // ---- Theme-library listing scoped per active store ----

    public function test_theme_library_only_lists_the_active_stores_vertical_set(): void
    {
        $owner = $this->makeUser();
        $retail = $this->makeStore($owner, 'retail', 'shelf');
        $store = $this->makeStore($owner, 'restaurant', 'crave');

        $retailPage = $this->actingAs($owner)->get(route('theme.customizer.page').'?store='.$retail->id);
        $retailPage->assertOk();
        $retailPage->assertSeeHtml('shelf');
        $retailPage->assertDontSeeHtml('"crave"');

        $restaurantPage = $this->actingAs($owner)->get(route('theme.customizer.page').'?store='.$store->id);
        $restaurantPage->assertOk();
        $restaurantPage->assertSeeHtml('crave');
        $restaurantPage->assertDontSeeHtml('"shelf"');
    }
}
