<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Http\Controllers\MarketplaceController;
use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use App\Models\Theme;
use App\Services\Theme\ThemeSets;
use App\Storefront\ThemeSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;
use Tests\Support\TestPlans;
use Tests\TestCase;
use ZipArchive;

/**
 * Marketplace theme SETS — the vertical family a theme belongs to
 * (food | retail | service).
 *
 * Every marketplace theme used to be filed under `food`, so a clothing theme
 * was offered to restaurants and never to a clothing store (a clothing
 * store's set is `retail`). The set is derived from the theme's manifest
 * vertical and recorded on the row, so the catalogue can be scoped to the
 * stores the theme can actually serve and the offerability rule enforced when
 * a store applies it.
 */
class MarketplaceThemeSetTest extends TestCase
{
    use RefreshDatabase;

    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zipPath = sys_get_temp_dir().'/theme-set-'.bin2hex(random_bytes(4)).'.zip';
        $this->withoutMiddleware(Installed::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
        foreach ([storage_path('app/marketplace-themes'), public_path('assets/marketplace-themes')] as $root) {
            foreach (glob($root.'/settest-*', GLOB_ONLYDIR) ?: [] as $dir) {
                File::deleteDirectory($dir);
            }
        }
        parent::tearDown();
    }

    /**
     * A package whose manifest declares the given vertical/recipe.
     *
     * @param  array<string,mixed>  $manifest
     */
    private function makeZip(string $slug = 'settest-retail', array $manifest = []): string
    {
        @unlink($this->zipPath);
        $zip = new ZipArchive;
        $zip->open($this->zipPath, ZipArchive::CREATE);
        $zip->addFromString('theme.json', (string) json_encode(array_merge([
            'name' => 'Set Test', 'slug' => $slug, 'version' => '1.0.0',
        ], $manifest)));
        $zip->addFromString('templates/index.liquid', '<h1>{{ restaurant.title | escape }}</h1>');
        $zip->close();

        return $this->zipPath;
    }

    /**
     * A submitted + published theme (the catalogue only lists published ones).
     *
     * @param  array<string,mixed>  $manifest
     */
    private function publish(string $slug, array $manifest = []): Theme
    {
        $version = app(ThemeSubmissionService::class)->submit($this->makeZip($slug, $manifest));
        $theme = $version->theme;
        $theme->status = 'published';
        $theme->save();
        $version->status = 'published';
        $version->save();

        return $theme->fresh();
    }

    /**
     * An owner with one store of the given vertical.
     *
     * @return array{User, Store}
     */
    private function store(string $storeType): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'set-'.strtolower($storeType).'-'.strtolower(Str::random(5)),
            'color' => '#111', 'title' => 'Store '.$storeType, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'store_type' => $storeType, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    public function test_a_clothing_manifest_registers_the_theme_in_the_retail_set(): void
    {
        $theme = $this->publish('settest-retail', ['vertical' => 'clothing']);

        $this->assertSame('retail', $theme->theme_set);
        $this->assertTrue($theme->fitsStore('clothing'));
        $this->assertFalse($theme->fitsStore('restaurant'));
    }

    public function test_a_manifest_without_a_vertical_stays_in_the_food_set(): void
    {
        $theme = $this->publish('settest-food', []);

        $this->assertSame('food', $theme->theme_set);
        $this->assertTrue($theme->fitsStore('restaurant'));
        $this->assertFalse($theme->fitsStore('clothing'));
    }

    public function test_the_recipe_id_registers_the_set_when_the_manifest_has_no_vertical(): void
    {
        $theme = $this->publish('settest-retail', ['recipe' => 'clothing/default']);

        $this->assertSame('retail', $theme->theme_set);
    }

    public function test_the_catalogue_can_be_scoped_to_a_theme_set(): void
    {
        $this->publish('settest-food', ['vertical' => 'restaurant']);
        $this->publish('settest-retail', ['vertical' => 'clothing']);

        $scoped = app(MarketplaceController::class)
            ->index(request(), 'retail')->getData();
        $this->assertSame(['settest-retail'], $scoped['results']->pluck('slug')->all());

        $all = app(MarketplaceController::class)
            ->index(request(), null)->getData();
        $this->assertCount(2, $all['results']);
    }

    public function test_a_store_cannot_apply_a_theme_from_another_set(): void
    {
        $theme = $this->publish('settest-retail', ['vertical' => 'clothing']);
        [$owner, $restaurant] = $this->store('restaurant');

        $this->actingAs($owner)
            ->postJson(route('marketplace.apply', $theme->slug), ['store_id' => $restaurant->id])
            ->assertStatus(422);

        $this->assertNotSame(
            'mp:'.$theme->slug.'@1.0.0',
            StoreOption::getStoreOption($restaurant->id, 'store_template')
        );
    }

    public function test_a_store_can_apply_a_theme_from_its_own_set(): void
    {
        $theme = $this->publish('settest-retail', ['vertical' => 'clothing']);
        [$owner, $clothing] = $this->store('clothing');

        $this->actingAs($owner)
            ->postJson(route('marketplace.apply', $theme->slug), ['store_id' => $clothing->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(
            'mp:'.$theme->slug.'@1.0.0',
            StoreOption::getStoreOption($clothing->id, 'store_template')
        );
    }

    public function test_the_owner_theme_store_offers_the_active_stores_set(): void
    {
        $this->publish('settest-food', ['vertical' => 'restaurant']);
        $this->publish('settest-retail', ['vertical' => 'clothing']);
        [$owner, $clothing] = $this->store('clothing');

        $res = $this->actingAs($owner)->withSession(['dashboard_store_id' => $clothing->id])
            ->get(route('user.store.themes'));
        $this->assertSame(200, $res->status(), 'redirect to: '.($res->headers->get('Location') ?? 'none'));
        $res->assertSee('settest-retail')
            ->assertDontSee('settest-food');
    }

    /**
     * Every vertical the platform ships, with the set its themes land in.
     *
     * @return array<string,string> vertical => set
     */
    private function verticalSets(): array
    {
        $out = [];
        foreach (config('verticals.types') as $type => $cfg) {
            $out[$type] = (string) ($cfg['themes'] ?? 'food');
        }

        return $out;
    }

    public function test_every_vertical_registers_a_theme_in_its_own_set(): void
    {
        $sets = app(ThemeSets::class);

        foreach ($this->verticalSets() as $vertical => $expectedSet) {
            $theme = $this->publish('settest-v-'.str_replace('-', '', $vertical), ['vertical' => $vertical]);

            $this->assertSame($expectedSet, $theme->theme_set, "the {$vertical} theme landed in the wrong set");
            $this->assertTrue(
                $theme->fitsStore($vertical),
                "the {$vertical} theme is not offered to a {$vertical} store"
            );
            $this->assertTrue(
                $sets->isKnown($expectedSet),
                "the {$expectedSet} set is not registered"
            );
        }
    }

    public function test_a_hotel_theme_is_offered_to_a_hotel_store_and_not_to_a_restaurant(): void
    {
        $theme = $this->publish('settest-hotel', ['vertical' => 'hotel', 'recipe' => 'hotel/default']);
        [$owner, $hotel] = $this->store('hotel');
        [$owner2, $restaurant] = $this->store('restaurant');

        $this->assertSame('hotel', $theme->theme_set);
        $this->assertTrue($theme->fitsStore('hotel'));
        $this->assertFalse($theme->fitsStore('restaurant'));

        $this->actingAs($owner)
            ->postJson(route('marketplace.apply', $theme->slug), ['store_id' => $hotel->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($owner2)
            ->postJson(route('marketplace.apply', $theme->slug), ['store_id' => $restaurant->id])
            ->assertStatus(422);
    }

    public function test_a_laundry_theme_registers_the_service_set(): void
    {
        $theme = $this->publish('settest-service', ['vertical' => 'laundry']);

        $this->assertSame('service', $theme->theme_set);
        $this->assertTrue($theme->fitsStore('laundry'));
        $this->assertFalse($theme->fitsStore('restaurant'));
    }

    public function test_the_catalogue_accepts_a_set_from_the_query_string(): void
    {
        $this->publish('settest-food', ['vertical' => 'restaurant']);
        $this->publish('settest-hotel', ['vertical' => 'hotel']);

        $res = $this->get(route('marketplace.index', ['set' => 'hotel']));
        $res->assertOk();
        $res->assertSee('settest-hotel')->assertDontSee('settest-food');
    }

    public function test_an_unknown_set_in_the_query_string_is_not_found(): void
    {
        $this->publish('settest-food', ['vertical' => 'restaurant']);

        $this->get(route('marketplace.index', ['set' => 'nope']))->assertNotFound();
    }

    public function test_every_set_has_a_label_and_the_default_set_has_no_badge(): void
    {
        $sets = app(ThemeSets::class);

        $this->assertNull($sets->label('food'), 'the default set needs no badge');
        $this->assertSame(___('Retail'), $sets->label('retail'));
        $this->assertSame(___('Services'), $sets->label('service'));
        $this->assertSame(___('Hotel'), $sets->label('hotel'));

        $this->assertSame('Spa days', $sets->label('spa-days'));
        $this->assertSame(___('Food'), $sets->name('food'));
    }

    public function test_a_store_keeps_the_theme_it_already_runs_even_if_the_set_no_longer_matches(): void
    {
        $theme = $this->publish('settest-food', ['vertical' => 'restaurant']);
        [$owner, $clothing] = $this->store('clothing');
        StoreOption::updateStoreOption($clothing->id, 'store_template', 'mp:'.$theme->slug.'@1.0.0');

        $this->actingAs($owner)
            ->postJson(route('marketplace.apply', $theme->slug), ['store_id' => $clothing->id])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_a_manifest_naming_an_unknown_vertical_is_rejected(): void
    {
        $issues = ThemePackageValidator::manifestIssues((string) json_encode([
            'name' => 'Bad', 'slug' => 'bad', 'version' => '1.0.0', 'vertical' => 'clothsng',
        ]));

        $this->assertNotEmpty($issues, 'an unknown vertical must be rejected');
        $this->assertStringContainsString('vertical', $issues[0]);
    }

    public function test_a_manifest_naming_an_unknown_recipe_is_rejected(): void
    {
        $issues = ThemePackageValidator::manifestIssues((string) json_encode([
            'name' => 'Bad', 'slug' => 'bad', 'version' => '1.0.0', 'recipe' => 'clothing/nope',
        ]));

        $this->assertNotEmpty($issues, 'an unknown recipe must be rejected');
        $this->assertStringContainsString('recipe', $issues[0]);
    }

    public function test_non_string_vertical_or_recipe_is_rejected_without_conversion_error(): void
    {
        $verticalIssues = ThemePackageValidator::manifestIssues((string) json_encode([
            'name' => 'Bad', 'slug' => 'bad', 'version' => '1.0.0', 'vertical' => ['clothing'],
        ]));
        $this->assertNotEmpty($verticalIssues);
        $this->assertStringContainsString('vertical', $verticalIssues[0]);

        $recipeIssues = ThemePackageValidator::manifestIssues((string) json_encode([
            'name' => 'Bad', 'slug' => 'bad', 'version' => '1.0.0', 'recipe' => ['clothing/default'],
        ]));
        $this->assertNotEmpty($recipeIssues);
        $this->assertStringContainsString('recipe', $recipeIssues[0]);

        $this->assertSame('food', Theme::setForManifest([
            'vertical' => ['clothing'],
            'recipe' => ['clothing/default'],
        ]));
    }

    public function test_every_first_party_theme_manifest_in_the_repo_is_valid_and_lands_in_its_set(): void
    {
        $paths = glob(base_path('resources/liquid-themes/*/1/theme.json')) ?: [];
        $this->assertNotEmpty($paths, 'no first-party theme manifests found');

        $expectedByFamily = [
            'food' => 'food',
            'goods' => 'retail',
            'services' => 'service',
            'stays' => 'hotel',
        ];

        foreach ($paths as $path) {
            $raw = (string) file_get_contents($path);
            $issues = ThemePackageValidator::manifestIssues($raw);
            $this->assertSame([], $issues, basename(dirname($path, 2)).': '.implode('; ', $issues));

            /** @var array<string,mixed> $manifest */
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $vertical = (string) ($manifest['vertical'] ?? '');
            if (isset($expectedByFamily[$vertical])) {
                $this->assertSame(
                    $expectedByFamily[$vertical],
                    Theme::setForManifest($manifest),
                    basename(dirname($path, 2)).' landed in the wrong set'
                );
            }
        }
    }

    public function test_set_names_are_accepted_in_manifests(): void
    {
        foreach (['food', 'retail', 'service', 'hotel'] as $set) {
            $manifest = ['name' => 'T', 'slug' => 't-'.$set, 'version' => '1.0.0', 'vertical' => $set];
            $this->assertSame([], ThemePackageValidator::manifestIssues((string) json_encode($manifest)));
            $this->assertSame($set, Theme::setForManifest($manifest));
        }
    }

    public function test_theme_sets_all_returns_translated_labels(): void
    {
        $prev = app()->getLocale();
        app()->setLocale('ar');
        try {
            $all = app(ThemeSets::class)->all();
            $this->assertSame(___('Retail'), $all['retail']);
            $this->assertNotSame('Retail', $all['retail']);
        } finally {
            app()->setLocale($prev);
        }
    }
}
