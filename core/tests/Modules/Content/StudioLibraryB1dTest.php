<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\LoyaltyProgram;
use App\Models\StoreReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\Drops\BlockDrop;
use Juwri\Modules\Storefront\Storefront\Drops\FeaturesDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Symfony\Component\Process\Process;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B1d: library v1 trust / content / action sections — reviews,
 * testimonials, about, image-with-text, gallery, faq, rich-text and the
 * widget-hosting reservation-cta, loyalty-card, catering-cta, branch-picker,
 * plus the phase-A custom-block section.
 */
class StudioLibraryB1dTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = [
        'reviews', 'testimonials', 'about', 'image-with-text', 'gallery', 'faq',
        'rich-text', 'reservation-cta', 'loyalty-card', 'catering-cta', 'branch-picker', 'custom-block',
    ];

    private const WIDGET_SECTIONS = ['reviews', 'reservation-cta', 'loyalty-card', 'catering-cta', 'branch-picker'];

    private const XSS = '<script>alert(1)</script>';

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
     * Render one library section in strict upload mode, like SectionedTemplate.
     * $blocks null = the first preset's blocks; otherwise list of [type, settings].
     *
     * @param  array<string,mixed>  $vars
     * @param  array<string,mixed>  $settings
     * @param  list<array{0:string,1:array<string,mixed>}>|null  $blocks
     */
    private function render(string $type, array $vars, string $lang = 'en', array $settings = [], ?array $blocks = null): string
    {
        $library = $this->library();
        $source = (string) $library->sectionSource($type);
        $schema = SectionedTemplate::extractSchema($source);

        $defaults = [];
        foreach ($schema['blocks'] ?? [] as $def) {
            foreach ($def['settings'] ?? [] as $s) {
                if (array_key_exists('default', $s)) {
                    $defaults[$def['type']][$s['id']] = $s['default'];
                }
            }
        }
        $blockList = $blocks ?? array_map(
            fn ($b) => [$b['type'], $b['settings'] ?? []],
            array_values($schema['presets'][0]['blocks'] ?? []),
        );
        $drops = [];
        foreach ($blockList as $i => [$blockType, $blockSettings]) {
            $drops[] = new BlockDrop('b'.$i, $blockType, array_merge($defaults[$blockType] ?? [], $blockSettings));
        }

        $vars['default_menu_language'] = $vars['menu_languages']->firstWhere('code', $lang) ?? $vars['default_menu_language'];
        app()->setLocale($lang);
        $vars['settings'] = [];
        $ctx = app(ThemeContext::class);
        $globals = $ctx->build($vars);
        $globals['section'] = new SectionDrop('t-'.$type, $type, array_merge(SectionedTemplate::schemaDefaults($schema), $settings), $drops);

        return app(LiquidRenderer::class)->render(
            $source,
            $globals,
            'upload',
            $ctx->registers($vars) + ['theme_locale_map' => $library->locales($lang)],
            $library->snippets(),
        );
    }

    /** @return array<string,mixed> DemoStorefront vars with every feature switch set */
    private function demo(bool $features = true): array
    {
        $vars = DemoStorefront::vars();
        $vars['extras']['features'] = $this->features($features);

        return $vars;
    }

    /** @return array<string,mixed> a brand-new store: nothing configured, ordering off */
    private function emptyStore(bool $features = false): array
    {
        $store = new Store(['title' => 'Fresh', 'slug' => 'fresh']);
        $store->id = 0;

        return array_merge(DemoStorefront::vars(), [
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [],
            'menuBanners' => collect(), 'branches' => collect(), 'flags' => ['allow_order' => false],
            'extras' => ['hours' => [], 'stories' => [], 'social' => [], 'announcement' => null, 'features' => $this->features($features)],
        ]);
    }

    /** A real (persisted) store, so the widget tag has a store to render for. */
    private function realStore(): Store
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

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> demo vars rendered for a real store with data for every widget */
    private function widgetVars(bool $features): array
    {
        $store = $this->realStore();
        LoyaltyProgram::create(['store_id' => $store->id, 'type' => 'points', 'reward_threshold' => 50, 'reward_description' => 'Free tea', 'active' => true]);
        StoreReview::create(['store_id' => $store->id, 'reviewer_name' => 'Mona', 'stars' => 5, 'body' => 'Lovely', 'status' => 'approved']);
        Branch::create(['store_id' => $store->id, 'name' => 'Downtown', 'active' => true, 'position' => 1]);
        Branch::create(['store_id' => $store->id, 'name' => 'Harbour', 'active' => true, 'position' => 2]);

        $vars = $this->demo($features);
        $vars['store'] = $store;
        $vars['branches'] = Branch::where('store_id', $store->id)->orderBy('position')->get();
        $vars['activeBranch'] = null;

        return $vars;
    }

    public function test_library_ships_the_b1d_sections_with_schemas(): void
    {
        $types = $this->library()->sectionTypes();
        foreach (self::SECTIONS as $type) {
            $this->assertContains($type, $types);
            $schema = SectionedTemplate::extractSchema((string) $this->library()->sectionSource($type));
            $this->assertNotEmpty($schema['name'] ?? null, "{$type} schema name");
            $this->assertNotEmpty($schema['presets'] ?? null, "{$type} preset");
            foreach ($schema['settings'] ?? [] as $def) {
                $this->assertArrayHasKey('default', $def, "{$type}.{$def['id']} needs a default (strict mode)");
            }
            foreach ($schema['blocks'] ?? [] as $block) {
                foreach ($block['settings'] ?? [] as $def) {
                    $this->assertArrayHasKey('default', $def, "{$type} block {$block['type']}.{$def['id']} needs a default");
                }
            }
            $this->assertFileExists(base_path('../tools/liquid-library/src/sections/'.$type.'.css'));
        }

        $custom = SectionedTemplate::extractSchema((string) $this->library()->sectionSource('custom-block'));
        // Phase A shipped: offered in the gallery (the editor gates it on caps.custom_block).
        $this->assertFalse($custom['hidden_from_gallery'] ?? false);
        $this->assertSame(['block', 'width'], array_column($custom['settings'], 'id'));
        $this->assertSame('custom_block', $custom['settings'][0]['type']);
    }

    public function test_every_section_strict_renders_en_ar_features_on_off_and_empty_store(): void
    {
        foreach (['en', 'ar'] as $lang) {
            foreach ([true, false] as $on) {
                foreach (self::SECTIONS as $type) {
                    foreach ([$this->demo($on), $this->emptyStore($on)] as $i => $vars) {
                        foreach ([null, []] as $blocks) {
                            $html = $this->render($type, $vars, $lang, [], $blocks);
                            $where = "{$type} {$lang} features=".($on ? 'on' : 'off').($i ? ' empty' : ' demo');
                            if (trim($html) !== '') {
                                $this->assertStringContainsString('class="mbs-'.$type, $html, $where);
                            }
                            $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*\bwidth=)[^>]*>/', $html, $where);
                            $this->assertStringNotContainsString('data-mb-open-cart', $html, $where);
                        }
                    }
                }
            }
        }
    }

    public function test_an_empty_store_with_default_settings_renders_nothing(): void
    {
        foreach (['en', 'ar'] as $lang) {
            foreach ([true, false] as $on) {
                foreach (self::SECTIONS as $type) {
                    $this->assertSame('', trim($this->render($type, $this->emptyStore($on), $lang)), "{$type} {$lang}");
                }
            }
        }
        $this->assertSame('', trim($this->render('custom-block', $this->demo(), 'en', ['block' => 'abc'])), 'no such block renders nothing');
    }

    public function test_widget_sections_render_nothing_when_the_feature_is_off(): void
    {
        $vars = $this->widgetVars(false);
        foreach (self::WIDGET_SECTIONS as $type) {
            if ($type === 'branch-picker') {
                continue; // self-gates on branch data, not a feature switch
            }
            $this->assertSame('', trim($this->render($type, $vars)), "{$type} with its feature off");
        }

        // Branch picker: one branch → nothing.
        $single = $this->widgetVars(true);
        $single['branches'] = $single['branches']->take(1);
        $this->assertSame('', trim($this->render('branch-picker', $single)));
    }

    public function test_widget_sections_host_the_platform_widgets_when_on(): void
    {
        $vars = $this->widgetVars(true);
        $expect = [
            'reviews' => 'data-mbw-open="store-reviews"',
            'reservation-cta' => 'data-mbw-open="reservation"',
            'loyalty-card' => 'data-mbw-open="loyalty"',
            'catering-cta' => 'data-mbw-open="catering"',
            'branch-picker' => 'data-mbw-open="branch-switcher"',
        ];
        foreach ($expect as $type => $hook) {
            $html = $this->render($type, $vars);
            $this->assertStringContainsString('class="mbs-'.$type, $html, $type);
            $this->assertStringContainsString($hook, $html, $type);
        }

        $booking = $this->render('reservation-cta', $vars);
        $this->assertStringContainsString('data-mbw-open="waitlist"', $booking);
        $this->assertStringNotContainsString('data-mbw-open="waitlist"', $this->render('reservation-cta', $vars, 'en', ['show_waitlist' => false]));

        // Waitlist only (reservations off) still renders, with the waitlist heading.
        $vars['extras']['features']['reservations'] = false;
        $queue = $this->render('reservation-cta', $vars);
        $this->assertStringNotContainsString('data-mbw-open="reservation"', $queue);
        $this->assertStringContainsStringIgnoringCase('Join the waitlist', $queue);

        // Reviews: Google link only with the google_review switch + a URL.
        $vars['extras']['social'] = ['google_review_url' => 'https://g.page/r/demo/review'];
        $this->assertStringContainsString('href="https://g.page/r/demo/review"', $this->render('reviews', $vars));
        $vars['extras']['features']['google_review'] = false;
        $this->assertStringNotContainsString('g.page', $this->render('reviews', $vars));
    }

    public function test_faq_uses_native_details_summary_and_passes_richtext_through(): void
    {
        $html = $this->render('faq', $this->demo(), 'en', ['open_first' => true], [
            ['question', ['question' => 'Do you deliver?', 'answer' => 'Yes — <strong>every day</strong><br>until 11pm.']],
            ['question', ['question' => 'Skipped', 'answer' => '']],
            ['question', ['question' => self::XSS, 'answer' => '<em>ok</em>']],
        ]);

        $this->assertSame(2, substr_count($html, '<details class="mbs-faq__item"'));
        $this->assertSame(2, substr_count($html, '<summary class="mbs-faq__q"'));
        $this->assertSame(1, substr_count($html, ' open>'), 'only the first is open');
        $this->assertStringContainsString('<strong>every day</strong><br>until 11pm.', $html, 'richtext passes through');
        $this->assertStringNotContainsString('Skipped', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsStringIgnoringCase('Common questions', $html, 'platform string wins, library fills gaps');
    }

    public function test_text_settings_are_escaped_and_richtext_is_not(): void
    {
        $vars = $this->demo();
        $rich = '<strong>Fresh</strong> <a href="/menu">daily</a>';
        $cases = [
            ['rich-text', ['heading' => self::XSS, 'text' => $rich, 'cta_label' => self::XSS, 'cta_url' => '/menu'], []],
            ['about', ['heading' => self::XSS, 'body' => $rich, 'image' => 'about.jpg', 'image_alt' => self::XSS], null],
            ['gallery', ['heading' => self::XSS], [['image', ['image' => 'a.jpg', 'caption' => self::XSS]]]],
            ['image-with-text', ['heading' => self::XSS], [['row', ['heading' => self::XSS, 'text' => $rich, 'button_label' => self::XSS, 'button_url' => 'https://example.com/x', 'image_alt' => self::XSS]]]],
            ['testimonials', ['heading' => self::XSS], [['testimonial', ['quote' => self::XSS, 'author' => self::XSS, 'detail' => self::XSS, 'rating' => 4]]]],
            ['faq', ['heading' => self::XSS], [['question', ['question' => self::XSS, 'answer' => $rich]]]],
        ];
        foreach ($cases as [$type, $settings, $blocks]) {
            $html = $this->render($type, $vars, 'en', $settings, $blocks);
            $this->assertStringContainsString('class="mbs-'.$type, $html, $type);
            $this->assertStringNotContainsString('<script>', $html, "{$type}: text setting escaped");
            $this->assertStringContainsString('&lt;script&gt;', $html, $type);
            if (in_array($type, ['rich-text', 'about', 'image-with-text', 'faq'], true)) {
                $this->assertStringContainsString($rich, $html, "{$type}: richtext passes through");
            }
        }

        // Widget-section headings / texts (real store so the widget renders).
        $wvars = $this->widgetVars(true);
        foreach (['reservation-cta', 'loyalty-card', 'catering-cta', 'branch-picker', 'reviews'] as $type) {
            $settings = ['heading' => self::XSS, 'text' => self::XSS];
            if ($type === 'reviews') {
                $settings = ['heading' => self::XSS, 'intro' => self::XSS];
            }
            $html = $this->render($type, $wvars, 'en', $settings);
            $this->assertStringNotContainsString(self::XSS, $html, $type);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $type);
        }
    }

    public function test_buttons_only_accept_safe_links(): void
    {
        foreach (['javascript:alert(1)', '//evil.test/x', 'data:text/html,x', ''] as $bad) {
            $html = $this->render('rich-text', $this->demo(), 'en', ['heading' => 'Hi', 'cta_label' => 'Go', 'cta_url' => $bad]);
            $this->assertStringNotContainsString('mbs-rich-text__cta', $html, $bad);
        }
        foreach (['https://example.com', '/menu', '#mb-section-menu'] as $good) {
            $html = $this->render('rich-text', $this->demo(), 'en', ['heading' => 'Hi', 'cta_label' => 'Go', 'cta_url' => $good]);
            $this->assertStringContainsString('href="'.$good.'"', $html, $good);
        }
    }

    public function test_content_sections_layouts_and_fallbacks(): void
    {
        $vars = $this->demo();
        $vars['store']->description = 'Family kitchen since 1990';

        $about = $this->render('about', $vars, 'en', ['layout' => 'image-end', 'image' => 'about.jpg']);
        $this->assertStringContainsString('mbs-about--image-end has-media', $about);
        $this->assertStringContainsString('Family kitchen since 1990', $about, 'store description fallback');
        $this->assertMatchesRegularExpression('/<img[^>]*class="mbs-about__img"[^>]*width="960"[^>]*height="720"[^>]*loading="lazy"/', $about);
        $this->assertSame('', trim($this->render('about', $vars, 'en', ['use_description' => false])));

        $gallery = $this->render('gallery', $vars, 'en', ['layout' => 'masonry', 'columns' => 9], [
            ['image', ['image' => 'a.jpg', 'shape' => 'tall']], ['image', ['image' => '']], ['image', ['image' => 'b.jpg', 'shape' => 'wide']],
        ]);
        $this->assertSame(2, substr_count($gallery, '<li class="mbs-gallery__item"'));
        $this->assertStringContainsString('--mbs-gallery-cols: 4', $gallery, 'columns clamped');
        $this->assertStringContainsString('height="1067"', $gallery);
        $this->assertStringContainsString('aria-label="Gallery"', $gallery);

        $iwt = $this->render('image-with-text', $vars, 'en', ['media_position' => 'alternate'], [
            ['row', ['heading' => 'One']], ['row', []], ['row', ['heading' => 'Two', 'image' => 'x.jpg']],
        ]);
        $this->assertSame(2, substr_count($iwt, 'class="mbs-image-with-text__row'));
        $this->assertStringContainsString('<h2 class="mbs-image-with-text__heading">One</h2>', $iwt, 'rows are h2 without a section heading');

        $t = $this->render('testimonials', $vars, 'en', ['layout' => 'carousel'], [
            ['testimonial', ['quote' => 'Great', 'author' => 'Sam', 'rating' => 3]], ['testimonial', ['quote' => '  ']],
        ]);
        $this->assertSame(1, substr_count($t, '<li class="mbs-testimonials__item"'));
        $this->assertStringContainsString('mbs-testimonials--carousel', $t);
        $this->assertSame(3, substr_count($t, 'mbs-icon--star'));
        $this->assertStringContainsString('Rating: 3 / 5', $t);
        $this->assertSame('', trim($this->render('testimonials', $vars)), 'preset blocks have no quotes → nothing (never fake praise)');
    }

    public function test_arabic_render_uses_the_b1d_strings(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'العربية', 'direction' => 'rtl', 'active' => 1]);
        $faq = $this->render('faq', $this->demo(), 'ar', [], [['question', ['question' => 'س', 'answer' => 'ج']]]);
        $this->assertStringContainsString('أسئلة', $faq);
        $this->assertStringNotContainsString('Common questions', $faq);

        $vars = $this->widgetVars(true);
        $this->assertStringContainsString('ماذا يقول عملاؤنا', $this->render('testimonials', $vars, 'ar', [], [['testimonial', ['quote' => 'رائع']]]));
        $this->assertStringContainsString('اختر فرعك', $this->render('branch-picker', $vars, 'ar'));

        $en = json_decode((string) file_get_contents(resource_path('liquid-library/1/locales/en/b1d.json')), true);
        $ar = json_decode((string) file_get_contents(resource_path('liquid-library/1/locales/ar/b1d.json')), true);
        $this->assertSame(array_keys($en), array_keys($ar), 'en/ar b1d strings in step');
        foreach ($ar as $key => $value) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $value, "ar {$key} is Arabic");
        }
    }

    public function test_library_check_and_css_build_pass(): void
    {
        $exit = Artisan::call('studio:library-check', ['--library' => '1']);
        $this->assertSame(0, $exit, Artisan::output());

        $css = (string) file_get_contents(public_path('assets/liquid-library/1/library.css'));
        // Budget (spec §7): ≤ 15 KB gzipped is the hard limit (the real transfer), ≤ 96 KB raw.
        $this->assertLessThanOrEqual(96 * 1024, strlen($css));
        $this->assertLessThanOrEqual(15 * 1024, strlen((string) gzencode($css, 9)));
        foreach (self::SECTIONS as $type) {
            $this->assertStringContainsString('.mbs-'.$type.'{', $css, $type);
        }

        $node = null;
        foreach (['node', ((string) getenv('HOME')).'/.nvm/versions/node/v18.20.4/bin/node'] as $candidate) {
            $probe = new Process([$candidate, '--version']);
            try {
                $probe->run();
            } catch (\Throwable) {
                continue;
            }
            if ($probe->isSuccessful()) {
                $node = $candidate;
                break;
            }
        }
        if ($node === null) {
            $this->markTestSkipped('node not available for build.mjs --check');
        }
        $build = new Process([$node, base_path('../tools/liquid-library/build.mjs'), '--check']);
        $build->run();
        $this->assertTrue($build->isSuccessful(), $build->getErrorOutput().$build->getOutput());
    }
}
