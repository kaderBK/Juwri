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
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\Drops\FeaturesDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B1c: vertical library sections (product-grid, service-list,
 * room-list, fitment-search, pharmacy-notice), their card/stock snippets,
 * the vertical DemoStorefront datasets and the library-check across them.
 */
class StudioLibraryB1cTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = ['product-grid', 'service-list', 'room-list', 'fitment-search', 'pharmacy-notice'];

    private const SNIPPETS = ['card-product', 'card-service', 'card-room', 'stock-badge'];

    private const VERTICALS = ['restaurant', 'retail', 'pharmacy', 'laundry', 'hotel', 'carparts'];

    /** @var list<string> */
    private array $tmpDirs = [];

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
        foreach ($this->tmpDirs as $dir) {
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
     * Render one library section in strict upload mode (schema defaults + overrides).
     *
     * @param  array<string,mixed>  $vars
     * @param  array<string,mixed>  $settings
     */
    private function render(string $type, array $vars, string $lang = 'en', array $settings = []): string
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

        return app(LiquidRenderer::class)->render(
            $source,
            $globals,
            'upload',
            $ctx->registers($vars) + ['theme_locale_map' => $library->locales($lang)],
            $library->snippets(),
        );
    }

    /** @return array<string,mixed> */
    private function emptyStore(bool $featuresOn): array
    {
        $store = new Store(['title' => 'Fresh', 'slug' => 'fresh']);
        $store->id = 0;

        return array_merge(DemoStorefront::vars(), [
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [],
            'menuBanners' => collect(), 'branches' => collect(), 'flags' => ['allow_order' => false],
            'extras' => ['hours' => [], 'stories' => [], 'social' => [], 'announcement' => null, 'features' => $this->features($featuresOn)],
        ]);
    }

    public function test_library_ships_the_b1c_sections_and_snippets_with_schemas(): void
    {
        $types = $this->library()->sectionTypes();
        foreach (self::SECTIONS as $type) {
            $this->assertContains($type, $types);
            $schema = SectionedTemplate::extractSchema((string) $this->library()->sectionSource($type));
            $this->assertNotEmpty($schema['name'] ?? null, "{$type} schema name");
            $this->assertNotEmpty($schema['verticals'] ?? null, "{$type} declares its verticals");
            foreach ($schema['settings'] ?? [] as $def) {
                $this->assertArrayHasKey('default', $def, "{$type}.{$def['id']} needs a default (strict mode)");
            }
            $this->assertFileExists(base_path('../tools/liquid-library/src/sections/'.$type.'.css'));
        }
        foreach (self::SNIPPETS as $snippet) {
            $this->assertArrayHasKey($snippet, $this->library()->snippets());
            $this->assertFileExists(base_path('../tools/liquid-library/src/snippets/'.$snippet.'.css'));
        }
    }

    public function test_every_section_strict_renders_for_every_vertical_in_en_and_ar(): void
    {
        foreach (self::VERTICALS as $vertical) {
            foreach (['en', 'ar'] as $lang) {
                foreach (self::SECTIONS as $type) {
                    $html = $this->render($type, DemoStorefront::vars($vertical), $lang);
                    $where = "{$type} [{$vertical} {$lang}]";
                    if (trim($html) !== '') {
                        $this->assertStringContainsString('class="mbs-'.$type, $html, $where);
                    }
                    $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*\bwidth=)[^>]*>/', $html, "{$where}: img without width");
                    $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*\bheight=)[^>]*>/', $html, "{$where}: img without height");
                    $this->assertStringNotContainsString('<script', $html, $where);
                    $this->assertStringNotContainsString('data-mb-cart-mount', $html, "{$where}: runtime mounts belong to the header");
                }
            }
        }
    }

    public function test_sections_render_with_features_on_and_off(): void
    {
        foreach (['restaurant', 'retail', 'carparts'] as $vertical) {
            foreach ([true, false] as $on) {
                foreach (self::SECTIONS as $type) {
                    $vars = DemoStorefront::vars($vertical);
                    $vars['extras']['features'] = $this->features($on);
                    $html = $this->render($type, $vars, 'en');
                    if (in_array($type, ['fitment-search', 'pharmacy-notice'], true)) {
                        $this->assertSame($on, trim($html) !== '', "{$type} follows its feature switch ({$vertical})");
                    } else {
                        $this->assertStringContainsString('class="mbs-'.$type, $html);
                    }
                }
            }
        }
    }

    public function test_sections_are_safe_for_a_brand_new_store(): void
    {
        foreach ([true, false] as $on) {
            foreach (['en', 'ar'] as $lang) {
                foreach (self::SECTIONS as $type) {
                    $html = $this->render($type, $this->emptyStore($on), $lang);
                    $this->assertStringNotContainsString('data-mb-add', $html, "{$type}: nothing to add");
                    $this->assertStringNotContainsString('data-mb-item', $html);
                }
            }
        }

        $grid = $this->render('product-grid', $this->emptyStore(false));
        $this->assertStringContainsString('Nothing here yet', $grid);
        $this->assertStringNotContainsString('data-mb-sort', $grid, 'no tools without items');
        $this->assertStringContainsString('Nothing here yet', $this->render('service-list', $this->emptyStore(false)));
        $this->assertStringContainsString('Nothing here yet', $this->render('room-list', $this->emptyStore(false)));
        $this->assertSame('', trim($this->render('fitment-search', $this->emptyStore(false))));
        $this->assertSame('', trim($this->render('pharmacy-notice', $this->emptyStore(false))));

        $notice = $this->render('pharmacy-notice', $this->emptyStore(true));
        $this->assertStringContainsString('over-the-counter', $notice);
        $this->assertStringNotContainsString('mbs-pharmacy-notice__row--age', $notice, 'no age note without restricted items');
    }

    public function test_vertical_sections_are_gated_by_capability(): void
    {
        foreach (self::VERTICALS as $vertical) {
            $fitment = trim($this->render('fitment-search', DemoStorefront::vars($vertical)));
            $notice = trim($this->render('pharmacy-notice', DemoStorefront::vars($vertical)));
            $this->assertSame($vertical === 'carparts', $fitment !== '', "fitment-search on {$vertical}");
            $this->assertSame($vertical === 'pharmacy', $notice !== '', "pharmacy-notice on {$vertical}");
        }
    }

    public function test_fitment_search_follows_the_v2_form_contract(): void
    {
        $html = $this->render('fitment-search', DemoStorefront::vars('carparts'));
        $this->assertMatchesRegularExpression('/<form[^>]*\sdata-mb-fitment[\s>]/', $html);
        foreach (['data-mb-fitment-make', 'data-mb-fitment-model', 'data-mb-fitment-year', 'data-mb-fitment-oem', 'data-mb-fitment-reset', 'data-mb-fitment-status', 'type="submit"'] as $hook) {
            $this->assertStringContainsString($hook, $html);
        }
        $this->assertStringNotContainsString('data-mb-fitment-mode=', $html, 'filter mode by default');

        $highlight = $this->render('fitment-search', DemoStorefront::vars('carparts'), 'en', ['mode' => 'highlight', 'show_part_number' => false]);
        $this->assertStringContainsString('data-mb-fitment-mode="highlight"', $highlight);
        $this->assertStringNotContainsString('data-mb-fitment-oem', $highlight);
    }

    public function test_pharmacy_notice_shows_the_otc_disclaimer_and_age_note(): void
    {
        $html = $this->render('pharmacy-notice', DemoStorefront::vars('pharmacy'));
        $this->assertStringContainsString('role="note"', $html);
        $this->assertStringContainsString('over-the-counter (OTC)', $html);
        $this->assertStringContainsString('mbs-pharmacy-notice__row--age', $html, 'the demo has an age-restricted item');

        $custom = $this->render('pharmacy-notice', DemoStorefront::vars('pharmacy'), 'en', ['disclaimer' => '<b>OTC</b> only', 'show_age_note' => false]);
        $this->assertStringContainsString('&lt;b&gt;OTC&lt;/b&gt; only', $custom);
        $this->assertStringNotContainsString('mbs-pharmacy-notice__row--age', $custom);

        $ar = $this->render('pharmacy-notice', DemoStorefront::vars('pharmacy'), 'ar');
        $this->assertStringContainsString('وصفة طبية', $ar);
        $this->assertStringNotContainsString('over-the-counter', $ar);
    }

    public function test_product_grid_carries_the_commerce_hooks(): void
    {
        $html = $this->render('product-grid', DemoStorefront::vars('retail'));

        foreach (['data-mb-catalog', 'data-mb-cat', 'data-mb-search', 'data-mb-sort', 'data-mb-filter="fav"', 'data-mb-empty',
            'data-mb-item="9411"', 'data-mb-open="9411"', 'data-mb-add="9411"', 'data-mb-fav="9411"', 'data-mb-price="19"', 'href="?item=9411"'] as $hook) {
            $this->assertStringContainsString($hook, $html, $hook);
        }
        // Variant matrix hint: distinct sizes then colours; "From" the lowest variant price.
        $this->assertMatchesRegularExpression('/>S<\/li><li[^>]*>M<\/li><li[^>]*>L<\/li><li[^>]*>Black<\/li><li[^>]*>White</', $html);
        $this->assertMatchesRegularExpression('#__from">from</span>#i', $html);
        // Stock: low / out badges wired to data-mb-stock; the variant item defers to the runtime.
        $this->assertStringContainsString('data-mb-stock="9412" data-mb-stock-state="low">Only 3 left', $html);
        $this->assertStringContainsString('data-mb-stock="9413" data-mb-stock-state="out">Out of stock', $html);
        $this->assertStringContainsString('<span class="mbs-stock-badge" data-mb-stock="9411" hidden>', $html);
        $this->assertMatchesRegularExpression('/data-mb-add="9413"[^>]*disabled/', $html, 'out-of-stock items cannot be added');
        $this->assertMatchesRegularExpression('/data-mb-money="45">[^<]*45\.00/u', $html, 'prices go through | money');
        $this->assertStringContainsString('data-mb-money="55"', $html, 'offer shows the old price');
        $this->assertStringContainsString('Add to cart', $html, 'goods vocab');
        $this->assertStringContainsString('<h2 class="mbs-product-grid__title" id="mbs-pg-t-product-grid">Products</h2>', $html);

        // Ordering off → no add buttons; the catalogue still renders.
        $vars = DemoStorefront::vars('retail');
        $vars['flags']['allow_order'] = false;
        $off = $this->render('product-grid', $vars);
        $this->assertStringNotContainsString('data-mb-add', $off);
        $this->assertStringContainsString('data-mb-open="9411"', $off);

        // Flat (ungrouped) grid with a cap, and a single-category filter.
        $flat = $this->render('product-grid', DemoStorefront::vars('retail'), 'en', ['group_by_category' => false, 'limit' => 2]);
        $this->assertSame(1, substr_count($flat, 'data-mb-cat>'));
        $this->assertSame(2, substr_count($flat, '<article'));
        $home = $this->render('product-grid', DemoStorefront::vars('retail'), 'en', ['category' => '9312']);
        $this->assertStringContainsString('data-mb-item="9413"', $home);
        $this->assertStringNotContainsString('data-mb-item="9411"', $home);
    }

    public function test_product_grid_shows_expiry_and_age_flags_for_pharmacy(): void
    {
        $html = $this->render('product-grid', DemoStorefront::vars('pharmacy'));
        $this->assertStringContainsString('<time datetime="2027-03-31">', $html, 'expiry-tracking store shows best-before');
        $this->assertStringContainsString('is-age', $html);

        $retail = DemoStorefront::vars('retail');
        $this->assertStringNotContainsString('<time', $this->render('product-grid', $retail), 'no expiry capability → no dates');
    }

    public function test_service_list_shows_turnaround_unit_and_book(): void
    {
        $html = $this->render('service-list', DemoStorefront::vars('laundry'));
        $this->assertStringContainsString('Ready in 24 hours', $html);
        $this->assertStringContainsString('per kg', $html);
        $this->assertStringContainsString('data-mb-add="9431"', $html);
        $this->assertStringContainsString('>Book<', $html, 'services vocab');
        $this->assertStringContainsString('Pick a time at checkout', $html, 'v2 slot flow note');
        $this->assertMatchesRegularExpression('#__from">from</span>#i', $html, 'variant price range');
        $this->assertStringContainsString('<h2 class="mbs-service-list__title" id="mbs-sl-t-service-list">Services</h2>', $html);

        $ar = $this->render('service-list', DemoStorefront::vars('laundry'), 'ar');
        $this->assertStringContainsString('جاهز خلال 24 ساعة', $ar);
        $this->assertStringContainsString('اختر الموعد عند إتمام الطلب', $ar);
        $this->assertStringNotContainsString('Ready in', $ar);

        $flat = $this->render('service-list', DemoStorefront::vars('laundry'), 'en', ['show_category_titles' => false]);
        $this->assertStringNotContainsString('<h4', $flat, 'no skipped heading level without category titles');
    }

    public function test_room_list_shows_amenities_nightly_price_and_booking(): void
    {
        $html = $this->render('room-list', DemoStorefront::vars('hotel'), 'en', ['category' => '9341']);
        $this->assertSame(3, substr_count($html, '<article'));
        $this->assertStringContainsString('<li class="mbs-card-room__amenity">Free Wi-Fi</li>', $html);
        $this->assertMatchesRegularExpression('/per night/i', $html);
        $this->assertStringContainsString('data-mb-add="9441"', $html);
        $this->assertStringContainsString('>Rooms</h2>', $html, 'stays vocab');

        $vars = DemoStorefront::vars('hotel');
        $vars['flags']['allow_order'] = false;
        $link = $this->render('room-list', $vars, 'en', ['booking_url' => 'https://book.example.com/r']);
        $this->assertStringNotContainsString('data-mb-add', $link);
        $this->assertStringContainsString('href="https://book.example.com/r"', $link);
        $bad = $this->render('room-list', $vars, 'en', ['booking_url' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('javascript:', $bad);
        $this->assertStringNotContainsString('mbs-card-room__book', $bad);
    }

    public function test_item_text_is_escaped_and_arabic_strings_are_used(): void
    {
        $vars = DemoStorefront::vars('retail');
        $vars['total_menus'][9411]->name = '<img src=x onerror=alert(1)>';
        foreach (['product-grid', 'service-list', 'room-list'] as $type) {
            $html = $this->render($type, $vars);
            $this->assertStringNotContainsString('<img src=x', $html, $type);
            $this->assertStringContainsString('&lt;img src=x', $html, $type);
        }

        $ar = $this->render('product-grid', DemoStorefront::vars('retail'), 'ar');
        foreach (['ترتيب حسب', 'السعر: من الأقل إلى الأعلى', 'المنتجات'] as $word) {
            $this->assertStringContainsString($word, $ar);
        }
        foreach (['Sort by', 'Out of stock', 'Wishlist', 'Only 3 left'] as $english) {
            $this->assertStringNotContainsString($english, $ar);
        }
    }

    public function test_b1c_sections_lint_clean(): void
    {
        $files = ['theme.json' => (string) json_encode(['name' => 'b1c', 'slug' => 'b1c', 'version' => '1.0.0', 'library' => '1'])];
        // The whole library (the header hosts {% commerce %}), judged on the B1c files.
        foreach ($this->library()->sectionTypes() as $type) {
            $files['sections/'.$type.'.liquid'] = (string) $this->library()->sectionSource($type);
        }
        foreach ($this->library()->snippets() as $name => $source) {
            $files['snippets/'.$name.'.liquid'] = $source;
        }
        $report = app(ThemeLinter::class)->lintFiles($files);
        $this->assertSame([], $report['errors'], json_encode($report['errors']));
        $mine = array_filter($report['warnings'], fn ($w) => preg_match('#(sections/('.implode('|', self::SECTIONS).')|snippets/('.implode('|', self::SNIPPETS).'))\.liquid#', $w['file']));
        $this->assertSame([], array_values($mine), json_encode($mine));
    }

    public function test_library_check_passes_across_the_vertical_datasets(): void
    {
        $exit = Artisan::call('studio:library-check', ['--library' => '1']);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('All library sections render cleanly', $output);
        preg_match('/(\d+) sections, \d+ snippets, (\d+) renders/', $output, $m);
        $this->assertNotEmpty($m, $output);
        // 6 restaurant/empty scenarios + 5 verticals × en/ar = 16 per section (at least).
        $this->assertGreaterThanOrEqual((int) $m[1] * 16, (int) $m[2]);
    }

    public function test_demo_storefront_default_dataset_is_unchanged(): void
    {
        $default = DemoStorefront::vars();
        $snapshot = fn (array $v) => [
            'store' => [$v['store']->title, $v['store']->slug, $v['store']->sub_title, $v['store']->id, $v['store']->store_type],
            'items' => array_map(fn ($m) => [$m->id, $m->name, (float) $m->price, $m->category->name, $m->variants->count(), (bool) $m->track_inventory], $v['total_menus']),
            'options' => (array) $v['postOptions'],
            'flags' => $v['flags'],
            'features' => $v['extras']['features'],
            'announcement' => $v['extras']['announcement'],
            'banners' => $v['menuBanners']->pluck('title')->all(),
            'branches' => $v['branches']->pluck('name')->all(),
            'langs' => $v['menu_languages']->pluck('code')->all(),
            'default_lang' => $v['default_menu_language']->code,
            'scans' => $v['scans_today'],
        ];

        $this->assertSame([
            'store' => ['Demo Bistro', 'demo-bistro', 'Preview data — not a real restaurant', 0, null],
            'items' => [
                9401 => [9401, 'Signature Burger', 9.5, 'Mains', 2, false],
                9402 => [9402, 'Garden Salad', 7.0, 'Mains', 0, false],
                9403 => [9403, 'Mint Tea', 3.0, 'Drinks', 0, false],
            ],
            'options' => [
                'currency_sign' => '$', 'currency_pos' => '1',
                'restaurant_taxx_charge' => 10, 'restaurant_tax_label' => 'Tax',
                'restaurant_delivery_charge' => 2.5, 'min_order_value' => 0,
                'allow_call_waiter' => 1, 'allow_coupons' => 1, 'allow_tips' => 1,
            ],
            'flags' => ['allow_order' => true, 'on_table' => true, 'takeaway' => true, 'delivery' => true, 'payment' => false, 'scheduling' => false],
            'features' => [
                'reservations' => true, 'waitlist' => true, 'loyalty' => true, 'dietary_filters' => true,
                'call_waiter' => true, 'coupons' => true, 'tips' => true, 'store_reviews' => true,
            ],
            'announcement' => ['text' => 'Free delivery this weekend', 'key' => 'demo0001'],
            'banners' => ['Happy Hour'],
            'branches' => ['Main Branch'],
            'langs' => ['en', 'ar'],
            'default_lang' => 'en',
            'scans' => 128,
        ], $snapshot($default));

        $this->assertSame(array_keys($default), array_keys(DemoStorefront::vars('restaurant')));
        $this->assertSame($snapshot($default), $snapshot(DemoStorefront::vars('restaurant')));
        $this->assertSame($snapshot($default), $snapshot(DemoStorefront::vars('no-such-vertical')), 'unknown vertical → restaurant');
    }

    public function test_vertical_datasets_carry_their_capabilities(): void
    {
        $expect = [
            'retail' => ['variant_goods' => true, 'expiry' => false, 'age_gate' => false, 'fitment' => false, 'service' => false],
            'pharmacy' => ['variant_goods' => true, 'expiry' => true, 'age_gate' => true, 'fitment' => false, 'service' => false],
            'laundry' => ['variant_goods' => false, 'expiry' => false, 'age_gate' => false, 'fitment' => false, 'service' => true],
            'hotel' => ['variant_goods' => false, 'expiry' => false, 'age_gate' => false, 'fitment' => false, 'service' => false],
            'carparts' => ['variant_goods' => true, 'expiry' => false, 'age_gate' => false, 'fitment' => true, 'service' => false],
        ];
        foreach ($expect as $vertical => $caps) {
            $vars = DemoStorefront::vars($vertical);
            $this->assertSame($vertical, $vars['store']->store_type);
            $this->assertNotEmpty($vars['total_menus']);
            foreach ($caps as $cap => $on) {
                $this->assertSame($on, $vars['extras']['features'][$cap], "{$vertical}.{$cap}");
            }
        }

        $globals = app(ThemeContext::class)->build(DemoStorefront::vars('laundry'));
        $this->assertSame('services', $globals['vocab']->group());
        $items = $globals['menu']->items();
        $this->assertSame('kg', $items[0]->pricing_unit());
        $this->assertSame(24, $items[0]->turnaround_hours());

        $pharmacy = app(ThemeContext::class)->build(DemoStorefront::vars('pharmacy'));
        $restricted = array_filter($pharmacy['menu']->items(), fn ($i) => $i->age_restricted());
        $this->assertCount(1, $restricted);
        $this->assertSame('2027-03-31', $pharmacy['menu']->items()[0]->expiry_date());
    }

    public function test_sectioned_theme_on_runtime_v2_loads_the_v2_bundle_with_product_grid_hooks(): void
    {
        $slug = 'b1c-v2-'.Str::lower(Str::random(6));
        $dir = storage_path('app/marketplace-themes/'.$slug);
        $this->tmpDirs[] = $dir;
        File::ensureDirectoryExists($dir.'/1/templates');
        File::put($dir.'/1/theme.json', (string) json_encode([
            'name' => 'B1c v2', 'slug' => $slug, 'version' => '1.0.0', 'author' => 'Test',
            'min_platform_version' => '2.0', 'source' => 'liquid', 'library' => '1', 'commerce_runtime' => 'v2',
        ]));
        File::put($dir.'/1/templates/index.json', (string) json_encode([
            'sections' => [
                'header' => ['type' => 'header'],
                'products' => ['type' => 'product-grid'],
            ],
            'order' => ['header', 'products'],
        ]));

        $store = $this->store('mp:'.$slug.'@1', 'retail');
        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('assets/commerce-runtime/v2/commerce.js', $html);
        $this->assertStringNotContainsString('assets/commerce-runtime/v1/commerce.js', $html);
        $this->assertSame(1, substr_count($html, 'window.MB_COMMERCE='), 'runtime emitted exactly once');
        $this->assertStringContainsString('class="mbs-product-grid', $html);
        foreach (['data-mb-add="', 'data-mb-open="', 'data-mb-item="', 'data-mb-stock="', 'data-mb-sort', 'data-mb-search', 'data-mb-fav="'] as $hook) {
            $this->assertStringContainsString($hook, $html, $hook);
        }
        $this->assertStringContainsString('Only 2 left', $html);
        $this->assertStringContainsString('assets/liquid-library/1/library.css?v=', $html);
    }

    private function store(string $template, string $storeType): Store
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
        $title = 'S'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $store->store_type = $storeType;
        $store->save();
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Apparel']);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Tee', 'price' => 19, 'active' => '1',
            'track_inventory' => true, 'stock_qty' => 2,
        ]);

        return $store;
    }
}
