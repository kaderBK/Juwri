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
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\Drops\BlockDrop;
use Juwri\Modules\Storefront\Storefront\Drops\FeaturesDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B1a + B3: library v1 chrome/hero/info sections, the library
 * stylesheet build, the studio:library-check gate and the studio-dev theme.
 */
class StudioLibraryB1aTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = [
        'announcement-bar', 'header', 'hero-cover', 'brand-row', 'store-status',
        'hours', 'location', 'bottom-tabbar', 'floating-cart', 'footer',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
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
     * Render one library section exactly like SectionedTemplate (schema defaults + preset blocks), strict mode.
     *
     * @param  array<string,mixed>  $vars
     */
    private function renderSection(string $type, array $vars, string $lang, bool $withPresetBlocks = true): string
    {
        $library = $this->library();
        $source = (string) $library->sectionSource($type);
        $schema = SectionedTemplate::extractSchema($source);

        $blocks = [];
        if ($withPresetBlocks) {
            $defaults = [];
            foreach ($schema['blocks'] ?? [] as $def) {
                foreach ($def['settings'] ?? [] as $s) {
                    if (array_key_exists('default', $s)) {
                        $defaults[$def['type']][$s['id']] = $s['default'];
                    }
                }
            }
            foreach (array_values($schema['presets'][0]['blocks'] ?? []) as $i => $b) {
                $blocks[] = new BlockDrop('b'.$i, $b['type'], array_merge($defaults[$b['type']] ?? [], $b['settings'] ?? []));
            }
        }

        $vars['default_menu_language'] = $vars['menu_languages']->firstWhere('code', $lang) ?? $vars['default_menu_language'];
        app()->setLocale($lang);
        $vars['settings'] = [];
        $ctx = app(ThemeContext::class);
        $globals = $ctx->build($vars);
        $globals['section'] = new SectionDrop('t-'.$type, $type, SectionedTemplate::schemaDefaults($schema), $blocks);

        return app(LiquidRenderer::class)->render(
            $source,
            $globals,
            'upload',
            $ctx->registers($vars) + ['theme_locale_map' => $library->locales($lang)],
            $library->snippets(),
        );
    }

    public function test_library_ships_the_b1a_sections_with_schemas(): void
    {
        $types = $this->library()->sectionTypes();
        foreach (self::SECTIONS as $type) {
            $this->assertContains($type, $types);
            $schema = SectionedTemplate::extractSchema((string) $this->library()->sectionSource($type));
            $this->assertNotEmpty($schema['name'] ?? null, "{$type} schema name");
            foreach ($schema['settings'] ?? [] as $def) {
                $this->assertArrayHasKey('default', $def, "{$type}.{$def['id']} needs a default (strict mode)");
            }
        }
        foreach (['icon', 'media', 'open-badge', 'tabbar-tab'] as $snippet) {
            $this->assertArrayHasKey($snippet, $this->library()->snippets());
        }
    }

    public function test_every_section_renders_in_upload_mode_en_ar_features_on_and_off(): void
    {
        foreach (['en', 'ar'] as $lang) {
            foreach ([true, false] as $on) {
                foreach (self::SECTIONS as $type) {
                    $vars = DemoStorefront::vars();
                    $vars['extras']['features'] = $this->features($on);
                    $html = $this->renderSection($type, $vars, $lang);
                    $this->assertStringContainsString('class="mbs-'.$type, $html, "{$type} {$lang} features=".($on ? 'on' : 'off'));
                    $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*\bwidth=)[^>]*>/', $html, "{$type}: img without width");
                    $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*\bheight=)[^>]*>/', $html, "{$type}: img without height");
                    if ($type !== 'header') {
                        $this->assertStringNotContainsString('<script', $html, "{$type}: only the header hosts the runtime");
                    }
                }
            }
        }
    }

    public function test_arabic_render_uses_the_arabic_strings(): void
    {
        $html = $this->renderSection('hours', DemoStorefront::vars(), 'ar');
        $this->assertStringContainsString('الاثنين', $html);
        $this->assertStringContainsString('ساعات العمل', $html);
        $this->assertStringNotContainsString('Monday', $html);

        $status = $this->renderSection('store-status', DemoStorefront::vars(), 'ar');
        $this->assertStringContainsString('يغلق الساعة', $status, 'library locale fills keys the platform lacks');
    }

    public function test_sections_render_for_a_brand_new_store_and_respect_gates(): void
    {
        $demo = DemoStorefront::vars();
        $store = new Store(['title' => 'Fresh', 'slug' => 'fresh']);
        $store->id = 0;
        $vars = array_merge($demo, [
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [],
            'menuBanners' => collect(), 'branches' => collect(), 'flags' => ['allow_order' => false],
            'extras' => ['hours' => [], 'stories' => [], 'social' => [], 'announcement' => null, 'features' => []],
        ]);

        foreach (['en', 'ar'] as $lang) {
            foreach (self::SECTIONS as $type) {
                foreach ([true, false] as $blocks) {
                    $html = $this->renderSection($type, $vars, $lang, $blocks);
                    $this->assertStringNotContainsString('data-mb-open-cart', $html, "{$type}: no cart without ordering");
                    $this->assertStringNotContainsString('data-mb-tab="cart"', $html);
                }
            }
        }

        $this->assertSame('', trim($this->renderSection('announcement-bar', $vars, 'en')), 'announcement off → nothing');
        $this->assertSame('', trim($this->renderSection('floating-cart', $vars, 'en')));
        $this->assertSame('', trim($this->renderSection('hours', $vars, 'en')), 'no schedule → nothing');
        $this->assertSame('', trim($this->renderSection('store-status', $vars, 'en')));
        $this->assertSame('', trim($this->renderSection('location', $vars, 'en')));
        $footer = $this->renderSection('footer', $vars, 'en');
        $this->assertStringContainsString('mbs-footer', $footer);
        $this->assertStringNotContainsString('mbs-footer__social', $footer);
    }

    public function test_owner_text_is_escaped(): void
    {
        $vars = DemoStorefront::vars();
        $vars['extras']['announcement'] = ['text' => '<img src=x onerror=alert(1)>', 'key' => 'k'];
        $vars['store']->title = '<b>Bistro</b>';

        $bar = $this->renderSection('announcement-bar', $vars, 'en');
        $this->assertStringNotContainsString('<img src=x', $bar);
        $this->assertStringContainsString('&lt;img src=x', $bar);

        $header = $this->renderSection('header', $vars, 'en');
        $this->assertStringNotContainsString('<b>Bistro</b>', $header);
    }

    public function test_hero_only_prioritises_its_image_and_header_hosts_the_runtime_once(): void
    {
        $vars = DemoStorefront::vars();
        $vars['store']->cover_image = 'cover.jpg';
        $hero = $this->renderSection('hero-cover', $vars, 'en');
        $this->assertSame(1, substr_count($hero, 'fetchpriority="high"'));
        $this->assertStringNotContainsString('100vh', $hero);

        $header = $this->renderSection('header', DemoStorefront::vars(), 'en');
        foreach (['data-mb-view="cart"', 'data-mb-view="profile"', 'data-mb-cart-mount', 'data-mb-account-mount', 'data-mb-item-sheet-mount', 'data-mb-lang-sheet', 'data-mb-lang-open'] as $hook) {
            $this->assertStringContainsString($hook, $header);
        }
        foreach (array_diff(self::SECTIONS, ['header']) as $type) {
            $this->assertStringNotContainsString('data-mb-cart-mount', $this->renderSection($type, DemoStorefront::vars(), 'en'), "{$type} must not duplicate runtime mounts");
        }
    }

    public function test_library_check_command_passes(): void
    {
        $exit = Artisan::call('studio:library-check', ['--library' => '1']);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('All library sections render cleanly', $output);
    }

    public function test_library_check_rejects_an_unknown_version(): void
    {
        $this->assertSame(1, Artisan::call('studio:library-check', ['--library' => '999']));
    }

    public function test_built_library_css_is_scoped_logical_and_in_budget(): void
    {
        $path = public_path('assets/liquid-library/1/library.css');
        $this->assertFileExists($path, 'run node tools/liquid-library/build.mjs');
        $css = (string) file_get_contents($path);
        // Budget (spec §7): ≤ 15 KB gzipped is the hard limit (the real transfer), ≤ 96 KB raw.
        $this->assertLessThanOrEqual(96 * 1024, strlen($css));
        $this->assertLessThanOrEqual(15 * 1024, strlen((string) gzencode($css, 9)));

        $names = array_merge($this->library()->sectionTypes(), array_keys($this->library()->snippets()));
        $selectors = $this->selectors((string) preg_replace('#/\*.*?\*/#s', '', $css));
        $this->assertNotEmpty($selectors);
        foreach ($selectors as $selector) {
            // Runtime skin (src/runtime): the commerce runtime's own .mb-* markup (StudioRuntimeSkinTest).
            if (preg_match('/^(dialog)?\.mb-[a-z0-9]/', $selector)) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^\.mbs-[a-z0-9-]+/', $selector, "unscoped selector: {$selector}");
            $this->assertTrue($this->scopedToKnownName($selector, $names), "selector not scoped to a library section/snippet: {$selector}");
        }

        $this->assertDoesNotMatchRegularExpression('/(?<![\w-])(margin|padding)-(left|right)\s*:|(?<![\w-])(left|right)\s*:|text-align\s*:\s*(left|right)|float\s*:\s*(left|right)/', $css);
        $this->assertStringNotContainsString('url(', $css);
        $this->assertStringNotContainsString('100vh', $css);

        foreach (self::SECTIONS as $type) {
            $this->assertStringContainsString('.mbs-'.$type, $css);
            $this->assertFileExists(base_path('../tools/liquid-library/src/sections/'.$type.'.css'));
        }
    }

    public function test_studio_dev_theme_lints_clean(): void
    {
        $theme = MarketplaceThemeResolver::resolve('mp:studio-dev@1', gate: false);
        $this->assertNotNull($theme);
        $this->assertSame('1', $theme->library()?->version);

        $report = app(ThemeLinter::class)->lint($theme);
        $this->assertSame([], $report['errors'], json_encode($report['errors']));
    }

    public function test_studio_dev_renders_through_the_storefront_in_arabic(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'العربية', 'direction' => 'rtl', 'active' => 1]);
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['ar', 'en']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('assets/liquid-library/1/library.css?v=', $html);
        foreach (['header', 'hero-cover', 'brand-row', 'footer', 'bottom-tabbar'] as $type) {
            $this->assertStringContainsString('class="mbs-'.$type, $html, $type);
        }
        $this->assertStringContainsString('الرئيسية', $html, 'Arabic tab labels');
        $this->assertSame(1, substr_count($html, 'window.MB_COMMERCE='), 'runtime emitted exactly once');
        $this->assertSame(1, substr_count($html, 'data-mb-item-sheet-mount'));
    }

    public function test_studio_dev_renders_ltr_for_an_english_store(): void
    {
        $store = $this->store();
        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('class="mbs-header', $html);
        $this->assertStringContainsString('Item', $html);
    }

    private function store(): Store
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
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:studio-dev@1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return $store;
    }

    /**
     * Every selector (comma part) of a minified stylesheet, @media/@supports
     * bodies included, @keyframes frames skipped.
     *
     * @return list<string>
     */
    private function selectors(string $css): array
    {
        $out = [];
        $depth = 0;
        $buf = '';
        $skipUntilDepth = null;
        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            $c = $css[$i];
            if ($c === '{') {
                $prelude = trim($buf);
                $buf = '';
                if ($skipUntilDepth === null) {
                    if (str_starts_with($prelude, '@keyframes')) {
                        $skipUntilDepth = $depth;
                    } elseif (! str_starts_with($prelude, '@')) {
                        foreach (explode(',', $prelude) as $part) {
                            $out[] = trim($part);
                        }
                        // skip the declaration block
                        $end = strpos($css, '}', $i);
                        $i = $end === false ? $len : $end;

                        continue;
                    }
                }
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                $buf = '';
                if ($skipUntilDepth !== null && $depth === $skipUntilDepth) {
                    $skipUntilDepth = null;
                }
            } else {
                $buf .= $c;
            }
        }

        return array_values(array_filter($out, fn ($s) => $s !== ''));
    }

    /** @param  list<string>  $names */
    private function scopedToKnownName(string $selector, array $names): bool
    {
        foreach ($names as $name) {
            $root = '.mbs-'.$name;
            if (str_starts_with($selector, $root)) {
                $rest = substr($selector, strlen($root));
                if ($rest === '' || str_starts_with($rest, '__') || str_starts_with($rest, '--') || preg_match('/^[^a-zA-Z0-9_-]/', $rest)) {
                    return true;
                }
            }
        }

        return false;
    }
}
