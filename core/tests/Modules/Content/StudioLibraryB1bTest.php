<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\Drops\FeaturesDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Symfony\Component\Process\Process;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B1b: library v1 "Discover" + food "Catalogue" + "Engage"
 * sections (search-bar, dietary-filters, category-nav, featured-rail,
 * menu-grid, stories-rail, banners-slider, offers-strip) and their card /
 * price / badges / rating snippets.
 */
class StudioLibraryB1bTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = [
        'search-bar', 'dietary-filters', 'category-nav', 'featured-rail',
        'menu-grid', 'stories-rail', 'banners-slider', 'offers-strip',
    ];

    private const SNIPPETS = [
        'card-photo', 'card-row', 'card-compact', 'card-magazine', 'price', 'badges', 'rating-stars',
    ];

    /** @var list<string> theme fixture dirs to remove */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function library(): SectionLibrary
    {
        $library = SectionLibrary::forVersion('1');
        $this->assertNotNull($library);

        return $library;
    }

    /** @return array<string,bool> */
    private function features(bool $on): array
    {
        $out = [];
        foreach (new \ReflectionClass(FeaturesDrop::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === FeaturesDrop::class && ! $m->isConstructor() && ! $m->isStatic()) {
                $out[$m->getName()] = $on;
            }
        }

        return $out;
    }

    /**
     * The demo storefront with every B1b data source populated: an offer, a
     * daily special, a sold-out item, a rated item, stories and a banner link.
     *
     * @return array<string,mixed>
     */
    private function richVars(): array
    {
        $vars = DemoStorefront::vars();
        $menus = $vars['total_menus'];
        $menus[9402]->setAttribute('original_price', 10.0);   // Garden Salad 7.00 ← 10.00 (30% off)
        $menus[9402]->setAttribute('schedule_label', 'Happy hour');
        $menus[9401]->is_daily_special = true;
        $menus[9401]->rating_avg = 4.5;
        $menus[9401]->rating_count = 12;
        $menus[9403]->is_sold_out = true;                      // Mint Tea
        $vars['menuBanners']->first()->link_url = 'https://example.com/deal';
        $vars['extras']['stories'] = [
            ['id' => 1, 'image_url' => 'https://cdn.example.com/s1.jpg', 'caption' => 'New menu', 'cta_label' => null, 'cta_url' => '/new-menu'],
            ['id' => 2, 'image_url' => 'https://cdn.example.com/s2.jpg', 'caption' => '', 'cta_label' => null, 'cta_url' => null],
        ];

        return $vars;
    }

    /** @return array<string,mixed> a brand-new store: nothing configured, ordering off */
    private function emptyVars(): array
    {
        $demo = DemoStorefront::vars();
        $store = new Store(['title' => 'Fresh', 'slug' => 'fresh']);
        $store->id = 0;

        return array_merge($demo, [
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [],
            'menuBanners' => collect(), 'branches' => collect(), 'flags' => ['allow_order' => false],
            'extras' => ['hours' => [], 'stories' => [], 'social' => [], 'announcement' => null, 'features' => []],
        ]);
    }

    /**
     * Render one library section exactly like SectionedTemplate (schema
     * defaults ← overrides), strict upload mode.
     *
     * @param  array<string,mixed>  $vars
     * @param  array<string,mixed>  $settings
     */
    private function render(string $type, array $vars, string $lang = 'en', array $settings = [], bool $commerce = false): string
    {
        $library = $this->library();
        $source = (string) $library->sectionSource($type);
        $schema = SectionedTemplate::extractSchema($source);

        $vars['default_menu_language'] = $vars['menu_languages']->firstWhere('code', $lang) ?? $vars['default_menu_language'];
        app()->setLocale($lang);
        $vars['settings'] = [];
        $ctx = app(ThemeContext::class);
        $globals = $ctx->build($vars);
        $globals['section'] = new SectionDrop('t-'.$type, $type, array_merge(SectionedTemplate::schemaDefaults($schema), $settings), []);

        $registers = $ctx->registers($vars) + ['theme_locale_map' => $library->locales($lang)];
        if ($commerce) {
            $registers += app(CommerceRuntime::class)->registers($vars);
        }

        return app(LiquidRenderer::class)->render($source, $globals, 'upload', $registers, $library->snippets());
    }

    public function test_library_ships_the_b1b_sections_snippets_and_strings(): void
    {
        $library = $this->library();
        foreach (self::SECTIONS as $type) {
            $this->assertContains($type, $library->sectionTypes());
            $schema = SectionedTemplate::extractSchema((string) $library->sectionSource($type));
            $this->assertNotEmpty($schema['name'] ?? null, "{$type} schema name");
            $this->assertNotEmpty($schema['presets'] ?? null, "{$type} preset");
            foreach ($schema['settings'] ?? [] as $def) {
                $this->assertArrayHasKey('default', $def, "{$type}.{$def['id']} needs a default (strict mode)");
            }
            $this->assertFileExists(base_path('../tools/liquid-library/src/sections/'.$type.'.css'));
        }
        foreach (self::SNIPPETS as $snippet) {
            $this->assertArrayHasKey($snippet, $library->snippets());
            $this->assertFileExists(base_path('../tools/liquid-library/src/snippets/'.$snippet.'.css'));
        }

        $en = json_decode((string) file_get_contents($library->path('locales/en/b1b.json')), true);
        $ar = json_decode((string) file_get_contents($library->path('locales/ar/b1b.json')), true);
        $this->assertIsArray($en);
        $this->assertIsArray($ar);
        $this->assertSame(array_keys($en), array_keys($ar), 'en and ar fragments carry the same keys');
        foreach ($ar as $key => $value) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $value, "ar string for '{$key}' is Arabic");
        }
    }

    public function test_every_section_renders_strict_in_en_and_ar_with_features_on_and_off(): void
    {
        foreach (['en', 'ar'] as $lang) {
            foreach ([true, false] as $on) {
                foreach (self::SECTIONS as $type) {
                    $vars = $this->richVars();
                    $vars['extras']['features'] = $this->features($on);
                    $html = $this->render($type, $vars, $lang);
                    $where = "{$type} {$lang} features=".($on ? 'on' : 'off');

                    if ($type === 'dietary-filters' && ! $on) {
                        $this->assertSame('', trim($html), "{$where}: gated on features.dietary_filters");

                        continue;
                    }
                    $this->assertStringContainsString('class="mbs-'.$type, $html, $where);
                    $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*\bwidth=)[^>]*>/', $html, "{$where}: img without width");
                    $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*\bheight=)[^>]*>/', $html, "{$where}: img without height");
                    $this->assertStringNotContainsString('100vh', $html);
                }
            }
        }
    }

    public function test_every_menu_grid_card_style_renders_with_its_hooks(): void
    {
        $cards = ['photo-card' => 'mbs-card-photo', 'list-row' => 'mbs-card-row', 'compact' => 'mbs-card-compact',
            'magazine' => 'mbs-card-magazine', 'text-only' => 'mbs-card-row'];
        foreach ($cards as $card => $class) {
            foreach (['en', 'ar'] as $lang) {
                $html = $this->render('menu-grid', $this->richVars(), $lang, ['card' => $card]);
                $this->assertStringContainsString('class="'.$class, $html, $card);
                $this->assertSame(3, substr_count($html, 'data-mb-item="'), "{$card}: every item is a catalogue card");
                $this->assertSame(3, substr_count($html, 'data-mb-open="'), "{$card}: every item opens");
                $this->assertSame(2, substr_count($html, 'data-mb-add="'), "{$card}: sold-out Mint Tea is not addable");
                $this->assertStringNotContainsString('data-mb-add="9403"', $html);
                $this->assertStringContainsString('href="?item=9401"', $html, 'deep link');
                $this->assertSame(2, preg_match_all('/data-mb-cat(?![\w-])/', $html), 'one block per category');
                $this->assertStringContainsString('id="mbs-cat-9301"', $html, 'category-nav anchor target');
                $this->assertStringContainsString('data-mb-catalog', $html);
                $this->assertStringContainsString('data-mb-empty', $html);
                $this->assertStringContainsString('data-mb-sort', $html);
                $this->assertStringContainsString('data-mb-veg', $html);
                $this->assertStringContainsString('data-mb-money="10"', $html, 'struck-through regular price');
                if ($card === 'text-only') {
                    $this->assertStringNotContainsString('<img', $html);
                }
            }
        }

        $text = $this->render('menu-grid', $this->richVars(), 'en', ['card' => 'photo-card']);
        $this->assertMatchesRegularExpression('/data-mb-money="9.5">[^<]*9\.50/u', $text, 'prices via | money');
        $this->assertStringContainsString('Sold out', $text);
        $this->assertStringContainsString('30% off', $text);
        $this->assertStringContainsString('Rated 4.5 out of 5', $text);
        $this->assertStringContainsString('--mbs-cols-m: 2; --mbs-cols-d: 3', $text);
        $this->assertStringNotContainsString('fetchpriority', $text, 'catalogue images lazy-load by default');

        $eager = $this->render('menu-grid', $this->richVars(), 'en', ['eager_first' => true]);
        $this->assertSame(1, substr_count($eager, 'fetchpriority="high"'), 'only the first image when opted in');

        $magazine = $this->render('menu-grid', $this->richVars(), 'en', ['card' => 'magazine']);
        $this->assertStringContainsString('Add to order', $magazine, 'magazine add button uses vocab.add_to_cart');
    }

    public function test_menu_grid_hides_add_hooks_when_the_store_does_not_take_orders(): void
    {
        $vars = $this->richVars();
        $vars['flags']['allow_order'] = false;
        foreach (['photo-card', 'list-row', 'compact', 'magazine', 'text-only'] as $card) {
            $html = $this->render('menu-grid', $vars, 'en', ['card' => $card]);
            $this->assertStringNotContainsString('data-mb-add', $html, $card);
            $this->assertSame(3, substr_count($html, 'data-mb-open="'), "{$card}: items still open");
        }
        foreach (['featured-rail', 'offers-strip'] as $type) {
            $this->assertStringNotContainsString('data-mb-add', $this->render($type, $vars, 'en', $type === 'featured-rail' ? ['source' => 'offers'] : []));
        }
    }

    public function test_menu_grid_emits_the_commerce_runtime(): void
    {
        $html = $this->render('menu-grid', $this->richVars(), 'en', [], commerce: true);
        $this->assertSame(1, substr_count($html, 'window.MB_COMMERCE='));
    }

    public function test_arabic_render_uses_arabic_strings(): void
    {
        $vars = $this->richVars();
        $grid = $this->render('menu-grid', $vars, 'ar');
        $this->assertStringContainsString('خصم 30%', $grid);
        $this->assertStringContainsString('التقييم 4.5 من 5', $grid);
        $this->assertStringNotContainsString('Sold out', $grid);
        $this->assertStringNotContainsString('Rated 4.5', $grid);

        $search = $this->render('search-bar', $vars, 'ar');
        $this->assertMatchesRegularExpression('/placeholder="[^"]*\p{Arabic}/u', $search);
        $this->assertStringNotContainsString(':items', $search);

        $slider = $this->render('banners-slider', $vars, 'ar');
        $this->assertStringContainsString('الشريحة 1 من 1', $slider);
    }

    public function test_sources_rails_and_gates(): void
    {
        $vars = $this->richVars();

        $popular = $this->render('featured-rail', $vars);
        $this->assertStringContainsString('data-mb-open="9401"', $popular);
        $this->assertStringNotContainsString('data-mb-item=', $popular, 'rail cards stay out of search / filter');

        $specials = $this->render('featured-rail', $vars, 'en', ['source' => 'specials']);
        $this->assertStringContainsString('Today&#039;s specials', $specials);

        $category = $this->render('featured-rail', $vars, 'en', ['source' => 'category', 'category' => 9302]);
        $this->assertStringContainsString('data-mb-open="9403"', $category);
        $this->assertStringNotContainsString('data-mb-open="9401"', $category);

        $picked = $this->render('featured-rail', $vars, 'en', ['source' => 'picked', 'items' => ['9403', 9402], 'card' => 'compact']);
        $this->assertLessThan(strpos($picked, 'data-mb-open="9402"'), strpos($picked, 'data-mb-open="9403"'), 'pick order kept');
        $this->assertStringNotContainsString('data-mb-open="9401"', $picked);

        $this->assertSame('', trim($this->render('featured-rail', $vars, 'en', ['source' => 'category', 'category' => ''])));
        $this->assertSame('', trim($this->render('featured-rail', $vars, 'en', ['source' => 'picked'])));

        $offers = $this->render('offers-strip', $vars);
        $this->assertStringContainsString('data-mb-open="9402"', $offers);
        $this->assertStringContainsString('Happy hour', $offers);
        $this->assertStringContainsString('30% off', $offers);

        $filters = $this->render('dietary-filters', $vars);
        foreach (['all', 'fav', 'special', 'vegan'] as $f) {
            $this->assertStringContainsString('data-mb-filter="'.$f.'"', $filters);
        }
        $this->assertStringNotContainsString('data-mb-filter="halal"', $filters, 'no halal item → no chip');
        $all = $this->render('dietary-filters', $vars, 'en', ['only_used' => false]);
        $this->assertStringContainsString('data-mb-filter="halal"', $all);

        $nav = $this->render('category-nav', $vars, 'en', ['variant' => 'image-tiles', 'show_count' => true]);
        $this->assertStringContainsString('href="#mbs-cat-9301"', $nav);
        $this->assertStringContainsString('href="#mbs-cat-9302"', $nav);
        foreach (['chips', 'tabs'] as $variant) {
            $this->assertStringContainsString('mbs-category-nav--'.$variant, $this->render('category-nav', $vars, 'en', ['variant' => $variant]));
        }

        $search = $this->render('search-bar', $vars, 'en', ['sticky' => true]);
        $this->assertStringContainsString('data-mb-search', $search);
        $this->assertStringContainsString('placeholder="Search dishes"', $search);
        $this->assertStringContainsString('is-sticky', $search);

        $stories = $this->render('stories-rail', $vars);
        $this->assertSame(2, substr_count($stories, 'mbs-stories-rail__item'));
        $this->assertStringContainsString('href="/new-menu"', $stories);

        $slider = $this->render('banners-slider', $vars);
        $this->assertStringContainsString('href="https://example.com/deal"', $slider);
        $this->assertStringNotContainsString('data-mb-slider', $slider, 'a single banner never autoplays');
        $this->assertSame(1, substr_count($slider, 'fetchpriority="high"'), 'first banner loads eagerly by default');
    }

    public function test_banners_autoplay_with_two_or_more_banners(): void
    {
        $vars = $this->richVars();
        $second = $vars['menuBanners']->first()->replicate();
        $second->id = 9502;
        $vars['menuBanners']->push($second);

        $html = $this->render('banners-slider', $vars, 'en', ['interval' => 7]);
        $this->assertStringContainsString('data-mb-slider data-mb-slider-interval="7000"', $html);
        $this->assertStringNotContainsString('data-mb-slider', $this->render('banners-slider', $vars, 'en', ['autoplay' => false]));
    }

    public function test_sections_are_empty_store_safe(): void
    {
        $vars = $this->emptyVars();
        foreach (['en', 'ar'] as $lang) {
            foreach (self::SECTIONS as $type) {
                $html = $this->render($type, $vars, $lang);
                $this->assertStringNotContainsString('data-mb-add', $html, $type);
                if ($type !== 'menu-grid') {
                    $this->assertSame('', trim($html), "{$type} renders nothing for an empty store");
                }
            }
        }

        $grid = $this->render('menu-grid', $vars, 'en');
        $this->assertStringContainsString('mbs-menu-grid__empty', $grid);
        $this->assertStringNotContainsString('data-mb-sort', $grid);
    }

    public function test_owner_and_item_text_is_escaped_and_unsafe_links_dropped(): void
    {
        $vars = $this->richVars();
        $vars['total_menus'][9401]->name = '<img src=x onerror=alert(1)>';
        $vars['menuBanners']->first()->link_url = 'javascript:alert(1)';
        $vars['menuBanners']->first()->title = '<b>Deal</b>';
        $vars['extras']['stories'][0]['cta_url'] = '//evil.example';
        $vars['extras']['stories'][0]['caption'] = '<script>x</script>';

        foreach (['menu-grid', 'featured-rail'] as $type) {
            $html = $this->render($type, $vars);
            $this->assertStringNotContainsString('<img src=x', $html, $type);
            $this->assertStringContainsString('&lt;img src=x', $html, $type);
        }
        $slider = $this->render('banners-slider', $vars);
        $this->assertStringNotContainsString('javascript:', $slider);
        $this->assertStringNotContainsString('<b>Deal</b>', $slider);
        $stories = $this->render('stories-rail', $vars);
        $this->assertStringNotContainsString('evil.example', $stories);
        $this->assertStringNotContainsString('<script>', $stories);

        $search = $this->render('search-bar', $vars, 'en', ['placeholder' => '"><b>x']);
        $this->assertStringNotContainsString('"><b>', $search);
    }

    public function test_page_with_header_and_menu_grid_emits_one_runtime_and_gates_ordering(): void
    {
        $slug = $this->themeFixture();

        $ordering = $this->store($slug, true);
        $html = $this->get(route('publicView', $ordering->slug))->assertOk()->getContent();
        $this->assertStringContainsString('class="mbs-header', $html);
        $this->assertStringContainsString('class="mbs-menu-grid', $html);
        $this->assertStringContainsString('class="mbs-category-nav', $html);
        $this->assertSame(1, substr_count($html, 'window.MB_COMMERCE='), 'runtime emitted exactly once');
        $this->assertSame(1, substr_count($html, 'data-mb-item-sheet-mount'));
        $this->assertMatchesRegularExpression('/data-mb-add="\d+"/', $html);
        $this->assertMatchesRegularExpression('/data-mb-open="\d+"/', $html);
        $this->assertStringContainsString('id="mb-section-menu"', $html);

        $viewOnly = $this->store($slug, false);
        $html = $this->get(route('publicView', $viewOnly->slug))->assertOk()->getContent();
        $this->assertStringContainsString('class="mbs-menu-grid', $html);
        $this->assertStringNotContainsString('data-mb-add=', $html);
        $this->assertMatchesRegularExpression('/data-mb-open="\d+"/', $html);
        $this->assertSame(1, substr_count($html, 'window.MB_COMMERCE='));
    }

    public function test_library_check_passes(): void
    {
        $exit = Artisan::call('studio:library-check', ['--library' => '1']);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('All library sections render cleanly', $output);
    }

    public function test_library_css_build_is_current_and_in_budget(): void
    {
        $node = $this->node();
        if ($node === null) {
            $this->markTestSkipped('node is not available');
        }
        $process = new Process([$node, base_path('../tools/liquid-library/build.mjs'), '--check']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        $css = (string) file_get_contents(public_path('assets/liquid-library/1/library.css'));
        // Budget (spec §7): ≤ 15 KB gzipped is the hard limit (the real transfer), ≤ 96 KB raw.
        $this->assertLessThanOrEqual(96 * 1024, strlen($css));
        $this->assertLessThanOrEqual(15 * 1024, strlen((string) gzencode($css, 9)));
        foreach (array_merge(self::SECTIONS, self::SNIPPETS) as $name) {
            $this->assertStringContainsString('.mbs-'.$name, $css, $name);
        }
        $this->assertStringContainsString('.mbs-card-photo[hidden]{display:none}', $css, 'filtered cards must hide');
    }

    private function node(): ?string
    {
        foreach (array_filter([getenv('NODE_BINARY') ?: null, trim((string) @shell_exec('command -v node 2>/dev/null'))]) as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        foreach (glob((getenv('HOME') ?: '').'/.nvm/versions/node/*/bin/node') ?: [] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    /** A throwaway sectioned theme pinned to library 1 (header + discover + catalogue + engage). */
    private function themeFixture(): string
    {
        $slug = 'b1b-test-'.Str::lower(Str::random(8));
        $dir = storage_path('app/marketplace-themes/'.$slug.'/1');
        $this->fixtures[] = dirname($dir);
        File::ensureDirectoryExists($dir.'/templates');
        File::put($dir.'/theme.json', (string) json_encode([
            'name' => 'B1b test', 'slug' => $slug, 'version' => '1.0.0', 'author' => 'test',
            'min_platform_version' => '2.0', 'source' => 'liquid', 'library' => '1',
        ]));
        $sections = [
            'header' => ['type' => 'header'],
            'banners' => ['type' => 'banners-slider'],
            'stories' => ['type' => 'stories-rail'],
            'search' => ['type' => 'search-bar'],
            'filters' => ['type' => 'dietary-filters'],
            'cats' => ['type' => 'category-nav'],
            'offers' => ['type' => 'offers-strip'],
            'popular' => ['type' => 'featured-rail'],
            'menu' => ['type' => 'menu-grid'],
        ];
        File::put($dir.'/templates/index.json', (string) json_encode(['sections' => $sections, 'order' => array_keys($sections)]));

        return $slug;
    }

    private function store(string $themeSlug, bool $ordering): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => $ordering ? 1 : 0, 'table_qr' => 0,
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
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:'.$themeSlug.'@1');
        if ($ordering) {
            StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        }
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return $store;
    }
}
