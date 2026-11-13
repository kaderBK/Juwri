<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreCustomBlock;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\ResellerPages\BuilderTemplates;
use App\Services\Storefront\StorefrontMenuCache;
use App\Services\ThemeCustomizerService;
use App\Storefront\StorefrontExtras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\Html;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Keepsuit\Liquid\Exceptions\SyntaxException;
use Tests\Support\PinsMiddayClock;
use Tests\TestCase;

/**
 * Theme Studio phase A: owner-authored Builder.js custom blocks — dashboard CRUD
 * (store-scoped, plan-limited, feature-gated), the storefront Builder.js
 * templates, the Blade-theme placements (crave + zevoa), the Studio
 * `custom-block` section / `{% custom_block %}` tag, and the render contract
 * (cleanPage at echo, nonce on kept <style>, escaped merge tags, locale filter,
 * drafts never public, another store's block never renders).
 */
class CustomBlocksTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;
    use ThemeStudioFixture;

    private const BLOCK_HTML = '<style>.promo{color:red}</style><div class="promo" data-mb-reveal="up"><h2>Hello {{store.name}}</h2>'
        .'<p>Call {{store.phone}}</p><script>window.pwned=1</script><a href="javascript:alert(1)" onclick="alert(2)">x</a></div>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureSlug = 'cbstudio';
        app()->setLocale('en');
        $this->languages();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->feature(true);
    }

    protected function tearDown(): void
    {
        $this->removeStudioTheme();
        parent::tearDown();
    }

    private function feature(bool $on): void
    {
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        config(['settings.admin_allow_custom_sections' => $on ? 1 : 0, 'settings.admin_allow_theme_studio' => 1]);
    }

    /** A store on a Blade theme whose plan grants custom sections. */
    private function store(string $template = 'crave', array $planSettings = []): Store
    {
        $store = $this->studioStore(true, ['custom_sections' => 1] + $planSettings, $template);
        StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return $store;
    }

    private function owner(Store $store): User
    {
        return User::findOrFail($store->user_id);
    }

    private function block(Store $store, array $attrs = []): StoreCustomBlock
    {
        $html = Html::cleanPage($attrs['html'] ?? self::BLOCK_HTML);

        return StoreCustomBlock::create(array_merge([
            'store_id' => $store->id,
            'name' => 'Promo',
            'html' => $html,
            'published_html' => $html,
            'status' => StoreCustomBlock::STATUS_PUBLISHED,
            'placement' => 'after-hero',
        ], array_diff_key($attrs, ['html' => 1])));
    }

    private function url(string $name, Store $store, array $params = []): string
    {
        return route($name, $params + ['store' => $store->id]);
    }

    private function storefront(Store $store): string
    {
        return (string) $this->get('/'.$store->slug)->assertOk()->getContent();
    }

    // ------------------------------------------------------------ routes + gate

    public function test_every_route_carries_auth_csp_and_the_feature_gate(): void
    {
        foreach (['index', 'store', 'edit', 'save', 'update', 'publish', 'unpublish', 'destroy', 'preview', 'template', 'assets', 'ai'] as $name) {
            $route = Route::getRoutes()->getByName('custom-blocks.'.$name);
            $this->assertNotNull($route, $name);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('feature:custom_sections', $middleware, $name);
            $this->assertContains('auth', $middleware, $name);
            $this->assertContains('csp.dashboard', $middleware, $name);
            $this->assertContains('web', $middleware, $name);
        }
    }

    public function test_dark_feature_refuses_the_dashboard(): void
    {
        $store = $this->store();
        $this->feature(false);

        $this->actingAs($this->owner($store))->get($this->url('custom-blocks.index', $store))->assertForbidden();
    }

    // ------------------------------------------------------------ CRUD

    public function test_owner_creates_designs_publishes_places_and_deletes_a_block(): void
    {
        $store = $this->store();
        $owner = $this->owner($store);

        $html = $this->actingAs($owner)->get($this->url('custom-blocks.index', $store))->assertOk()->getContent();
        $this->assertStringContainsString('No custom blocks yet', $html);
        $this->assertStringContainsString('storefront-faq', $html, 'storefront templates are offered');
        $this->assertStringNotContainsString('Y2_a_2_Column_Layout_theme', $html, 'page layouts are not');

        $res = $this->actingAs($owner)->post($this->url('custom-blocks.store', $store), [
            'name' => 'Weekend offer', 'template' => 'storefront-faq', 'placement' => 'before-menu', 'locale' => '',
        ]);
        $block = StoreCustomBlock::where('store_id', $store->id)->sole();
        $res->assertRedirect($this->url('custom-blocks.edit', $store, ['id' => $block->id]));
        $this->assertSame('storefront-faq', json_decode((string) $block->design_json, true)['base']);
        $this->assertStringContainsString('data-mb-accordion', (string) $block->html, 'seeded from the template');
        $this->assertSame(StoreCustomBlock::STATUS_DRAFT, $block->status);
        $this->assertNull($block->published_html);

        // Editor = the shared GrapesJS view (WI-05d) wired to this block's endpoints.
        $edit = $this->actingAs($owner)->get($this->url('custom-blocks.edit', $store, ['id' => $block->id]))->assertOk()->getContent();
        $this->assertStringContainsString('grapesjs.init(', $edit);
        $this->assertStringContainsString(json_encode($this->url('custom-blocks.save', $store, ['id' => $block->id])), $edit);
        $this->assertStringContainsString('base: "storefront-faq"', $edit);
        $this->assertStringContainsString('data-mb-accordion', $edit, 'the draft HTML is handed to the editor');
        // E2 follow-up: the shared editor's back link names custom blocks (cfg backLabel), not pages.
        $this->assertStringContainsString('Back to custom blocks', $edit);
        $this->assertStringNotContainsString('Back to pages', $edit);

        // Save sanitizes the draft (script / on* / javascript: gone).
        $this->actingAs($owner)->put($this->url('custom-blocks.save', $store, ['id' => $block->id]), [
            'html' => self::BLOCK_HTML, 'design' => json_encode(['name' => 'PageElement']), 'base' => 'storefront-promo-banner',
        ])->assertOk()->assertJson(['ok' => true]);
        $block->refresh();
        $this->assertStringNotContainsString('<script', (string) $block->html);
        $this->assertStringNotContainsString('onclick', (string) $block->html);
        $this->assertStringNotContainsString('javascript:', (string) $block->html);
        $this->assertStringContainsString('data-mb-reveal="up"', (string) $block->html);
        $this->assertSame('storefront-promo-banner', json_decode((string) $block->design_json, true)['base']);

        // Drafts never reach the storefront.
        $this->assertStringNotContainsString('mbcb-'.$block->id, $this->storefront($store));

        $this->actingAs($owner)->postJson($this->url('custom-blocks.publish', $store, ['id' => $block->id]))->assertOk()->assertJson(['ok' => true]);
        $block->refresh();
        $this->assertSame(StoreCustomBlock::STATUS_PUBLISHED, $block->status);
        $this->assertSame($block->html, $block->published_html);
        $this->assertStringContainsString('id="mbcb-'.$block->id.'"', $this->storefront($store));

        $this->actingAs($owner)->post($this->url('custom-blocks.update', $store, ['id' => $block->id]), [
            'name' => 'Renamed', 'placement' => 'footer', 'locale' => 'ar',
        ])->assertRedirect();
        $block->refresh();
        $this->assertSame(['Renamed', 'footer', 'ar'], [$block->name, $block->placement, $block->locale]);

        $this->actingAs($owner)->post($this->url('custom-blocks.update', $store, ['id' => $block->id]), [
            'name' => 'Renamed', 'placement' => 'sidebar',
        ])->assertSessionHasErrors('placement');

        $this->actingAs($owner)->post($this->url('custom-blocks.unpublish', $store, ['id' => $block->id]))->assertRedirect();
        $this->assertNull($block->refresh()->published_html);

        $this->actingAs($owner)->get($this->url('custom-blocks.preview', $store, ['id' => $block->id]))->assertOk()->assertSeeHtml('Draft preview')->assertDontSeeHtml('window.pwned');

        $this->actingAs($owner)->delete($this->url('custom-blocks.destroy', $store, ['id' => $block->id]))->assertRedirect();
        $this->assertSame(0, StoreCustomBlock::where('store_id', $store->id)->count());
    }

    public function test_template_switch_only_accepts_storefront_templates(): void
    {
        $store = $this->store();
        $block = $this->block($store);
        $owner = $this->owner($store);

        $this->actingAs($owner)->postJson($this->url('custom-blocks.template', $store, ['id' => $block->id]), ['template' => 'Y4_Pricing_Table_theme'])->assertStatus(422);
        $this->actingAs($owner)->postJson($this->url('custom-blocks.template', $store, ['id' => $block->id]), ['template' => '../Y0_Blank'])->assertStatus(422);
        $this->actingAs($owner)->postJson($this->url('custom-blocks.template', $store, ['id' => $block->id]), ['template' => 'storefront-coupon'])->assertOk();
        $this->assertStringContainsString('sfb-coupon', (string) $block->refresh()->html);
    }

    public function test_block_limit_defaults_to_five_and_follows_the_plan(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $store = $this->store();
        $owner = $this->owner($store);
        $this->assertSame(5, StoreCustomBlock::limitFor($owner));

        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($owner)->post($this->url('custom-blocks.store', $store), ['name' => 'B'.$i])->assertRedirect();
        }
        $this->assertSame(5, StoreCustomBlock::where('store_id', $store->id)->count(), 'the sixth block is refused');
        $this->actingAs($owner)->get($this->url('custom-blocks.index', $store))->assertOk()->assertSeeHtml('custom block limit');

        $two = $this->store('crave', ['custom_blocks_limit' => 2]);
        $this->assertSame(2, StoreCustomBlock::limitFor($this->owner($two)));
        $unlimited = $this->store('crave', ['custom_blocks_limit' => 999]);
        $this->assertNull(StoreCustomBlock::limitFor($this->owner($unlimited)));
    }

    public function test_another_owners_store_and_another_stores_block_are_404(): void
    {
        $mine = $this->store();
        $theirs = $this->store();
        $theirBlock = $this->block($theirs);
        $me = $this->owner($mine);

        $this->actingAs($me)->get($this->url('custom-blocks.index', $theirs))->assertNotFound();
        $this->actingAs($me)->post($this->url('custom-blocks.store', $theirs), ['name' => 'X'])->assertNotFound();
        foreach ([$theirs, $mine] as $store) { // their store, or my store + their block id
            $q = ['id' => $theirBlock->id];
            $this->actingAs($me)->get($this->url('custom-blocks.edit', $store, $q))->assertNotFound();
            $this->actingAs($me)->put($this->url('custom-blocks.save', $store, $q), ['html' => '<p>hijack</p>'])->assertNotFound();
            $this->actingAs($me)->postJson($this->url('custom-blocks.publish', $store, $q))->assertNotFound();
            $this->actingAs($me)->post($this->url('custom-blocks.unpublish', $store, $q))->assertNotFound();
            $this->actingAs($me)->post($this->url('custom-blocks.update', $store, $q), ['name' => 'X'])->assertNotFound();
            $this->actingAs($me)->get($this->url('custom-blocks.preview', $store, $q))->assertNotFound();
            $this->actingAs($me)->postJson($this->url('custom-blocks.template', $store, $q), ['template' => 'storefront-faq'])->assertNotFound();
            $this->actingAs($me)->postJson($this->url('custom-blocks.ai', $store, $q))->assertNotFound();
            $this->actingAs($me)->delete($this->url('custom-blocks.destroy', $store, $q))->assertNotFound();
        }
        $this->assertSame(1, StoreCustomBlock::where('id', $theirBlock->id)->where('store_id', $theirs->id)->count());
        $this->assertStringNotContainsString('hijack', (string) $theirBlock->refresh()->html);
    }

    // ------------------------------------------------------------ render contract

    public function test_merge_tags_are_escaped_and_the_kept_style_carries_the_nonce(): void
    {
        $store = $this->store();
        $store->update(['title' => 'Bob <img src=x onerror=alert(1)> & Co', 'phone' => '+966 50 123']);
        $block = $this->block($store);

        $html = $this->storefront($store);
        $this->assertStringContainsString('Hello Bob &lt;img src=x onerror=alert(1)&gt; &amp; Co', $html);
        $this->assertStringContainsString('Call +966 50 123', $html);
        $this->assertStringNotContainsString('window.pwned', $html);
        $this->assertStringNotContainsString('javascript:alert(1)', $html);

        preg_match('#<section class="mbcb" id="mbcb-'.$block->id.'".*?</section>#s', $html, $m);
        $this->assertNotEmpty($m, 'block section rendered');
        $this->assertMatchesRegularExpression('#<style nonce="([^"]+)">\.mb-doc \.promo\{color:red\}</style>#', $m[0]);
        preg_match('#<style nonce="([^"]+)">\.mb-doc \.promo#', $m[0], $n);
        $this->assertStringContainsString('<script nonce="'.$n[1].'"', $html, 'the block style nonce is the page nonce');
        $this->assertStringNotContainsString(' onclick=', $m[0]);
    }

    public function test_placements_render_in_crave_and_zevoa_with_the_runtime_once(): void
    {
        foreach (['crave', 'zevoa'] as $theme) {
            $store = $this->store($theme);
            $a = $this->block($store, ['placement' => 'after-hero', 'name' => 'A']);
            $b = $this->block($store, ['placement' => 'footer', 'name' => 'B']);
            $none = $this->block($store, ['placement' => null, 'name' => 'Studio only']);

            $html = $this->storefront($store);
            $this->assertStringContainsString('id="mbcb-'.$a->id.'" data-mbcb-placement="after-hero" data-mb-pending', $html, $theme);
            $this->assertStringContainsString('id="mbcb-'.$b->id.'" data-mbcb-placement="footer"', $html, $theme);
            $this->assertStringNotContainsString('mbcb-'.$none->id, $html, $theme.': unplaced blocks are Studio-only');
            $this->assertSame(1, substr_count($html, 'assets/builder/runtime/mb-builder.js'), $theme.': runtime once');
            $this->assertStringContainsString('window.mbAnchor', $html, $theme.': placement engine present');
        }
    }

    public function test_feature_off_renders_nothing_anywhere(): void
    {
        $crave = $this->store('crave');
        $zevoa = $this->store('zevoa');
        $studio = $this->studioThemeStore();
        $blocks = [$this->block($crave), $this->block($zevoa), $studio[1]];
        $this->feature(false);

        foreach ([$crave, $zevoa, $studio[0]] as $i => $store) {
            $html = $this->storefront($store);
            $this->assertStringNotContainsString('Hello ', $html, "store {$i}");
            $this->assertStringNotContainsString('mbcb-'.$blocks[$i]->id, $html);
            $this->assertStringNotContainsString('data-mb-custom-block', $html);
        }
    }

    public function test_locale_filter_shows_a_language_block_only_in_that_language(): void
    {
        $store = $this->store();
        $all = $this->block($store, ['name' => 'All']);
        $ar = $this->block($store, ['name' => 'Arabic', 'locale' => 'ar', 'html' => '<p>مرحبا بكم</p>']);

        $en = $this->storefront($store);
        $this->assertStringContainsString('mbcb-'.$all->id, $en);
        $this->assertStringNotContainsString('mbcb-'.$ar->id, $en);

        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['ar', 'en']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');
        $arHtml = $this->storefront($store);
        $this->assertStringContainsString('mbcb-'.$ar->id, $arHtml);
        $this->assertStringContainsString('mbcb-'.$all->id, $arHtml);

        $this->assertSame([$all->id], StoreCustomBlock::visibleFor($store->id, 'en')->pluck('id')->all());
        $this->assertSame([$all->id, $ar->id], StoreCustomBlock::visibleFor($store->id, 'ar')->pluck('id')->all());
    }

    public function test_publishing_busts_the_storefront_cache(): void
    {
        $store = $this->store();
        $block = $this->block($store, ['status' => StoreCustomBlock::STATUS_DRAFT, 'published_html' => null]);
        $before = StorefrontMenuCache::version($store->id);

        $this->actingAs($this->owner($store))->postJson($this->url('custom-blocks.publish', $store, ['id' => $block->id]))->assertOk();
        $after = StorefrontMenuCache::version($store->id);
        $this->assertNotSame($before, $after);

        $this->actingAs($this->owner($store))->delete($this->url('custom-blocks.destroy', $store, ['id' => $block->id]))->assertRedirect();
        $this->assertNotSame($after, StorefrontMenuCache::version($store->id), 'deleting a live block busts too');
    }

    // ------------------------------------------------------------ Studio (Liquid)

    /** @return array{0:Store,1:StoreCustomBlock} a Studio-theme store whose index places its block as a custom-block section */
    private function studioThemeStore(string $width = 'contained'): array
    {
        $store = $this->store('mp:cbstudio@1');
        $block = $this->block($store, ['placement' => null]);
        $this->makeStudioTheme();
        File::put(storage_path('app/marketplace-themes/cbstudio/1/templates/index.json'), json_encode([
            'sections' => [
                'hero' => ['type' => 'probe', 'settings' => ['heading' => 'Welcome']],
                'cb1' => ['type' => 'custom-block', 'settings' => ['block' => (string) $block->id, 'width' => $width]],
                'cb2' => ['type' => 'custom-block', 'settings' => ['block' => (string) $block->id, 'width' => 'full']],
            ],
            'order' => ['hero', 'cb1', 'cb2'],
        ]));

        return [$store, $block];
    }

    public function test_studio_custom_block_section_renders_the_block_and_runtime_once(): void
    {
        [$store, $block] = $this->studioThemeStore();

        $html = $this->storefront($store);
        $this->assertStringContainsString('id="mb-section-cb1"', $html);
        $this->assertStringContainsString('class="mbs-custom-block mbs-custom-block--contained"', $html);
        $this->assertSame(2, substr_count($html, 'data-mb-custom-block="'.$block->id.'"'));
        $this->assertStringContainsString('Hello '.e($store->title), $html);
        $this->assertStringNotContainsString('window.pwned', $html);
        $this->assertSame(1, substr_count($html, 'assets/builder/runtime/mb-builder.js'), 'runtime written once per page');
    }

    /** Render a Liquid source the way the storefront does (ThemeContext build + registers). */
    private function liquid(string $source, Store $store, ?array $registers = null, array $globals = []): string
    {
        $ctx = app(ThemeContext::class);
        $o = (object) store_options($store->id);
        $flags = ['on_table' => false];
        $vars = [
            'store' => $store, 'postOptions' => $o, 'flags' => $flags,
            'branches' => Branch::where('store_id', $store->id)->get(), 'activeBranch' => null, 'customer' => null, 'table' => null,
        ];
        $vars['extras'] = StorefrontExtras::for($store, $o, $flags);

        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient)->render(
            $source, $globals + $ctx->build($vars), 'live', $registers ?? $ctx->registers($vars),
        );
    }

    public function test_the_liquid_tag_never_renders_another_stores_block(): void
    {
        $a = $this->store();
        $b = $this->store();
        $mine = $this->block($a);
        $theirs = $this->block($b, ['html' => '<p>SECRET-OF-B</p>']);
        $draft = $this->block($a, ['status' => StoreCustomBlock::STATUS_DRAFT, 'published_html' => null, 'html' => '<p>DRAFT</p>']);

        $this->assertStringContainsString('data-mb-custom-block="'.$mine->id.'"', $this->liquid('{% custom_block '.$mine->id.' %}', $a));
        $this->assertStringContainsString('data-mb-custom-block="'.$mine->id.'"', $this->liquid("{% custom_block '".$mine->id."' %}", $a));
        $this->assertSame('', trim($this->liquid('{% custom_block '.$theirs->id.' %}', $a)), 'store B block on store A');
        $this->assertSame('', trim($this->liquid('{% custom_block '.$draft->id.' %}', $a)), 'draft');
        $this->assertSame('', trim($this->liquid("{% custom_block 'abc' %}{% custom_block 0 %}", $a)));

        // The register cannot be forged from theme data, and no register → nothing.
        $forged = ['store' => $b, 'emitted' => new \ArrayObject];
        $this->assertStringNotContainsString('SECRET-OF-B', $this->liquid(
            '{% assign custom_block_context = x %}{% custom_block '.$theirs->id.' %}', $a, null, ['custom_block_context' => $forged, 'x' => $forged]
        ));
        $this->assertSame('', trim($this->liquid('{% custom_block '.$mine->id.' %}', $a, ['store_id' => $a->id])));

        // Strict (upload) environment rejects a missing id at parse time.
        $this->expectException(SyntaxException::class);
        (new LiquidEnvironmentFactory)->upload()->parseString('{% custom_block %}');
    }

    public function test_customizer_custom_block_setting_must_be_one_of_the_stores_blocks(): void
    {
        $a = $this->store();
        $b = $this->store();
        $mine = $this->block($a);
        $theirs = $this->block($b);
        $schema = ['settings' => [['id' => 'block', 'type' => 'custom_block', 'label' => 'Block', 'default' => '']]];

        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($schema, ['block' => (string) $mine->id], $a));
        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($schema, ['block' => $mine->id], $a));
        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($schema, ['block' => ''], $a));
        $this->assertArrayHasKey('block', ThemeCustomizerService::validateSectionSettings($schema, ['block' => (string) $theirs->id], $a));
        $this->assertArrayHasKey('block', ThemeCustomizerService::validateSectionSettings($schema, ['block' => '<b>'], $a));

        $picker = ThemeCustomizerService::pickerOptions($a)['custom_block'];
        $this->assertSame([['id' => $mine->id, 'name' => 'Promo']], $picker);
    }

    // ------------------------------------------------------------ templates

    public function test_storefront_templates_are_offered_only_on_this_surface_and_clean(): void
    {
        $ids = array_column(BuilderTemplates::forSurface('storefront'), 'id');
        $expected = ['promo-banner', 'event', 'story', 'video', 'gallery', 'hours', 'faq', 'coupon', 'app-download', 'testimonial'];
        foreach ($expected as $id) {
            $this->assertContains('storefront-'.$id, $ids);
        }
        $this->assertContains('Y0_Blank', $ids);
        $this->assertCount(11, $ids);
        $this->assertSame([], array_values(array_filter(array_column(BuilderTemplates::all(), 'id'), fn ($i) => str_starts_with($i, 'storefront-'))), 'other surfaces never list them');

        foreach ($expected as $id) {
            $tpl = 'storefront-'.$id;
            $this->assertNull(BuilderTemplates::design($tpl), $tpl.': the editor builds its state from the HTML');
            $clean = Html::cleanPage(BuilderTemplates::html($tpl));
            $this->assertStringStartsWith('<div class="mb-doc">', $clean, $tpl.' keeps its scoped styles');
            $this->assertStringNotContainsString('<script', $clean, $tpl);
            $this->assertStringNotContainsString(' onclick', $clean, $tpl);
            if (! in_array($id, ['gallery', 'testimonial'], true)) {
                $this->assertStringContainsString('{{store.', $clean, $tpl.' uses merge tags');
            }
        }
        $this->assertStringContainsString('data-mb-accordion', BuilderTemplates::html('storefront-faq'));
        $this->assertStringContainsString('data-mb-carousel', BuilderTemplates::html('storefront-gallery'));
        $this->assertStringContainsString('data-mb-carousel', BuilderTemplates::html('storefront-testimonial'));
    }
}
