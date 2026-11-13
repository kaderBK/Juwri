<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\Theme;
use App\Models\ThemeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Database\Seeders\StudioStarterThemesSeeder;
use Juwri\Modules\Storefront\Services\Theme\ContrastGuard;
use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B2: recipes (vertical-aware starting layouts) and the six
 * Studio starter themes built from them.
 */
class StudioRecipesTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED = [
        'restaurant/classic', 'restaurant/quick-order', 'restaurant/fine-dining', 'cafe/default',
        'bakery/default', 'retail/grocery', 'cosmetic/default', 'clothing/default',
        'carparts/default', 'pharmacy/default', 'laundry/default', 'hotel/default',
    ];

    /** Recipe vertical → the catalogue section it must carry. */
    private const CATALOGUE = [
        'restaurant' => 'menu-grid', 'cafe' => 'menu-grid', 'bakery' => 'menu-grid',
        'retail' => 'product-grid', 'cosmetic' => 'product-grid', 'clothing' => 'product-grid',
        'carparts' => 'product-grid', 'pharmacy' => 'product-grid',
        'laundry' => 'service-list', 'hotel' => 'room-list',
    ];

    /**
     * Sections that render nothing without store data or an enabled feature
     * (no stories / public offers / announcement / specials in a demo, or the
     * widget feature is off for that vertical) — absent is correct there.
     */
    private const DATA_GATED = [
        'announcement-bar', 'stories-rail', 'offers-strip', 'featured-rail', 'reviews',
        'loyalty-card', 'catering-cta', 'reservation-cta', 'branch-picker',
    ];

    /** Starter theme → [recipe, DemoStorefront dataset, catalogue section]. */
    private const STARTERS = [
        'studio-bistro' => ['restaurant/classic', 'restaurant', 'menu-grid'],
        'studio-express' => ['restaurant/quick-order', 'restaurant', 'menu-grid'],
        'studio-market' => ['retail/grocery', 'retail', 'product-grid'],
        'studio-boutique' => ['clothing/default', 'retail', 'product-grid'],
        'studio-services' => ['laundry/default', 'laundry', 'service-list'],
        'studio-stay' => ['hotel/default', 'hotel', 'room-list'],
    ];

    /** @var list<string> */
    private array $writtenMedia = [];

    protected function setUp(): void
    {
        parent::setUp();
        Recipes::flush();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'العربية', 'direction' => 'rtl', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenMedia as $path) {
            @unlink(public_path($path));
            @rmdir(dirname(public_path($path)));
        }
        parent::tearDown();
    }

    /** A library-1 theme to validate/render recipes against (no own sections). */
    private function libraryTheme(): MarketplaceTheme
    {
        $theme = MarketplaceThemeResolver::resolve('mp:studio-dev@1', gate: false);
        $this->assertNotNull($theme);

        return $theme;
    }

    /**
     * Strict (upload-mode) render of a whole sectioned template, globals built
     * like MarketplaceController@preview / studio:library-check.
     *
     * @param  array<string,mixed>|null  $layout
     */
    private function renderTheme(MarketplaceTheme $theme, string $dataset, string $lang, ?array $layout = null): string
    {
        $vars = DemoStorefront::vars($dataset);
        $vars['settings'] = [];
        $vars['default_menu_language'] = $vars['menu_languages']->firstWhere('code', $lang) ?? $vars['default_menu_language'];
        app()->setLocale($lang);

        $ctx = app(ThemeContext::class);
        $globals = $ctx->build($vars);
        $registers = $ctx->registers($vars)
            + app(CommerceRuntime::class)->registers($vars, $theme)
            + ['theme_asset_map' => $theme->assetMap(), 'theme_locale_map' => $theme->locales($lang)];

        try {
            return app(SectionedTemplate::class)->render($theme, $globals, $registers, [], 'upload', $layout);
        } finally {
            app()->setLocale('en');
        }
    }

    /* ─────────────────────────────── recipes ─────────────────────────────── */

    public function test_the_minimum_recipe_set_ships_and_is_well_formed(): void
    {
        $all = Recipes::all();
        foreach (self::REQUIRED as $id) {
            $this->assertArrayHasKey($id, $all, "recipe {$id}");
        }

        $palettes = [];
        foreach ($all as $id => $recipe) {
            $this->assertMatchesRegularExpression(Recipes::ID_PATTERN, $id);
            [$vertical] = explode('/', $id);
            $this->assertSame($vertical, $recipe['vertical'], $id);
            $this->assertNotSame('', $recipe['name'], $id);
            $this->assertNotSame('', $recipe['description'], $id);
            $this->assertNotEmpty($recipe['translations']['ar']['name'] ?? null, "{$id} Arabic name");
            $this->assertContains($recipe['priority'], Recipes::PRIORITIES, $id);
            $this->assertContains($recipe['font_pair'], FontPairs::ids(), $id);
            $this->assertContains($recipe['tokens']['layout.radius'] ?? null, Tokens::RADIUS_STEPS, $id);
            $this->assertContains($recipe['tokens']['layout.density'] ?? null, Tokens::DENSITY_STEPS, $id);
            foreach (Tokens::COLOR_ROLES as $role) {
                $this->assertNotNull(Tokens::normalizeHex($recipe['tokens']['colors.'.$role] ?? null), "{$id} colors.{$role}");
            }
            $palettes[] = $recipe['tokens']['colors.primary'].$recipe['tokens']['colors.background'];

            $layout = Recipes::layout($id);
            $this->assertNotNull($layout);
            $this->assertLessThanOrEqual(18, count($layout['sections']), "{$id} has more than 18 sections");
            $this->assertSame(array_keys($recipe['layout']['sections']), $layout['order'], "{$id} order lists every section once");

            $types = array_column($layout['sections'], 'type');
            $this->assertContains('header', $types, "{$id} needs the header (hosts the cart/account sheets)");
            $this->assertContains('footer', $types, $id);
            $this->assertContains(self::CATALOGUE[$vertical], $types, "{$id} catalogue section");
        }
        $this->assertSame(count($palettes), count(array_unique($palettes)), 'every recipe has its own palette');
    }

    public function test_vertical_specific_sections_are_where_they_belong(): void
    {
        $types = fn (string $id) => array_column(Recipes::layout($id)['sections'], 'type');

        $this->assertContains('fitment-search', $types('carparts/default'));
        $this->assertContains('pharmacy-notice', $types('pharmacy/default'));
        $this->assertContains('service-list', $types('laundry/default'));
        $this->assertContains('room-list', $types('hotel/default'));
        $this->assertContains('reservation-cta', $types('hotel/default'));
        $this->assertContains('dietary-filters', $types('restaurant/classic'));
        $this->assertNotContains('hero-cover', $types('restaurant/quick-order'), 'fast ordering skips the hero');

        foreach (Recipes::all() as $id => $recipe) {
            if ($recipe['vertical'] !== 'carparts') {
                $this->assertNotContains('fitment-search', $types($id), $id);
            }
            if ($recipe['vertical'] !== 'pharmacy') {
                $this->assertNotContains('pharmacy-notice', $types($id), $id);
            }
            if (! in_array($recipe['vertical'], ['restaurant', 'cafe', 'bakery'], true)) {
                $this->assertNotContains('menu-grid', $types($id), $id);
            }
        }
    }

    public function test_every_recipe_validates_against_the_library(): void
    {
        $theme = $this->libraryTheme();
        foreach (Recipes::all() as $id => $recipe) {
            $layout = LayoutDocument::sanitize($theme, Recipes::layout($id));
            $this->assertSame([], LayoutDocument::validate($theme, $layout), $id);
        }
    }

    public function test_every_recipe_renders_strict_against_its_vertical_demo_in_en_and_ar(): void
    {
        $theme = $this->libraryTheme();
        foreach (Recipes::all() as $id => $recipe) {
            $layout = LayoutDocument::sanitize($theme, Recipes::layout($id));
            $dataset = Recipes::DEMO_DATASET[$recipe['vertical']];
            foreach (['en', 'ar'] as $lang) {
                $html = $this->renderTheme($theme, $dataset, $lang, $layout);
                $this->assertStringContainsString('class="mbs-header', $html, "{$id} [{$lang}]");
                $this->assertStringContainsString('mbs-'.self::CATALOGUE[$recipe['vertical']], $html, "{$id} [{$lang}] catalogue");
                $this->assertSame(1, substr_count($html, 'window.MB_COMMERCE='), "{$id} [{$lang}] runtime once");
                // Everything but data/feature-gated sections is visibly there.
                foreach (array_diff(array_column($layout['sections'], 'type'), self::DATA_GATED) as $type) {
                    $this->assertStringContainsString('mbs-'.$type, $html, "{$id} [{$lang}] {$type}");
                }
            }
        }
    }

    public function test_recipe_palettes_pass_the_contrast_guard(): void
    {
        foreach (Recipes::all() as $id => $recipe) {
            $template = 'mp:studio-bistro@1';
            // Not vacuous: the library theme declares every colour role.
            $resolved = Tokens::resolve($template, $recipe['tokens']);
            foreach (Tokens::COLOR_ROLES as $role) {
                $this->assertSame(strtolower($recipe['tokens']['colors.'.$role]), strtolower((string) ($resolved['colors.'.$role] ?? '')), "{$id} {$role}");
            }

            $this->assertSame([], ContrastGuard::warnings($template, $recipe['tokens']), $id);

            $t = $recipe['tokens'];
            $this->assertGreaterThanOrEqual(ContrastGuard::AA_NORMAL, Tokens::contrastRatio(Tokens::readableInk($t['colors.accent']), $t['colors.accent']), "{$id} on-accent");
            $this->assertGreaterThanOrEqual(ContrastGuard::AA_NORMAL, Tokens::contrastRatio($t['colors.accent'], $t['colors.surface']), "{$id} accent on cards");
        }
    }

    public function test_for_vertical_maps_store_types_to_candidates(): void
    {
        $ids = fn (string $type, ?string $priority = null) => array_column(Recipes::forVertical($type, $priority), 'id');

        $store = $ids('restaurant');
        $this->assertSame('restaurant', explode('/', $store[0])[0]);
        $this->assertContains('cafe/default', $store);
        $this->assertContains('bakery/default', $store);
        $this->assertNotContains('retail/grocery', $store);
        $this->assertSame(['restaurant/classic', 'restaurant/fine-dining', 'restaurant/quick-order'], array_slice($store, 0, 3));

        // Priority ranks within the store's own vertical.
        $this->assertSame('restaurant/quick-order', $ids('restaurant', 'fast_ordering')[0]);
        $this->assertSame('restaurant/fine-dining', $ids('restaurant', 'brand_story')[0]);

        // Cafés and bakeries (no store type of their own) fall back to food.
        $this->assertSame('cafe/default', $ids('cafe')[0]);
        $this->assertContains('restaurant/classic', $ids('cafe'));
        $this->assertSame('bakery/default', $ids('bakery')[0]);

        $this->assertSame(['pharmacy/default', 'retail/grocery'], $ids('pharmacy'));
        $this->assertSame(['carparts/default', 'retail/grocery'], $ids('carparts'));
        $this->assertSame('clothing/default', $ids('clothing')[0]);
        $this->assertNotContains('pharmacy/default', $ids('clothing'));
        $this->assertSame(['laundry/default'], $ids('laundry'));
        $this->assertSame(['hotel/default'], $ids('hotel'));
        $this->assertSame(['hotel/default'], $ids('  HOTEL '));

        // Unknown store types get the registry default (food).
        $this->assertSame($store, $ids('spaceship'));
        $this->assertSame($store, $ids(''));
    }

    public function test_recipe_ids_are_path_safe(): void
    {
        $this->assertNotNull(Recipes::find('restaurant/classic'));
        foreach (['../restaurant/classic', 'restaurant/../hotel/default', 'restaurant/classic.json', '/etc/passwd', 'restaurant', 'Restaurant/Classic', 'restaurant/classic/x', ''] as $bad) {
            $this->assertNull(Recipes::find($bad), $bad);
            $this->assertNull(Recipes::layout($bad), $bad);
            $this->assertNull(Recipes::tokens($bad), $bad);
        }
        $this->assertSame([], Recipes::all('../1'));

        $layout = Recipes::layout('hotel/default');
        $this->assertSame(LayoutDocument::SCHEMA, $layout['schema']);
        $this->assertSame(Recipes::find('hotel/default')['font_pair'], Recipes::tokens('hotel/default')['fonts.pair']);
    }

    /* ──────────────────────────── starter themes ─────────────────────────── */

    public function test_starter_themes_lint_clean_and_match_their_recipe(): void
    {
        foreach (self::STARTERS as $slug => [$recipeId]) {
            $theme = MarketplaceThemeResolver::resolve("mp:{$slug}@1", gate: false);
            $this->assertNotNull($theme, $slug);
            $manifest = $theme->manifest();
            foreach (['name', 'slug', 'version', 'author', 'description', 'library', 'commerce_runtime', 'category', 'vertical'] as $key) {
                $this->assertNotEmpty($manifest[$key] ?? null, "{$slug} theme.json {$key}");
            }
            $this->assertSame($slug, $manifest['slug']);
            $this->assertSame('1', $manifest['version']);
            $this->assertSame('1', $theme->library()?->version);
            $this->assertSame('v2', $theme->commerceRuntimeVersion());
            $this->assertContains($manifest['category'], config('marketplace.theme_industries'), $slug);
            $this->assertSame($recipeId, $manifest['recipe']);

            $report = app(ThemeLinter::class)->lint($theme);
            $this->assertSame([], $report['errors'], $slug.': '.json_encode($report['errors']));

            // index.json = the recipe layout; theme.json "tokens" = the recipe palette
            // (the shell applies them under the store's own Design values).
            $recipe = Recipes::find($recipeId);
            $json = $theme->templateJson();
            $this->assertSame($recipe['layout']['order'], $json['order'], $slug);
            $this->assertEquals($recipe['layout']['sections'], $json['sections'], $slug);
            $this->assertSame([], LayoutDocument::validate($theme, LayoutDocument::sanitize($theme, LayoutDocument::normalize($json))), $slug);
            foreach ($recipe['tokens'] as $key => $value) {
                $this->assertSame($value, $manifest['tokens'][$key] ?? null, "{$slug} token {$key}");
            }
        }
    }

    public function test_starter_themes_render_strict_against_their_vertical_demo(): void
    {
        foreach (self::STARTERS as $slug => [, $dataset, $catalogue]) {
            $theme = MarketplaceThemeResolver::resolve("mp:{$slug}@1", gate: false);
            foreach (['en', 'ar'] as $lang) {
                $html = $this->renderTheme($theme, $dataset, $lang);
                $this->assertStringContainsString('mbs-'.$catalogue, $html, "{$slug} [{$lang}]");
                $this->assertSame(1, substr_count($html, 'window.MB_COMMERCE='), "{$slug} [{$lang}]");
            }
        }
    }

    public function test_a_starter_theme_renders_through_the_storefront_in_arabic(): void
    {
        $store = $this->store('mp:studio-bistro@1');
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['ar', 'en']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('assets/liquid-library/1/library.css?v=', $html);
        $this->assertStringContainsString('--tc-primary:#b3401f', str_replace(' ', '', $html), 'recipe palette applied by the shell');
        $this->assertStringContainsString('class="mbs-menu-grid', $html);
        $this->assertStringContainsString('Item', $html);
    }

    public function test_seeder_lists_the_starters_and_is_idempotent(): void
    {
        $this->seed(StudioStarterThemesSeeder::class);
        $this->seed(StudioStarterThemesSeeder::class);

        $themes = Theme::whereIn('slug', array_keys(self::STARTERS))->get();
        $this->assertCount(6, $themes);
        foreach ($themes as $theme) {
            foreach ($theme->media()->get() as $m) {
                $this->writtenMedia[] = $m->disk_path;
            }
            $this->assertSame('liquid', $theme->engine);
            $this->assertSame('published', $theme->status);
            $this->assertEquals(0, $theme->price);
            $this->assertNull($theme->seller_id);
            $this->assertContains($theme->category, config('marketplace.theme_industries'));
            $this->assertNotEmpty($theme->tagline);
            $this->assertNotEmpty($theme->translations['ar']['tagline'] ?? null);
            $this->assertNotEmpty($theme->translations['ar']['description'] ?? null);
            $this->assertSame(1, $theme->versions()->count(), $theme->slug);
            $this->assertSame(1, $theme->media()->count(), $theme->slug);
            $this->assertSame('published', $theme->versions()->first()->status);
            $this->assertSame('1', $theme->versions()->first()->semver);

            // The gated resolver (what the storefront uses) serves it.
            $this->assertNotNull(MarketplaceThemeResolver::resolve('mp:'.$theme->slug.'@1'), $theme->slug);
        }
        $this->assertSame(6, ThemeVersion::whereIn('theme_id', $themes->pluck('id'))->count());
        $this->assertSame('fine_dining', $themes->firstWhere('slug', 'studio-bistro')->category);
        $this->assertSame('fast_food', $themes->firstWhere('slug', 'studio-express')->category);
    }

    private function store(string $template): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return $store;
    }
}
