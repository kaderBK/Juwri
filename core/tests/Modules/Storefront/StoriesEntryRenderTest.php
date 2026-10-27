<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Stories Entry Points ("SE" run) storefront render contract —
 * rev/folio-stories/SPEC.md §2/§4. Lane A.
 *
 * Pins the guest render path for the two standalone display themes (folio,
 * pageview): the #seRoot mount + bundle ship only when stories_entry_for()
 * resolves to a non-'none' slug AND the store has a publishable story; the
 * resolution chain (store customizer → super-admin default → 'ribbon') maps
 * onto data-se-entry; pageview remaps 'toolbar' → 'orb'; RTL stamps the flag;
 * and the shipped bundle carries no CSP-blocked inline handlers.
 */
class StoriesEntryRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        // Mirror the shared $settings global the installed app boots with
        // (APP_INSTALLED is unset in the test env, which kills the composer).
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function makeStore(string $template = 'folio'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'S'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }

    private function addStory(Store $store, array $attrs = []): Story
    {
        return Story::create(array_merge([
            'store_id' => $store->id,
            'media_url' => 'story-'.Str::lower(Str::random(5)).'.jpg',
            'caption' => 'Caption',
            'cta_label' => null,
            'cta_url' => null,
            'sort' => 1,
            'active' => true,
        ], $attrs));
    }

    /** Persist a published store-level entry style (customizer select). */
    private function setEntry(Store $store, string $slug, string $template = 'folio'): void
    {
        ThemeSetting::create([
            'store_id' => $store->id,
            'template' => $template,
            'settings' => ['stories.entry' => $slug],
        ]);
        StoreOption::flushOptionMemo();
    }

    private function render(Store $store): string
    {
        return $this->get(route('publicView', $store->slug))->getContent();
    }

    /* ---------------------------------------------------------------- A1 --- */

    public function test_mount_and_bundle_render_for_a_story_bearing_store(): void
    {
        [, $store] = $this->makeStore('folio');
        $this->addStory($store);

        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $res->assertSeeHtml('id="seRoot"');
        // No store/admin override → code fallback 'ribbon'.
        $res->assertSeeHtml('data-se-entry="ribbon"');
        $res->assertSeeHtml('data-se-feed="'.route('stories.public', $store->slug).'"');
        $res->assertSeeHtml('data-se-post="'.$store->id.'"');
        // Both asset tags ship, the script nonce'd.
        $res->assertSeeHtml('assets/global/stories-entries/stories-entries.css');
        $res->assertSeeHtml('assets/global/stories-entries/stories-entries.js');
    }

    public function test_mount_absent_when_store_has_no_publishable_story(): void
    {
        [, $store] = $this->makeStore('folio');
        // No stories at all.
        $this->render($store);
        $this->get(route('publicView', $store->slug))->assertDontSeeHtml('id="seRoot"');

        // An inactive / expired story is not publishable either.
        $this->addStory($store, ['active' => false]);
        $this->addStory($store, ['active' => true, 'expires_at' => now()->subDay()]);
        $this->get(route('publicView', $store->slug))->assertDontSeeHtml('id="seRoot"');
    }

    public function test_mount_absent_when_entry_resolves_to_none(): void
    {
        [, $store] = $this->makeStore('folio');
        $this->addStory($store);
        $this->setEntry($store, 'none');

        $this->get(route('publicView', $store->slug))->assertDontSeeHtml('id="seRoot"');
    }

    /** Repo-root asset path (docroot is the repo root, not core/public). */
    private function bundlePath(string $file): string
    {
        return base_path('../assets/global/stories-entries/'.$file);
    }

    public function test_bundle_js_has_no_inline_event_handlers(): void
    {
        // The bundle is runtime-generated markup; it must obey script-src-attr 'none'.
        $js = file_get_contents($this->bundlePath('stories-entries.js'));
        $this->assertNotEmpty($js);
        $this->assertSame(
            0,
            preg_match_all('/\son(?:click|change|error|load|focus|blur|input|submit)\s*=/i', $js),
            'bundle JS must not emit inline on*= handlers'
        );
    }

    /* ---------------------------------------------------------------- A2 --- */

    public function test_bundle_defines_premium_viewer_contract(): void
    {
        $js = file_get_contents($this->bundlePath('stories-entries.js'));

        // Full-screen scoped viewer, aria-modal, focus trap.
        $this->assertStringContainsString("'se-viewer'", $js);
        $this->assertStringContainsString("'aria-modal'", $js);
        $this->assertStringContainsString("'Tab'", $js, 'focus trap on Tab');
        // Gold segmented progress + 4s auto-advance driven by rAF.
        $this->assertStringContainsString('se-v-seg-fill', $js);
        $this->assertStringContainsString('requestAnimationFrame', $js);
        $this->assertStringContainsString('DURATION = 4000', $js);
        // Close via Esc + prev/next via arrows, RTL-aware.
        $this->assertStringContainsString("'Escape'", $js);
        $this->assertStringContainsString("'ArrowRight'", $js);
        $this->assertStringContainsString('CFG.rtl', $js);
        // Gold CTA pill + seen-state.
        $this->assertStringContainsString('se-v-cta', $js);
        $this->assertStringContainsString('markSeen', $js);
        // Reduced-motion branch present.
        $this->assertStringContainsString('reduceMotion', $js);
    }

    /* ------------------------------------------------ per-slug helpers ----- */

    /** Configure a store-level slug and assert the mount stamps it. */
    private function assertSlugRenders(string $slug, string $template = 'folio', ?string $expect = null): void
    {
        [, $store] = $this->makeStore($template);
        $this->addStory($store);
        $this->setEntry($store, $slug, $template);

        $this->get(route('publicView', $store->slug))->assertOk()->assertSeeHtml('data-se-entry="'.($expect ?? $slug).'"');
    }

    /** Assert the bundle both registers (JS) and styles (CSS) a variant. */
    private function assertVariantBuilt(string $slug): void
    {
        $js = file_get_contents($this->bundlePath('stories-entries.js'));
        $css = file_get_contents($this->bundlePath('stories-entries.css'));
        $this->assertStringContainsString('ENTRIES.'.$slug.' =', $js, "$slug renderer registered");
        $this->assertStringContainsString('.se-entry--'.$slug, $css, "$slug variant styled");
    }

    /* ---------------------------------------------------------------- A3 --- */

    public function test_ribbon_and_orb_render_when_configured(): void
    {
        $this->assertSlugRenders('ribbon');
        $this->assertSlugRenders('orb');
        $this->assertVariantBuilt('ribbon');
        $this->assertVariantBuilt('orb');
    }

    /* ---------------------------------------------------------------- A4 --- */

    public function test_toolbar_and_capsule_render_when_configured_on_folio(): void
    {
        $this->assertSlugRenders('toolbar', 'folio');   // folio keeps the toolbar slug
        $this->assertSlugRenders('capsule', 'folio');
        $this->assertVariantBuilt('toolbar');
        $this->assertVariantBuilt('capsule');
    }

    public function test_toolbar_slug_remaps_to_orb_on_pageview(): void
    {
        [, $store] = $this->makeStore('pageview');
        $this->addStory($store);
        $this->setEntry($store, 'toolbar', 'pageview');

        $res = $this->get(route('publicView', $store->slug));
        $res->assertOk();
        $res->assertSeeHtml('data-se-entry="orb"');       // pageview has no toolbar
        $res->assertDontSeeHtml('data-se-entry="toolbar"');
    }

    public function test_toolbar_variant_targets_the_folio_toolbar_row(): void
    {
        $js = file_get_contents($this->bundlePath('stories-entries.js'));
        $this->assertStringContainsString('#folioTheme .folio-tb-row', $js);
    }

    /* ---------------------------------------------------------------- A5 --- */

    public function test_tassel_and_seal_render_when_configured(): void
    {
        $this->assertSlugRenders('tassel');
        $this->assertSlugRenders('seal');
        $this->assertVariantBuilt('tassel');
        $this->assertVariantBuilt('seal');
    }

    public function test_rtl_entries_carry_the_rtl_class_and_edge_flip(): void
    {
        $js = file_get_contents($this->bundlePath('stories-entries.js'));
        $css = file_get_contents($this->bundlePath('stories-entries.css'));
        // JS stamps se-rtl on the entry button when the store renders RTL.
        $this->assertStringContainsString("CFG.rtl ? ' se-rtl'", $js);
        // The tassel flips its physical slide transform for RTL.
        $this->assertStringContainsString('.se-entry--tassel.se-rtl', $css);
    }

    /* ---------------------------------------------------------------- A6 --- */

    public function test_dogear_and_filmstrip_render_when_configured(): void
    {
        $this->assertSlugRenders('dogear');
        $this->assertSlugRenders('filmstrip');
        $this->assertVariantBuilt('dogear');
        $this->assertVariantBuilt('filmstrip');
    }

    public function test_dogear_fold_never_intercepts_flip_drags(): void
    {
        $css = file_get_contents($this->bundlePath('stories-entries.css'));
        // The fold container is inert; only the small dot is interactive.
        $this->assertMatchesRegularExpression('/\.se-entry--dogear\s*\{[^}]*pointer-events:\s*none/s', $css);
        $this->assertMatchesRegularExpression('/\.se-dogear-dot\s*\{[^}]*pointer-events:\s*auto/s', $css);
    }

    public function test_filmstrip_thumbnails_bind_to_the_feed(): void
    {
        $js = file_get_contents($this->bundlePath('stories-entries.js'));
        $this->assertStringContainsString('Math.min(4, stories.length)', $js, 'up to 4 thumbs');
        $this->assertStringContainsString('thumbButton(', $js);
        $this->assertStringContainsString("isSeen(story.id) ? ' se-seen'", $js, 'seen thumbs dim');
    }

    /* ---------------------------------------------------------------- A7 --- */

    public function test_medallion_and_sheet_render_when_configured(): void
    {
        $this->assertSlugRenders('medallion');
        $this->assertSlugRenders('sheet');
        // Also verify they resolve on pageview (medallion → above first page).
        $this->assertSlugRenders('medallion', 'pageview');
        $this->assertSlugRenders('sheet', 'pageview');
        $this->assertVariantBuilt('medallion');
        $this->assertVariantBuilt('sheet');
    }

    public function test_medallion_rides_the_stage_and_sheet_clears_folio_toolbar(): void
    {
        $js = file_get_contents($this->bundlePath('stories-entries.js'));
        $css = file_get_contents($this->bundlePath('stories-entries.css'));
        // Medallion is re-parented onto the stage (rides zoom/resize).
        $this->assertMatchesRegularExpression('/ENTRIES\.medallion =.*?mountStage\(btn\);/s', $js);
        // Sheet gains the toolbar-clearing offset on folio.
        $this->assertStringContainsString('isFolio() ?', $js);
        $this->assertStringContainsString('.se-entry--sheet.se-above-toolbar', $css);
    }

    /* ---------------------------------------------------------------- A8 --- */

    public function test_ar_default_store_stamps_rtl_flag_on_the_mount(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        [, $store] = $this->makeStore('folio');
        $this->addStory($store);
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['ar', 'en']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');
        StoreOption::flushOptionMemo();

        $res = $this->get(route('publicView', $store->slug));
        $res->assertOk();
        $res->assertSeeHtml('data-se-rtl="1"');
        $res->assertSeeHtml('dir="rtl"');
    }

    public function test_ltr_store_stamps_rtl_zero(): void
    {
        [, $store] = $this->makeStore('folio');
        $this->addStory($store);
        $this->get(route('publicView', $store->slug))->assertSeeHtml('data-se-rtl="0"');
    }

    public function test_reduced_motion_keeps_slideout_entries_reachable(): void
    {
        $css = file_get_contents($this->bundlePath('stories-entries.css'));
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        // The tassel/sheet must rest revealed (transition is off, no hover on touch).
        $this->assertMatchesRegularExpression('/prefers-reduced-motion.*\.se-entry--tassel\s*\{\s*transform:\s*translateX\(0\)/s', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion.*\.se-entry--sheet\s*\{\s*transform:\s*translate\(-50%, 8%\)/s', $css);
    }

    public function test_viewer_tap_zones_do_not_double_flip_for_rtl(): void
    {
        // CSS mirrors the zones via inset-inline-*; the handlers stay logical.
        $js = file_get_contents($this->bundlePath('stories-entries.js'));
        $this->assertMatchesRegularExpression("/zonePrev\.addEventListener\('click', function \(\) \{ go\(-1\); \}\)/", $js);
        $this->assertMatchesRegularExpression("/zoneNext\.addEventListener\('click', function \(\) \{ go\(1\); \}\)/", $js);
    }

    /* ---------------------------------------------------------------- A9 --- */

    public function test_all_ten_slugs_map_onto_the_mount(): void
    {
        // The full contract: every §1 slug renders its own data-se-entry (with
        // pageview's toolbar→orb remap), and 'none' suppresses the mount.
        $folio = ['ribbon', 'orb', 'toolbar', 'capsule', 'tassel', 'seal', 'dogear', 'filmstrip', 'medallion', 'sheet'];
        foreach ($folio as $slug) {
            $this->assertSlugRenders($slug, 'folio');
        }
        // pageview: toolbar collapses to orb, the rest pass through.
        $this->assertSlugRenders('toolbar', 'pageview', 'orb');
        $this->assertSlugRenders('ribbon', 'pageview');
    }

    public function test_inherit_falls_through_to_admin_default_then_ribbon(): void
    {
        // No store value + admin default 'seal' → seal.
        PlatformSettings::set('stories_entry_default', 'seal');
        [, $store] = $this->makeStore('folio');
        $this->addStory($store);
        $this->setEntry($store, 'inherit');
        $this->get(route('publicView', $store->slug))->assertSeeHtml('data-se-entry="seal"');

        // Store value wins over the admin default.
        [, $post2] = $this->makeStore('folio');
        $this->addStory($post2);
        $this->setEntry($post2, 'orb');
        $this->get(route('publicView', $post2->slug))->assertSeeHtml('data-se-entry="orb"');

        // Missing admin option → code fallback 'ribbon'.
        PlatformSettings::forget('stories_entry_default');
        [, $post3] = $this->makeStore('folio');
        $this->addStory($post3);
        $this->get(route('publicView', $post3->slug))->assertSeeHtml('data-se-entry="ribbon"');
    }

    public function test_bundle_has_no_literal_script_or_style_tokens(): void
    {
        // Literal <script>/<style> tokens inside JS strings or comments trip the
        // CSP ratchet lint (SPEC §7). The bundle must carry none.
        foreach (['stories-entries.js', 'stories-entries.css'] as $file) {
            $body = file_get_contents($this->bundlePath($file));
            $this->assertSame(0, preg_match_all('/<\s*script/i', $body), "$file has a <script token");
            $this->assertSame(0, preg_match_all('/<\s*style/i', $body), "$file has a <style token");
        }
    }

    public function test_viewer_styles_are_scoped_to_se_roots(): void
    {
        // Every top-level selector in the bundle CSS must stay under a .se- root
        // so it can never leak into a legacy theme.
        $css = file_get_contents($this->bundlePath('stories-entries.css'));
        $lines = preg_split('/\r?\n/', $css);
        $offenders = [];
        foreach ($lines as $n => $line) {
            // selector line = ends in '{' and is not an at-rule / nested continuation
            if (! preg_match('/\{\s*$/', $line)) {
                continue;
            }
            $sel = trim(preg_replace('/\{\s*$/', '', $line));
            if ($sel === '' || str_starts_with($sel, '@') || str_starts_with($sel, 'from') || str_starts_with($sel, 'to') || preg_match('/^\d/', $sel)) {
                continue;
            }
            // each comma-separated selector must reference a .se- hook
            foreach (explode(',', $sel) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (! str_contains($part, '.se-')) {
                    $offenders[] = ($n + 1).': '.$part;
                }
            }
        }
        $this->assertSame([], $offenders, "unscoped bundle selectors would leak:\n".implode("\n", $offenders));
    }
}
