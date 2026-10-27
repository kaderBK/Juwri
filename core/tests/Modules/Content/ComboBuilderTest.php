<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T6 — the owner's combo builder.
 *
 * CRUD plus the two things a builder must refuse: a choice group that can never
 * be satisfied (min above max, or min above the number of options), and an
 * option pointing at another tenant's menu item.
 */
class ComboBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        config(['settings.admin_allow_combos' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        // The '*' composer that shares the layout scaffolding is gated behind
        // env('APP_INSTALLED'), which phpunit.xml deliberately leaves false.
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function storeFixture(?User $owner = null): Store
    {
        $owner = $owner ?: $this->owner();
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $store, string $name, float $price): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => $name, 'price' => $price, 'active' => 1,
        ]);
    }

    private function payload(Store $store, array $override = []): array
    {
        $burger = $this->item($store, 'Burger', 10);
        $water = $this->item($store, 'Water', 2);

        return array_replace_recursive([
            'store_id' => $store->id,
            'name' => 'Family Meal',
            'description' => 'Feeds two',
            'price' => 15,
            'active' => 1,
            'slots' => [[
                'title' => 'Choose your main', 'min' => 1, 'max' => 1,
                'options' => [['menu_id' => $burger->id, 'upcharge' => 0, 'active' => 1]],
            ], [
                'title' => 'Choose your drink', 'min' => 1, 'max' => 1,
                'options' => [['menu_id' => $water->id, 'upcharge' => 0.75, 'active' => 1]],
            ]],
        ], $override);
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function test_the_page_renders_with_an_empty_state_before_any_combo_exists(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $this->item($store, 'Burger', 10);

        $this->actingAs($owner)
            ->get(route('combos.index', ['store_id' => $store->id]))->assertOk()->assertSeeHtml('No combo meals yet');
    }

    public function test_a_store_with_no_menu_items_is_told_why_it_cannot_build_one(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);

        $this->actingAs($owner)
            ->get(route('combos.index', ['store_id' => $store->id]))->assertOk()->assertSeeHtml('a combo is built from items you already sell');
    }

    public function test_the_builder_renders_an_existing_combo_with_its_saving_preview(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        // Cheapest picks à la carte: burger 10.00 + water 2.00 + its 0.75 upcharge
        // = 12.75. A 9.00 bundle therefore saves exactly 3.75.
        $payload = $this->payload($store);
        $payload['price'] = 9;
        $this->actingAs($owner)->postJson(route('combos.save'), $payload)->assertOk();

        $html = $this->actingAs($owner)
            ->get(route('combos.index', ['store_id' => $store->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Family Meal', $html);
        $this->assertStringContainsString('2 choice groups', $html);
        $this->assertStringContainsString('3.75', $html);
        $this->assertStringContainsString('class="ui-text-success">', $html);
    }

    public function test_a_bundle_dearer_than_its_parts_shows_no_saving_badge_on_the_row(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        // 15.00 bundle against a 12.75 à-la-carte floor — nothing to boast about.
        $this->actingAs($owner)->postJson(route('combos.save'), $this->payload($store))->assertOk();

        $html = $this->actingAs($owner)
            ->get(route('combos.index', ['store_id' => $store->id]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('class="ui-text-success">', $html);
    }

    public function test_the_builder_renders_under_the_arabic_locale(): void
    {
        // phpunit.xml forces DEFAULT_LANGUAGE=ar; the dashboard is RTL there, and a
        // missing translation key or an @js/@click quoting slip shows up as a 500.
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $this->actingAs($owner)->postJson(route('combos.save'), $this->payload($store))->assertOk();

        app()->setLocale('ar');
        $this->actingAs($owner)
            ->get(route('combos.index', ['store_id' => $store->id]))->assertOk()->assertSeeHtml('وجبة مجمّعة جديدة'); // "New combo"
    }

    public function test_saving_creates_the_whole_tree_in_one_call(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);

        $this->actingAs($owner)
            ->postJson(route('combos.save'), $this->payload($store))
            ->assertOk()->assertJson(['success' => true]);

        $combo = Combo::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('Family Meal', $combo->name);
        $this->assertSame(2, ComboSlot::where('combo_id', $combo->id)->count());
        $this->assertSame(2, ComboSlotOption::where('combo_id', $combo->id)->count());
        $this->assertSame('15.00', (string) $combo->price);
    }

    public function test_saving_again_replaces_the_slots_rather_than_duplicating_them(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);

        $this->actingAs($owner)->postJson(route('combos.save'), $this->payload($store))->assertOk();
        $combo = Combo::where('store_id', $store->id)->firstOrFail();

        $again = $this->payload($store);
        $again['id'] = $combo->id;
        $again['name'] = 'Family Meal v2';
        $this->actingAs($owner)->postJson(route('combos.save'), $again)->assertOk();

        $this->assertSame(1, Combo::where('store_id', $store->id)->count());
        $this->assertSame(2, ComboSlot::where('combo_id', $combo->id)->count());
        $this->assertSame('Family Meal v2', $combo->fresh()->name);
    }

    public function test_a_choice_group_asking_for_more_than_it_allows_is_refused(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $payload = $this->payload($store);
        $payload['slots'][0]['min'] = 2;
        $payload['slots'][0]['max'] = 1;

        $this->actingAs($owner)->postJson(route('combos.save'), $payload)
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, Combo::count());
    }

    public function test_a_choice_group_asking_for_more_picks_than_it_has_options_is_refused(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $payload = $this->payload($store);
        $payload['slots'][0]['min'] = 3;
        $payload['slots'][0]['max'] = 3;

        $this->actingAs($owner)->postJson(route('combos.save'), $payload)
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, Combo::count());
    }

    public function test_a_combo_cannot_include_another_tenants_menu_item(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $theirs = $this->storeFixture(); // different owner
        $foreign = $this->item($theirs, 'Their Burger', 10);

        $payload = $this->payload($store);
        $payload['slots'][0]['options'][0]['menu_id'] = $foreign->id;

        $this->actingAs($owner)->postJson(route('combos.save'), $payload)->assertForbidden();
        $this->assertSame(0, Combo::count());
    }

    public function test_a_slotless_combo_is_refused(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $payload = $this->payload($store);
        $payload['slots'] = [];

        $this->actingAs($owner)->postJson(route('combos.save'), $payload)->assertStatus(422);
    }

    public function test_another_owner_cannot_edit_or_delete_a_combo(): void
    {
        $mine = $this->owner();
        $store = $this->storeFixture($mine);
        $this->actingAs($mine)->postJson(route('combos.save'), $this->payload($store))->assertOk();
        $combo = Combo::firstOrFail();

        $intruder = $this->owner();
        $this->actingAs($intruder)->deleteJson(route('combos.destroy', $combo->id))->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->actingAs($intruder)->postJson(route('combos.toggle', $combo->id))->assertNotFound();
        $this->assertSame(1, Combo::count());
    }

    public function test_toggling_hides_the_combo_without_deleting_it(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $this->actingAs($owner)->postJson(route('combos.save'), $this->payload($store))->assertOk();
        $combo = Combo::firstOrFail();

        $this->actingAs($owner)->postJson(route('combos.toggle', $combo->id))
            ->assertOk()->assertJson(['active' => false]);
        $this->assertFalse((bool) $combo->fresh()->active);
        $this->assertSame(2, ComboSlot::where('combo_id', $combo->id)->count());
    }

    public function test_deleting_a_combo_takes_its_slots_and_options_with_it(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $this->actingAs($owner)->postJson(route('combos.save'), $this->payload($store))->assertOk();
        $combo = Combo::firstOrFail();

        $this->actingAs($owner)->deleteJson(route('combos.destroy', $combo->id))->assertOk();

        $this->assertSame(0, Combo::count());
        $this->assertSame(0, ComboSlot::count());
        $this->assertSame(0, ComboSlotOption::count());
    }

    public function test_reordering_only_touches_the_owners_own_combos(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $this->actingAs($owner)->postJson(route('combos.save'), $this->payload($store))->assertOk();
        $mine = Combo::firstOrFail();

        $theirsPost = $this->storeFixture();
        $theirs = Combo::create([
            'store_id' => $theirsPost->id, 'name' => 'Theirs', 'price' => 1, 'active' => true, 'position' => 7,
        ]);

        $this->actingAs($owner)->postJson(route('combos.reorder'), ['position' => [$theirs->id, $mine->id]])->assertOk();

        $this->assertSame(1, (int) $mine->fresh()->position);
        $this->assertSame(7, (int) $theirs->fresh()->position, "Another tenant's ordering must be untouched.");
    }

    /**
     * The money contract on a 3-decimal currency. The row currency resolves
     * from `store_id`; without it (or storeIdForMoney()) a bundle price of
     * 0.275 OMR would silently persist and read back as 0.28.
     */
    public function test_a_three_decimal_currency_keeps_its_fils_on_combo_money(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'OMR');

        $payload = $this->payload($store);
        $payload['price'] = 0.275;
        $payload['slots'][1]['options'][0]['upcharge'] = 0.125;

        $this->actingAs($owner)->postJson(route('combos.save'), $payload)->assertOk();

        $combo = Combo::firstOrFail();
        $this->assertSame('0.275', (string) $combo->price);
        $this->assertSame('0.125', (string) ComboSlotOption::orderByDesc('id')->first()->upcharge);
    }
}
