<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\StorefrontThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Store vertical CONVERT (in place) + DUPLICATE-as-a-different-vertical.
 *
 * Mirrors VerticalEntitlementGateTest's HTTP-through-controller style (a
 * Blade-direct/service-direct test would miss the route/controller contract —
 * auth, ownership, validation) and StoreCloneTest's fixture helpers.
 */
class StoreVerticalConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => (object) ['restaurant_limit' => 5], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function grant(User $user): void
    {
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();
    }

    /** An already-existing store of the given vertical, bypassing the create-gate. */
    private function makeStore(User $owner, string $type, ?string $title = null): Store
    {
        $title = $title ?? 'S'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'store_type' => $type,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function foodTemplate(): string
    {
        return app(StorefrontThemeService::class)->defaultSlug();
    }

    // ------------------------------------------------------------- convert

    /** SAME-GROUP (both `goods`) is a plain swap once entitled — no template needed. */
    public function test_same_group_convert_swaps_type_when_entitled(): void
    {
        $owner = $this->makeUser();
        $this->grant($owner);
        $store = $this->makeStore($owner, 'retail');

        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), ['store_type' => 'pharmacy'])
            ->assertOk()
            ->assertJson(['success' => true, 'store_type' => 'pharmacy', 'same_group' => true]);

        $this->assertSame('pharmacy', $store->fresh()->store_type);
    }

    /** CROSS-GROUP (goods -> food) needs a template for the TARGET set; converting back to restaurant is free (no entitlement needed). */
    public function test_cross_group_convert_requires_a_target_template(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail'); // no entitlement granted — target is 'restaurant', which is free

        // Without a template: rejected, nothing changes.
        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), ['store_type' => 'restaurant'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertSame('retail', $store->fresh()->store_type);

        // With a valid food template: succeeds.
        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), [
                'store_type' => 'restaurant',
                'store_template' => $this->foodTemplate(),
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'store_type' => 'restaurant', 'same_group' => false]);

        $store->refresh();
        $this->assertSame('restaurant', $store->store_type);
        $this->assertSame($this->foodTemplate(), StoreOption::getStoreOption($store->id, 'store_template'));
    }

    /** The entitlement gate fires even when a valid template is supplied — it is re-checked independently, not bypassed by satisfying the template requirement. */
    public function test_cross_group_convert_rechecks_entitlement_even_with_a_template(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $owner = $this->makeUser(); // no entitlement
        $store = $this->makeStore($owner, 'restaurant');

        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), ['store_type' => 'retail', 'store_template' => 'shelf'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertSame('restaurant', $store->fresh()->store_type);

        $this->grant($owner);

        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), ['store_type' => 'retail', 'store_template' => 'shelf'])
            ->assertOk()
            ->assertJson(['success' => true, 'store_type' => 'retail']);

        $store->refresh();
        $this->assertSame('retail', $store->store_type);
        $this->assertSame('shelf', StoreOption::getStoreOption($store->id, 'store_template'));
    }

    /** Converting TO any non-restaurant type without multi_vertical is rejected outright. */
    public function test_convert_to_non_restaurant_without_multi_vertical_is_rejected(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'restaurant');

        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), ['store_type' => 'laundry', 'store_template' => 'bookla'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame('restaurant', $store->fresh()->store_type);
    }

    /** Hotel is excluded from convert in v1, both as source and as target. */
    public function test_hotel_cannot_be_converted_from_or_to(): void
    {
        $owner = $this->makeUser();
        $this->grant($owner);

        $hotel = $this->makeStore($owner, 'hotel');
        $this->actingAs($owner)
            ->postJson(route('stores.convert', $hotel), ['store_type' => 'restaurant'])
            ->assertStatus(422);
        $this->assertSame('hotel', $hotel->fresh()->store_type);

        $store = $this->makeStore($owner, 'restaurant');
        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), ['store_type' => 'hotel'])
            ->assertStatus(422);
        $this->assertSame('restaurant', $store->fresh()->store_type);
    }

    /** Converting to the store's own current type is a no-op success — no transaction, no side effects. */
    public function test_convert_is_idempotent_for_the_same_type(): void
    {
        $owner = $this->makeUser();
        $this->grant($owner);
        $store = $this->makeStore($owner, 'retail');
        StoreOption::updateStoreOption($store->id, 'store_template', 'shelf');

        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), ['store_type' => 'retail'])
            ->assertOk()
            ->assertJson(['success' => true, 'store_type' => 'retail', 'same_group' => true])
            ->assertJsonPath('warnings', []);

        $store->refresh();
        $this->assertSame('retail', $store->store_type);
        // The no-op path never touches the template — still exactly what it was.
        $this->assertSame('shelf', StoreOption::getStoreOption($store->id, 'store_template'));
    }

    /** Fulfillment self-heal: modes the OLD vertical allowed but the NEW one does not are dropped from storage, not just filtered at read time. */
    public function test_convert_reheals_fulfillment_enabled_modes_on_cross_group_convert(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail');
        // Goods modes — none of these are valid for 'restaurant' (food).
        StoreOption::updateStoreOption($store->id, 'fulfillment_enabled_modes', json_encode(['ship', 'pickup_slot', 'in_store']));

        $this->actingAs($owner)
            ->postJson(route('stores.convert', $store), [
                'store_type' => 'restaurant',
                'store_template' => $this->foodTemplate(),
            ])
            ->assertOk();

        $raw = StoreOption::getStoreOption($store->id, 'fulfillment_enabled_modes');
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $this->assertSame([], $decoded, 'stale goods-only fulfillment modes should be dropped, not just hidden, once the store is food');
    }

    public function test_convert_rejects_non_owner(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $this->grant($other);
        $store = $this->makeStore($owner, 'retail');

        $this->actingAs($other)
            ->postJson(route('stores.convert', $store), ['store_type' => 'pharmacy'])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $this->assertSame('retail', $store->fresh()->store_type);
    }

    // ----------------------------------------------------------- duplicate

    /** Duplicate-as-a-different-vertical creates a NEW store under the target type and never mutates the source. */
    public function test_duplicate_as_vertical_creates_new_store_and_does_not_mutate_source(): void
    {
        $owner = $this->makeUser();
        $this->grant($owner);
        $source = $this->makeStore($owner, 'restaurant', 'Original Diner');
        $cat = MenuCategory::create(['store_id' => $source->id, 'name' => 'Cat']);
        Menu::create(['store_id' => $source->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10, 'active' => 1]);

        $this->actingAs($owner)
            ->postJson(route('stores.duplicateVertical', $source), [
                'store_type' => 'retail',
                'store_template' => 'shelf',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'store_type' => 'retail']);

        // Source is completely untouched.
        $source->refresh();
        $this->assertSame('restaurant', $source->store_type);
        $this->assertSame('Original Diner', $source->title);

        $clone = Store::where('user_id', $owner->id)->where('id', '!=', $source->id)->firstOrFail();
        $this->assertSame('retail', $clone->store_type);
        $this->assertSame('shelf', StoreOption::getStoreOption($clone->id, 'store_template'));
        $this->assertNotNull(Menu::where('store_id', $clone->id)->where('name', 'Item')->first(), 'the clone should carry over the source menu tree');
    }

    /** No explicit template: the source's (food) template is invalid for the goods target, so the clone falls back to the target vertical's own default. */
    public function test_duplicate_without_explicit_template_falls_back_to_target_default(): void
    {
        $owner = $this->makeUser();
        $this->grant($owner);
        $source = $this->makeStore($owner, 'restaurant');
        StoreOption::updateStoreOption($source->id, 'store_template', $this->foodTemplate());

        $this->actingAs($owner)
            ->postJson(route('stores.duplicateVertical', $source), ['store_type' => 'retail'])
            ->assertOk()
            ->assertJson(['success' => true, 'store_type' => 'retail']);

        $clone = Store::where('user_id', $owner->id)->where('id', '!=', $source->id)->firstOrFail();
        $cloneTemplate = StoreOption::getStoreOption($clone->id, 'store_template');
        $this->assertNotSame($this->foodTemplate(), $cloneTemplate, 'a food template is not valid for a retail store');
        // StorefrontThemeService::fallbackSlug('retail') resolves to the retail
        // vertical's own shared theme (VERTICAL_LAST_RESORT), same as a
        // brand-new retail store falling through with no configured default.
        $this->assertSame('shelf', $cloneTemplate);
    }

    /** Same entitlement/hotel guard as convert() applies to duplicate. */
    public function test_duplicate_blocks_hotel_target_and_non_restaurant_without_entitlement(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $owner = $this->makeUser(); // no entitlement
        $source = $this->makeStore($owner, 'restaurant');

        $this->actingAs($owner)
            ->postJson(route('stores.duplicateVertical', $source), ['store_type' => 'hotel'])
            ->assertStatus(422);
        $this->actingAs($owner)
            ->postJson(route('stores.duplicateVertical', $source), ['store_type' => 'retail'])
            ->assertStatus(422);

        $this->assertSame(1, Store::where('user_id', $owner->id)->count(), 'neither guarded attempt should have created a store');
    }

    /**
     * Regression: an Alpine `<template x-if>` INSIDE a native `<dialog>` throws
     * during init ("Cannot read properties of undefined (reading 'after')"),
     * which halts Alpine and leaves EVERY later store-settings tab cloaked —
     * the whole settings tab bar breaks, not just this panel. Dialog-state
     * conditionals must use `x-show` (display toggle, no DOM insertion). Caught
     * only by a real-browser walk; HTTP tests render the markup but never run
     * Alpine. Guard the exact markup so it can't come back.
     */
    public function test_convert_dialogs_use_x_show_not_template_x_if(): void
    {
        $src = file_get_contents(resource_path('views/owner/stores/partials/store-type-convert.blade.php'));

        preg_match_all('/<dialog\b.*?<\/dialog>/s', $src, $dialogs);
        $this->assertNotEmpty($dialogs[0], 'the partial should still define its confirm dialogs');
        foreach ($dialogs[0] as $dialog) {
            $this->assertStringNotContainsString(
                '<template x-if',
                $dialog,
                'a <template x-if> inside a <dialog> halts Alpine init and breaks every settings tab — use x-show'
            );
        }
    }
}
