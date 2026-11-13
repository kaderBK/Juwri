<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiLimitExceededException;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Ai\Services\TranslatorService;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutMerge;
use Juwri\Modules\Storefront\Services\ThemeStudio\StoreLayouts;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Tests\TestCase;

/**
 * Theme Studio C4: per-language text. `layout.i18n.<lang>` overrides section +
 * block text settings at render for that menu language (default language =
 * plain settings) on the public storefront, the owner preview and the section
 * fragment; LayoutDocument keeps only the store's other languages, known
 * ids and text-type keys; "Translate all" fills the gaps through
 * TranslatorService (stubbed — never a real provider) without overwriting.
 */
class ThemeStudioI18nTest extends TestCase
{
    use RefreshDatabase;
    use ThemeStudioFixture;

    private string $viewDir;

    /** @var list<array{records:array,lang:string,opts:array}> */
    public array $translatorCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureSlug = 'c4i18n';
        app()->setLocale('en');
        $this->languages();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->makeStudioTheme();
        $this->studioFeature(true);

        $this->viewDir = storage_path('framework/testing/c4-studio-views-'.getmypid());
        File::ensureDirectoryExists($this->viewDir.'/owner/design');
        File::put($this->viewDir.'/owner/design/studio.blade.php',
            '<script type="application/json" id="theme-studio-config">@json($studio)</script>');
        View::getFinder()->prependLocation($this->viewDir);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['Quick_user_lang_code']);
        $this->removeStudioTheme();
        File::deleteDirectory($this->viewDir);
        parent::tearDown();
    }

    private function theme(): MarketplaceTheme
    {
        $theme = MarketplaceThemeResolver::resolve('mp:c4i18n@1');
        $this->assertNotNull($theme);

        return $theme;
    }

    /** A Studio store whose menu is English (default) + Arabic. */
    private function bilingualStore(): Store
    {
        $store = $this->studioStore();
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['en', 'ar']));

        return $store;
    }

    private function owner(Store $store): User
    {
        return User::findOrFail($store->user_id);
    }

    private function url(string $name, Store $store, array $params = []): string
    {
        return route($name, $params + ['store' => $store->id]);
    }

    /** Store + publish a layout carrying Arabic text for the hero heading and its CTA block. */
    private function publishArabic(Store $store): void
    {
        $theme = $this->theme();
        $layout = StoreLayouts::themeDefault($theme);
        $layout['i18n'] = ['ar' => ['hero.heading' => 'أهلاً بكم', 'hero.b1.label' => 'اطلب الآن']];
        StoreLayouts::saveDraft($store, $theme, $layout);
        ThemeCustomizerService::publish($store, 'mp:c4i18n@1');
    }

    private function fakeTranslator(string $prefix = 'AR:', ?\Throwable $throw = null): void
    {
        $test = $this;
        $this->app->instance(TranslatorService::class, new class($this->app->make(AiService::class), $test, $prefix, $throw) extends TranslatorService
        {
            public function __construct(AiService $ai, private $test, private string $prefix, private ?\Throwable $throw)
            {
                parent::__construct($ai);
            }

            public function translateBatch(array $records, string $targetLang, array $opts = []): array
            {
                if ($this->throw) {
                    throw $this->throw;
                }
                $this->test->translatorCalls[] = ['records' => $records, 'lang' => $targetLang, 'opts' => $opts];
                $out = [];
                foreach ($records as $id => $fields) {
                    $out[$id] = ['_lowconf' => false];
                    foreach ($fields as $k => $v) {
                        $out[$id][$k] = $this->prefix.$v;
                    }
                }

                return $out;
            }
        });
        // A configured provider = AI translation available for the store.
        config([
            'ai.default' => 'fake', 'ai.fallback' => [],
            'ai.providers.fake' => ['driver' => 'openai', 'base_url' => 'https://ai.invalid/v1', 'api_key' => 'k', 'model' => 'm', 'supports_json' => true],
        ]);
    }

    // ------------------------------------------------------------ render

    public function test_section_and_block_text_render_in_the_requested_language(): void
    {
        $theme = $this->theme();
        $layout = StoreLayouts::themeDefault($theme);
        $layout['i18n'] = ['ar' => [
            'hero.heading' => 'أهلاً <b>بكم</b>',
            'hero.b1.label' => 'اطلب',
            'hero.image' => 'x.webp',                     // not a text setting — ignored at render
            'hero.body' => '<p>نص<script>alert(1)</script></p>', // sanitized at render too
        ]];
        $engine = app(SectionedTemplate::class);

        $ar = $engine->render($theme, [], ['render_language' => 'ar'], [], 'live', $layout);
        $this->assertStringContainsString('أهلاً &lt;b&gt;بكم&lt;/b&gt;', $ar);
        $this->assertStringContainsString('>اطلب</i>', $ar);
        $this->assertStringContainsString('<div class="body">نص', $ar);
        $this->assertStringNotContainsString('<script', $ar);

        $en = $engine->render($theme, [], ['render_language' => 'en'], [], 'live', $layout);
        $this->assertStringContainsString('Welcome', $en);
        $this->assertStringContainsString('>Order</i>', $en);
        $this->assertStringNotContainsString('أهلاً', $en);

        // Rendering in the store's DEFAULT language always uses the plain settings.
        $default = $engine->render($theme, [], [
            'render_language' => 'ar',
            'widget_context' => ['postOptions' => (object) ['default_language' => 'ar']],
        ], [], 'live', $layout);
        $this->assertStringContainsString('Welcome', $default);

        // The fragment path (renderOne) follows the same language.
        $one = $engine->renderOne($theme, 'hero', [], ['render_language' => 'ar'], [], $layout);
        $this->assertStringContainsString('أهلاً', (string) $one);
    }

    public function test_public_storefront_renders_the_shoppers_menu_language(): void
    {
        $store = $this->bilingualStore();
        $this->publishArabic($store);
        $base = route('publicView', $store->slug);

        $en = $this->get($base)->assertOk()->getContent();
        $this->assertStringContainsString('Welcome', $en);
        $this->assertStringNotContainsString('أهلاً بكم', $en);

        $_COOKIE['Quick_user_lang_code'] = 'ar';
        $ar = $this->get($base.'?c4=ar')->assertOk()->getContent();
        $this->assertStringContainsString('أهلاً بكم', $ar);
        $this->assertStringContainsString('اطلب الآن', $ar);
    }

    public function test_owner_preview_and_section_fragment_use_the_preview_language(): void
    {
        $store = $this->bilingualStore();
        $owner = $this->owner($store);
        $theme = $this->theme();
        $layout = StoreLayouts::editing((int) $store->id, $theme);
        $layout['i18n'] = ['ar' => ['hero.heading' => 'مسودة']];
        $this->actingAs($owner)->putJson($this->url('theme.studio.draft', $store), ['layout' => $layout])->assertOk()
            ->assertJsonPath('layout.i18n.ar', ['hero.heading' => 'مسودة']);

        $preview = $this->actingAs($owner)->get(route('publicView', $store->slug).'?preview_theme=1&preview_lang=ar')->assertOk()->getContent();
        $this->assertStringContainsString('مسودة', $preview);

        $fragment = $this->actingAs($owner)->get($this->url('theme.studio.section', $store, ['sid' => 'hero', 'draft' => 1, 'lang' => 'ar']))->assertOk()->getContent();
        $this->assertStringContainsString('<span data-studio-text="hero.heading">مسودة</span>', $fragment);

        $fragmentEn = $this->actingAs($owner)->get($this->url('theme.studio.section', $store, ['sid' => 'hero', 'draft' => 1, 'lang' => 'en']))->assertOk()->getContent();
        $this->assertStringContainsString('<span data-studio-text="hero.heading">Welcome</span>', $fragmentEn);
    }

    // -------------------------------------------------------- validation

    public function test_i18n_keeps_only_store_languages_known_ids_and_text_keys(): void
    {
        $store = $this->bilingualStore();
        $theme = $this->theme();
        $current = StoreLayouts::themeDefault($theme);
        $incoming = $current;
        $incoming['i18n'] = [
            'ar' => [
                'hero.heading' => 'أهلاً',
                'hero.b1.label' => 'اطلب',
                'hero.image' => 'x.webp',       // not a text setting
                'hero.b9.label' => 'x',         // unknown block
                'ghost.heading' => 'x',         // unknown section
                'hero.b1.nope' => 'x',          // unknown block setting
                'a.b.c.d' => 'x',               // malformed path
                'hero.heading2' => '',          // blank = use default
            ],
            'en' => ['hero.heading' => 'default lives in settings'],
            'fr' => ['hero.heading' => 'not a menu language of this store'],
            '../x' => ['hero.heading' => 'bad code'],
        ];

        $result = LayoutMerge::merge($current, $incoming, $theme, $store, array_fill_keys(['sections_add', 'sections_remove', 'sections_reorder', 'blocks_edit', 'tokens', 'fonts', 'custom_block'], true));

        $this->assertSame(['ar' => ['hero.heading' => 'أهلاً', 'hero.b1.label' => 'اطلب']], $result['layout']['i18n']);
    }

    public function test_over_long_translation_is_a_validation_error(): void
    {
        $store = $this->bilingualStore();
        $theme = $this->theme();
        $layout = StoreLayouts::themeDefault($theme);
        $layout['i18n'] = ['ar' => ['hero.heading' => str_repeat('ب', LayoutDocument::I18N_TEXT_MAX + 1)]];

        try {
            StoreLayouts::saveDraft($store, $theme, $layout);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('i18n.ar.hero.heading', $e->errors());
        }

        $this->actingAs($this->owner($store))->putJson($this->url('theme.studio.draft', $store), ['layout' => $layout])
            ->assertStatus(422)->assertJsonPath('errors', fn ($errors) => isset($errors['i18n.ar.hero.heading']));
    }

    public function test_removing_a_section_or_block_drops_its_translations(): void
    {
        $store = $this->bilingualStore();
        $theme = $this->theme();
        $current = StoreLayouts::themeDefault($theme);
        $current['i18n'] = ['ar' => ['hero.heading' => 'أهلاً', 'hero.b1.label' => 'اطلب']];
        $incoming = $current;
        unset($incoming['sections']['hero']['blocks']['b1']);
        $incoming['sections']['hero']['block_order'] = [];

        $normalized = LayoutDocument::normalize($incoming);
        $this->assertSame(['ar' => ['hero.heading' => 'أهلاً']], $normalized['i18n']);
    }

    // --------------------------------------------------------- translate

    public function test_translate_fills_missing_languages_without_overwriting(): void
    {
        $this->fakeTranslator('AR:');
        $store = $this->bilingualStore();
        $owner = $this->owner($store);
        $theme = $this->theme();
        $layout = StoreLayouts::editing((int) $store->id, $theme);
        $layout['i18n'] = ['ar' => ['hero.heading' => 'ترجمتي']]; // the owner's own
        StoreLayouts::saveDraft($store, $theme, $layout);

        $res = $this->actingAs($owner)->postJson($this->url('theme.studio.translate', $store))->assertOk();

        $ar = $res->json('layout.i18n.ar');
        $this->assertSame('ترجمتي', $ar['hero.heading'], 'existing translation kept');
        $this->assertSame('AR:Order', $ar['hero.b1.label'], 'block text translated');
        $this->assertSame(1, $res->json('translated'));
        $this->assertTrue($res->json('has_draft'));
        $this->assertCount(1, $this->translatorCalls);
        $this->assertSame('العربية', $this->translatorCalls[0]['lang']);
        $this->assertSame('studio_translate', $this->translatorCalls[0]['opts']['feature']);
        $this->assertSame((int) $store->id, $this->translatorCalls[0]['opts']['storeId']);
        $this->assertSame((int) $owner->id, $this->translatorCalls[0]['opts']['userId']);
        $this->assertArrayNotHasKey('hero.heading', $this->translatorCalls[0]['records'], 'already translated text is not sent');

        // Stored as the draft, never published.
        $this->assertSame('AR:Order', StoreLayouts::forStore((int) $store->id, $theme, true)['i18n']['ar']['hero.b1.label']);
        $this->assertNull(StoreLayouts::forStore((int) $store->id, $theme));

        // overwrite:true replaces the owner's text as well.
        $again = $this->actingAs($owner)->postJson($this->url('theme.studio.translate', $store), ['overwrite' => true])->assertOk();
        $this->assertSame('AR:Welcome', $again->json('layout.i18n.ar')['hero.heading']);
    }

    public function test_translate_is_unavailable_for_a_single_language_store_or_without_ai(): void
    {
        $this->fakeTranslator();
        $single = $this->studioStore();
        $this->actingAs($this->owner($single))->postJson($this->url('theme.studio.translate', $single))->assertForbidden();
        $this->assertSame([], $this->translatorCalls);

        $store = $this->bilingualStore();
        $config = $this->studioConfig($store);
        $this->assertTrue($config['translate']);
        $this->assertStringContainsString('/dashboard/theme-studio/translate', $config['urls']['translate']);

        config(['ai.providers.fake.api_key' => '', 'ai.default' => 'fake']);
        $this->assertFalse($this->studioConfig($store)['translate']);
        $this->actingAs($this->owner($store))->postJson($this->url('theme.studio.translate', $store))->assertForbidden();
    }

    public function test_translate_reports_an_exhausted_ai_allowance(): void
    {
        $this->fakeTranslator('AR:', new AiLimitExceededException);
        $store = $this->bilingualStore();

        $this->actingAs($this->owner($store))->postJson($this->url('theme.studio.translate', $store))->assertStatus(429);
        $this->assertFalse(StoreLayouts::hasDraft((int) $store->id, $this->theme()));
    }

    public function test_translate_route_carries_the_studio_gate_and_the_ai_rate_limit(): void
    {
        $middleware = Route::getRoutes()->getByName('theme.studio.translate')->gatherMiddleware();
        foreach (['feature:theme_studio', 'auth', 'csp.dashboard', 'web', 'ratelimit:ai'] as $m) {
            $this->assertContains($m, $middleware);
        }
    }

    public function test_editor_script_wires_translate_notices_and_publish_confirmation(): void
    {
        $path = public_path('assets/theme-studio/studio.js');
        $js = (string) file_get_contents($path);

        // Translate: an action in the language menu, only when the page allows it.
        $this->assertStringContainsString("q('[data-ts-langs]')", $js);
        $this->assertMatchesRegularExpression('/if \(!group \|\| !cfg\.translate \|\| !urls\.translate/', $js);
        $this->assertStringContainsString("api('POST', urls.translate", $js);
        // Notices banner + server-side dismissal.
        $this->assertStringContainsString('renderNotices(Array.isArray(d.notices)', $js);
        $this->assertStringContainsString('dismiss_notices: true', $js);
        // Publish asks first — through dashConfirm (confirmBox), never window.confirm.
        $publish = substr($js, (int) strpos($js, 'function publish(btn)'), 600);
        $this->assertStringContainsString("confirmBox({ title: t('Publish your changes?')", $publish);
        $this->assertStringContainsString('cfg.pending_theme', $publish);
        $this->assertStringNotContainsString('window.confirm(', $js);

        // Every new owner string reaches the page's i18n map.
        $store = $this->bilingualStore();
        $i18n = $this->studioConfig($store)['i18n'];
        foreach (['Translate to other languages', 'Publish your changes?', 'Your storefront will show these changes to customers.', 'Your store will switch to :theme.', 'Your theme was updated', 'Dismiss'] as $s) {
            $this->assertArrayHasKey($s, $i18n);
        }

        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node !== '') {
            exec(escapeshellarg($node).' --check '.escapeshellarg($path).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    public function test_pending_theme_name_reaches_the_publish_confirmation(): void
    {
        $store = $this->bilingualStore();
        $this->assertNull($this->studioConfig($store)['pending_theme']);

        StoreOption::updateStoreOption($store->id, 'store_template', 'legacy-classic');
        StoreOption::updateStoreOption($store->id, 'studio_pending_template', 'mp:c4i18n@1');
        $this->assertSame('C1 Studio', $this->studioConfig($store)['pending_theme']);
    }

    /** @return array<string,mixed> */
    private function studioConfig(Store $store): array
    {
        $html = $this->actingAs($this->owner($store))->get($this->url('theme.studio', $store))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script type="application/json" id="theme-studio-config">(.*?)</script>#s', $html, $m));

        return json_decode(html_entity_decode($m[1]), true);
    }
}
