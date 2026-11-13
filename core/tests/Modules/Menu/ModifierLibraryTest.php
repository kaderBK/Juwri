<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ModifierLibraryTest extends TestCase
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
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeItem(Store $r): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item '.Str::random(4), 'price' => 5, 'active' => '1']);
    }

    public function test_create_global_extra(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('modifiers.extras.store'), [
            'store_id' => $r->id, 'title' => 'Bacon', 'price' => 2,
        ]);

        $extra = MenuExtra::where('store_id', $r->id)->first();
        $this->assertTrue((bool) $extra->is_global);
        $this->assertNull($extra->menu_id);
        $this->assertSame('Bacon', $extra->title);
    }

    public function test_attach_extra_appears_in_resolved_with_override(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $menu = $this->makeItem($r);
        $extra = MenuExtra::create(['store_id' => $r->id, 'is_global' => true, 'title' => 'Bacon', 'price' => 2]);

        $this->actingAs($owner)->postJson(route('modifiers.attachExtra', $menu->id), [
            'extra_id' => $extra->id, 'price_override' => 3.5,
        ])->assertJson(['success' => true]);

        $resolved = $menu->fresh()->resolved_extras;
        $this->assertTrue($resolved->contains('id', $extra->id));
        $this->assertEquals(3.5, $resolved->firstWhere('id', $extra->id)->price);
    }

    public function test_detach_extra(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $menu = $this->makeItem($r);
        $extra = MenuExtra::create(['store_id' => $r->id, 'is_global' => true, 'title' => 'X', 'price' => 1]);
        $menu->attachedExtras()->attach($extra->id, ['active' => true]);

        $this->actingAs($owner)->deleteJson(route('modifiers.detachExtra', ['menu' => $menu->id, 'extra' => $extra->id]))
            ->assertJson(['success' => true]);

        $this->assertFalse($menu->fresh()->resolved_extras->contains('id', $extra->id));
    }

    public function test_attach_group_with_rule_override(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $menu = $this->makeItem($r);
        $group = MenuVariantOption::create([
            'store_id' => $r->id, 'is_global' => true, 'title' => 'Size',
            'options' => ['S', 'L'], 'required' => false, 'min_select' => 0,
        ]);

        $this->actingAs($owner)->postJson(route('modifiers.attachGroup', $menu->id), [
            'group_id' => $group->id, 'required' => 1, 'min_select' => 1, 'max_select' => 1,
        ])->assertJson(['success' => true]);

        $resolved = $menu->fresh()->resolved_variant_options->firstWhere('id', $group->id);
        $this->assertNotNull($resolved);
        $this->assertTrue((bool) $resolved->required);
        $this->assertSame(1, (int) $resolved->min_select);
    }

    public function test_library_edit_propagates(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $menu = $this->makeItem($r);
        $extra = MenuExtra::create(['store_id' => $r->id, 'is_global' => true, 'title' => 'Old', 'price' => 1]);
        $menu->attachedExtras()->attach($extra->id, ['active' => true]);

        $this->actingAs($owner)->putJson(route('modifiers.extras.update', $extra->id), [
            'title' => 'New name', 'price' => 9,
        ])->assertJson(['success' => true]);

        $resolved = $menu->fresh()->resolved_extras->firstWhere('id', $extra->id);
        $this->assertSame('New name', $resolved->title);
        $this->assertEquals(9, $resolved->price);
    }

    public function test_destroy_global_extra_detaches_everywhere(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $menu = $this->makeItem($r);
        $extra = MenuExtra::create(['store_id' => $r->id, 'is_global' => true, 'title' => 'Z', 'price' => 1]);
        $menu->attachedExtras()->attach($extra->id, ['active' => true]);

        $this->actingAs($owner)->deleteJson(route('modifiers.extras.destroy', $extra->id))->assertJson(['success' => true]);

        $this->assertDatabaseMissing('modifiers', ['id' => $extra->id]);
        $this->assertDatabaseMissing('item_modifier_group', ['modifier_id' => $extra->id]);
    }

    public function test_tenant_isolation_on_store_and_attach(): void
    {
        $owner = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($this->makeUser());
        $otherMenu = $this->makeItem($otherRestaurant);
        $otherExtra = MenuExtra::create(['store_id' => $otherRestaurant->id, 'is_global' => true, 'title' => 'X', 'price' => 1]);

        $this->actingAs($owner)->post(route('modifiers.extras.store'), [
            'store_id' => $otherRestaurant->id, 'title' => 'Hack', 'price' => 1,
        ])->assertForbidden();

        $this->actingAs($owner)->postJson(route('modifiers.attachExtra', $otherMenu->id), [
            'extra_id' => $otherExtra->id,
        ])->assertForbidden();
    }

    public function test_attached_pluck_with_pivot_order_does_not_throw(): void
    {
        // Regression: ordering a belongsToMany by a pivot column must use orderByPivot so it
        // survives ->pluck() (which doesn't select the pivot_* aliases). Browser caught this.
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $menu = $this->makeItem($r);
        $extra = MenuExtra::create(['store_id' => $r->id, 'is_global' => true, 'title' => 'P', 'price' => 1]);
        $menu->attachedExtras()->attach($extra->id, ['active' => true, 'position' => 0]);

        $ids = $menu->attachedExtras()->where('is_global', true)->pluck('modifiers.id');
        $this->assertEquals([$extra->id], $ids->all());
    }

    public function test_global_group_stores_option_prices(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('modifiers.groups.store'), [
            'store_id' => $r->id, 'title' => 'Size',
            'options' => 'Small, Medium, Large', 'option_prices' => '0, 2, 4.5',
            'required' => 1, 'min_select' => 1, 'max_select' => 1,
        ]);

        $group = MenuVariantOption::where('store_id', $r->id)->where('is_global', true)->first();
        $this->assertNotNull($group);
        $this->assertEquals([0, 2, 4.5], $group->option_prices);
        $this->assertTrue((bool) $group->required);
        $this->assertSame(1, (int) $group->max_select);
    }

    public function test_global_group_all_zero_prices_stores_null(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('modifiers.groups.store'), [
            'store_id' => $r->id, 'title' => 'Spice',
            'options' => 'Mild, Hot', 'option_prices' => '0, 0',
        ]);

        $group = MenuVariantOption::where('store_id', $r->id)->where('title', 'Spice')->first();
        $this->assertNull($group->option_prices); // free group => null
    }

    public function test_global_extra_translation_saved_without_touching_default(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'French', 'direction' => 'ltr', 'active' => 1]);
        StoreOption::updateStoreOption($r->id, 'default_language', 'en');
        StoreOption::updateStoreOption($r->id, 'menu_languages', json_encode(['en', 'fr']));

        $extra = MenuExtra::create(['store_id' => $r->id, 'is_global' => true, 'title' => 'Cheese', 'price' => 1]);

        $_COOKIE['Quick_user_lang_code'] = 'fr';
        try {
            $this->actingAs($owner)->putJson(route('modifiers.extras.update', $extra->id), ['title' => 'Fromage', 'price' => 1])
                ->assertJson(['success' => true]);
        } finally {
            unset($_COOKIE['Quick_user_lang_code']);
        }

        $extra->refresh();
        $this->assertSame('Cheese', $extra->getOriginal('title')); // default untouched
        $this->assertSame('Fromage', $extra->translations->fr->title); // translation stored
    }

    public function test_global_group_translation_saves_title_and_options(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'French', 'direction' => 'ltr', 'active' => 1]);
        StoreOption::updateStoreOption($r->id, 'default_language', 'en');
        StoreOption::updateStoreOption($r->id, 'menu_languages', json_encode(['en', 'fr']));

        $group = MenuVariantOption::create([
            'store_id' => $r->id, 'is_global' => true, 'title' => 'Size',
            'options' => ['Small', 'Large'], 'option_prices' => [0, 3],
        ]);

        $_COOKIE['Quick_user_lang_code'] = 'fr';
        try {
            $this->actingAs($owner)->putJson(route('modifiers.groups.update', $group->id), [
                'title' => 'Taille', 'options' => 'Petit, Grand', 'option_prices' => '0, 3',
            ])->assertJson(['success' => true]);
        } finally {
            unset($_COOKIE['Quick_user_lang_code']);
        }

        $group->refresh();
        $this->assertSame('Size', $group->getOriginal('title')); // default untouched
        $this->assertSame('Taille', $group->translations->fr->title);
        $this->assertSame(['Petit', 'Grand'], (array) $group->translations->fr->options);
        $rawOpts = $group->getOriginal('options');
        $this->assertEquals(['Small', 'Large'], is_string($rawOpts) ? json_decode($rawOpts, true) : (array) $rawOpts); // default options untouched
        $this->assertEquals([0, 3], $group->option_prices); // prices shared/untouched
    }

    public function test_backfill_kept_legacy_items_working(): void
    {
        // A legacy per-item extra (menu_id set) should still resolve for the item.
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $menu = $this->makeItem($r);
        $legacy = MenuExtra::create(['store_id' => $r->id, 'menu_id' => $menu->id, 'title' => 'Legacy', 'price' => 1]);

        $this->assertTrue($menu->fresh()->resolved_extras->contains('id', $legacy->id));
    }

    /** The resolved_* accessors return identical output whether relations are eager-loaded or queried, and eager-loaded reads issue no queries (N+1 fix). */
    public function test_resolved_accessors_identical_eager_loaded_vs_queried(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $menu = $this->makeItem($r);

        // Attached extras: one active with a price override, one INACTIVE (excluded).
        $eActive = MenuExtra::create(['store_id' => $r->id, 'title' => 'Bacon', 'price' => 2, 'is_global' => true]);
        $eInactive = MenuExtra::create(['store_id' => $r->id, 'title' => 'Cheese', 'price' => 1, 'is_global' => true]);
        $menu->attachedExtras()->attach($eActive->id, ['active' => true, 'position' => 0, 'price_override' => 3.5]);
        $menu->attachedExtras()->attach($eInactive->id, ['active' => false, 'position' => 1]);
        // Legacy per-item extra (hasMany).
        MenuExtra::create(['store_id' => $r->id, 'menu_id' => $menu->id, 'title' => 'Onion', 'price' => 0.5, 'position' => 0]);

        // Attached variant group (with rule overrides) + a legacy group.
        $gAttached = MenuVariantOption::create(['store_id' => $r->id, 'title' => 'Size', 'options' => ['S', 'L'], 'is_global' => true]);
        $menu->attachedVariantOptions()->attach($gAttached->id, ['active' => true, 'position' => 0, 'required' => 1, 'min_select' => 1, 'max_select' => 2]);
        MenuVariantOption::create(['store_id' => $r->id, 'menu_id' => $menu->id, 'title' => 'Temp', 'options' => ['Hot'], 'position' => 0]);

        $queried = Menu::find($menu->id);
        $loaded = Menu::with(['attachedExtras', 'extras', 'attachedVariantOptions', 'variantOptions'])->find($menu->id);

        // Identical extras: ids + order + overridden prices; inactive attachment excluded in both.
        $this->assertSame($queried->resolved_extras->pluck('id')->all(), $loaded->resolved_extras->pluck('id')->all());
        $this->assertEquals(
            $queried->resolved_extras->map(fn ($e) => (float) $e->price)->all(),
            $loaded->resolved_extras->map(fn ($e) => (float) $e->price)->all(),
        );
        $this->assertFalse($loaded->resolved_extras->contains('id', $eInactive->id));

        // Identical variant groups: ids + order + rule overrides.
        $this->assertSame($queried->resolved_variant_options->pluck('id')->all(), $loaded->resolved_variant_options->pluck('id')->all());
        $qg = $queried->resolved_variant_options->firstWhere('id', $gAttached->id);
        $lg = $loaded->resolved_variant_options->firstWhere('id', $gAttached->id);
        $this->assertSame(
            [(bool) $qg->required, (int) $qg->min_select, (int) $qg->max_select],
            [(bool) $lg->required, (int) $lg->min_select, (int) $lg->max_select],
        );

        // Eager-loaded reads must issue NO queries.
        $q = 0;
        DB::listen(function () use (&$q) {
            $q++;
        });
        $loaded->resolved_extras;
        $loaded->resolved_variant_options;
        $this->assertSame(0, $q, "resolved accessors must not query when relations are eager-loaded; got {$q}");
    }
}
