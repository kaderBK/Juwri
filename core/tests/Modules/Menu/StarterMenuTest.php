<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\StarterMenuService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C7 — one-click starter menus: per-preset seed counts, translations/flags, the idempotence
 * refusal, the 422 on a bad preset, and the owner-only empty-state banner.
 */
class StarterMenuTest extends TestCase
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
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    /** @return array{0: User, 1: Store} */
    private function ownerStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    /** @return array{0: int, 1: int} expected [categories, items] for a preset file */
    private function expected(string $preset): array
    {
        $data = json_decode((string) file_get_contents(database_path('starter-menus/'.$preset.'.json')), true);
        $cats = count($data['categories']);
        $items = array_sum(array_map(fn ($c) => count($c['items']), $data['categories']));

        return [$cats, $items];
    }

    public function test_every_preset_seeds_its_declared_counts(): void
    {
        $service = app(StarterMenuService::class);

        foreach (StarterMenuService::PRESETS as $preset) {
            [, $store] = $this->ownerStore();
            [$expCats, $expItems] = $this->expected($preset);

            $result = $service->seed($store, $preset);

            $this->assertTrue($result['seeded'], "preset {$preset} should seed");
            $this->assertSame($expCats, $result['categories'], "preset {$preset} category count");
            $this->assertSame($expItems, $result['items'], "preset {$preset} item count");
            $this->assertSame($expCats, MenuCategory::where('store_id', $store->id)->count());
            $this->assertSame($expItems, Menu::where('store_id', $store->id)->count());

            // 4–6 categories × 4–8 items contract.
            $this->assertGreaterThanOrEqual(4, $expCats);
            $this->assertLessThanOrEqual(6, $expCats);
        }
    }

    public function test_seed_writes_arabic_translations_and_flags(): void
    {
        [, $store] = $this->ownerStore();
        app(StarterMenuService::class)->seed($store, 'cafe');

        $category = MenuCategory::where('store_id', $store->id)->orderBy('position')->first();
        $this->assertNotEmpty($category->translations->ar->title ?? null, 'category carries an Arabic name');

        $vegan = Menu::where('store_id', $store->id)->where('is_vegan', 1)->first();
        $this->assertNotNull($vegan, 'at least one vegan item is flagged');
        $this->assertNotEmpty($vegan->translations->ar->title ?? null, 'item carries an Arabic name');
    }

    public function test_seed_refuses_when_store_already_has_menus(): void
    {
        [, $store] = $this->ownerStore();
        $service = app(StarterMenuService::class);

        $first = $service->seed($store, 'cafe');
        $this->assertTrue($first['seeded']);
        $countAfterFirst = MenuCategory::where('store_id', $store->id)->count();

        $second = $service->seed($store, 'pizzeria');
        $this->assertFalse($second['seeded']);
        $this->assertSame('not_empty', $second['reason']);
        $this->assertSame($countAfterFirst, MenuCategory::where('store_id', $store->id)->count(), 'a second seed must add nothing');
    }

    public function test_endpoint_rejects_a_bad_preset_with_422(): void
    {
        [$owner, $store] = $this->ownerStore();

        $this->actingAs($owner)
            ->postJson(route('menu.starter.seed', $store), ['preset' => 'sushi'])
            ->assertStatus(422);
    }

    public function test_endpoint_seeds_a_valid_preset(): void
    {
        [$owner, $store] = $this->ownerStore();

        $this->actingAs($owner)
            ->post(route('menu.starter.seed', $store), ['preset' => 'burger'])
            ->assertRedirect();

        $this->assertGreaterThan(0, MenuCategory::where('store_id', $store->id)->count());
    }

    public function test_banner_shows_only_when_menu_is_empty(): void
    {
        [$owner, $store] = $this->ownerStore();

        // Empty menu → the owner sees the template banner.
        $this->actingAs($owner)
            ->get(route('stores.menu', $store))
            ->assertOk()
            ->assertSee('Start from a template');

        // After seeding, categories exist → banner gone.
        app(StarterMenuService::class)->seed($store, 'cafe');
        $this->actingAs($owner)
            ->get(route('stores.menu', $store))
            ->assertOk()
            ->assertDontSee('Start from a template');
    }
}
