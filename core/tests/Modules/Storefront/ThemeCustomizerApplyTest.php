<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Juwri\Modules\Storefront\Services\Theme\SchemaV2;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * B3 — the theme-switch + customizer APPLY contract, driven through the real
 * controllers.
 *
 * The storefront contract the e2e journey asserts in a browser, asserted here at
 * the seam: switch the store's template → set one colour + one font → save as a
 * draft (invisible to the public) → publish → the storefront's `:root` block
 * carries them → revert → the previous look is back.
 *
 * The last test is the run's primary deliverable: it pins the list of customizer
 * options that SAVE (they validate, they persist, they are served) but that the
 * template's own stylesheet never reads, so the owner sees no change. Those are
 * logged as ISS-B-301..304 and are deliberately characterized rather than
 * asserted-away — if a template is fixed, that test fails on purpose so the
 * ISSUES entry gets updated instead of silently rotting.
 */
class ThemeCustomizerApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaV2::flushFragments();
        ThemeCustomizerService::flushSchemaMemo();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** The 17 retained storefront templates. */
    public static function modernTemplates(): array
    {
        $out = [];
        foreach (KeptTemplates::MODERN as $t) {
            $out[$t] = [$t];
        }

        return $out;
    }

    /** Every template that ships a customizer schema: the 17 + pageview. */
    public static function schemaTemplates(): array
    {
        $out = [];
        foreach (KeptTemplates::withPageview() as $t) {
            $out[$t] = [$t];
        }

        return $out;
    }

    /** The whole B3 scope: the 17 + pageview (flipbook was removed in WI-03). */
    public static function nineteenTemplates(): array
    {
        $out = [];
        foreach ([...KeptTemplates::MODERN, 'pageview'] as $t) {
            $out[$t] = [$t];
        }

        return $out;
    }

    /* ─────────────────────────── the apply contract ───────────────────────── */

    /**
     * The WI's core claim: publish a colour + a font, and the storefront serves
     * them in the `:root` token block every kept layout includes.
     */
    #[DataProvider('schemaTemplates')]
    public function test_publishing_a_colour_and_a_font_puts_them_on_the_storefront(string $template): void
    {
        [$owner, $store] = $this->store($template);

        $this->actingAs($owner)
            ->postJson("theme-customizer/{$store->id}/draft", [
                'values' => ['colors.primary' => '#3a7bd5', 'fonts.pair' => 'jakarta'],
            ])
            ->assertOk();

        $this->actingAs($owner)->postJson("theme-customizer/{$store->id}/publish")->assertOk();

        // The storefront renders this partial inside its layout; the journey
        // reads the same vars off document.documentElement in a real browser.
        $html = view('store_templates._partials.theme-tokens', ['store' => $store->fresh()])->render();

        $this->assertStringContainsString(
            '--tc-primary:#3a7bd5;', $html,
            "[{$template}] published brand colour must reach the storefront's token block"
        );
        $this->assertStringContainsString(
            'Plus Jakarta Sans', $html,
            "[{$template}] published font pairing must reach the storefront's token block"
        );
    }

    /**
     * The draft/preview/live split, and revert. Three states, three different
     * answers — the discriminating shape of the whole feature.
     */
    public function test_a_draft_is_invisible_to_the_public_until_published_and_revert_restores_the_previous_look(): void
    {
        [$owner, $store] = $this->store('crave');
        $tokens = fn () => view('store_templates._partials.theme-tokens', ['store' => $store->fresh()])->render();

        // 1. Nothing published yet → no token block at all (overrides-only emission).
        $this->assertSame('', trim($tokens()));

        // 2. A draft alone must NOT reach a public visitor: theme_setting()
        //    merges the draft only under ?preview_theme=1 for the owner.
        //    (Saving a draft DOES materialize the theme's own defaults, so the
        //    block appears — what must be absent is the DRAFTED value.)
        $this->actingAs($owner)
            ->postJson("theme-customizer/{$store->id}/draft", ['values' => ['colors.primary' => '#3a7bd5']])
            ->assertOk();
        $this->assertStringContainsString('--tc-primary:#3fd463;', $tokens(), 'the theme default stands while the change is only a draft');
        $this->assertStringNotContainsString('--tc-primary:#3a7bd5', $tokens(), 'a draft must not leak onto the public storefront');

        // 3. Publish → it is live.
        $this->actingAs($owner)->postJson("theme-customizer/{$store->id}/publish")->assertOk();
        $this->assertStringContainsString('--tc-primary:#3a7bd5;', $tokens());

        $history = $this->actingAs($owner)->getJson("theme-customizer/{$store->id}/history")->assertOk()->json('history');
        $firstVersion = $history[count($history) - 1]['id'];

        // 4. A second, different look goes live.
        $this->actingAs($owner)
            ->postJson("theme-customizer/{$store->id}/draft", ['values' => ['colors.primary' => '#c2185b']])
            ->assertOk();
        $this->actingAs($owner)->postJson("theme-customizer/{$store->id}/publish")->assertOk();
        $this->assertStringContainsString('--tc-primary:#c2185b;', $tokens());

        // 5. Revert → the FIRST published colour is back, not "nothing".
        $this->actingAs($owner)->postJson("theme-customizer/{$store->id}/version/{$firstVersion}/revert")->assertOk();
        $this->assertStringContainsString(
            '--tc-primary:#3a7bd5;', $tokens(),
            'revert must restore the previously published look, not clear the settings'
        );
    }

    /**
     * The switch, read back through the customizer's own controller: after
     * switching, the editor must serve the NEW template's schema.
     *
     * (The public storefront is deliberately NOT rendered here: its shared
     * layout reads a `$settings` object that is only bound for a real web
     * request, so `GET /{slug}` 500s under the test runner while serving 200 in
     * a browser. The storefront half of the contract belongs to the B3 journey,
     * which asserts it in Chrome across all 19 templates.)
     */
    #[DataProvider('nineteenTemplates')]
    public function test_switching_the_template_persists_and_rebuilds_the_editor(string $template): void
    {
        [$owner, $store] = $this->store('crave');

        $this->actingAs($owner)
            ->postJson("theme-customizer/{$store->id}/template", ['template' => $template])
            ->assertOk();

        $this->assertSame(
            $template,
            StoreSettings::legacy((int) $store->id, 'store_template'),
            "[{$template}] the switch must persist as the store_template post option"
        );

        $this->actingAs($owner)
            ->getJson("theme-customizer/{$store->id}")
            ->assertOk()
            ->assertJsonPath('template', $template);

        // Every template in scope must offer the two controls B3 drives.
        $fields = array_keys(ThemeCustomizerService::fields(
            ThemeCustomizerService::schemaFor($template)
        ));
        $this->assertContains('colors.primary', $fields);
        $this->assertContains('fonts.pair', $fields);
    }

    /* ───────────────────── the schema-limited templates ───────────────────── */

    /**
     * flipbook ships no theme.json, so the customizer offers it nothing — and
     * crucially it must REFUSE a colour rather than store one it cannot render.
     */
    public function test_flipbook_exposes_no_options_and_refuses_a_colour(): void
    {
        $schema = ThemeCustomizerService::schemaFor('flipbook');

        $this->assertSame([], ThemeCustomizerService::fields($schema), 'flipbook must expose no customizer fields');
        $this->assertSame([], ThemeCustomizerService::defaults($schema));

        [$owner, $store] = $this->store('flipbook');

        $this->expectException(ValidationException::class);
        ThemeCustomizerService::saveDraft($store, 'flipbook', ['colors.primary' => '#3a7bd5']);
    }

    /* ────────────── options that save but do not apply (ISS-B-301+) ───────── */

    /**
     * The B3 deliverable. Every case below is an option the customizer happily
     * saves and publishes — and that the template's own stylesheet never reads,
     * so the owner sees no change on the storefront.
     *
     * These are CHARACTERIZED, not asserted-away: the assertions state today's
     * (broken) behaviour so the gap is executable and reviewable. If a template
     * is fixed, the matching assertion fails — that is the signal to close the
     * ISSUES entry, not to delete the test.
     */
    public function test_options_that_save_but_do_not_apply(): void
    {
        // ISS-B-301 (FIXED) — the 17 MODERN templates now wire a spacing rule to
        // --ts-gap-* (overrides-only emission, literal/0 fallback → default look
        // unchanged), so layout.density applies. pageview reads none of its served
        // tokens for a different reason (its CSS hardcodes --pv-*) — that stays
        // tracked as ISS-B-303 below, so it is deliberately NOT in this loop.
        foreach (KeptTemplates::MODERN as $template) {
            $vars = Tokens::forTemplate($template, ['layout.density' => 'compact']);
            $this->assertNotSame([], array_keys($vars), "[{$template}] layout.density must emit spacing tokens");
            $this->assertTrue(
                $this->consumes($template, '--ts-'),
                "[{$template}] must read --ts-* so layout.density applies (ISS-B-301 fix)"
            );
        }

        // ISS-B-302 (FIXED) — vivid/feast/velvet now route their own radius vars
        // through the customizer's --tr-* tokens (overrides-only, literal fallback),
        // so layout.radius applies. Asserted, not characterized.
        foreach (['vivid', 'feast', 'velvet'] as $template) {
            $vars = Tokens::forTemplate($template, ['layout.radius' => 'pill']);
            $this->assertArrayHasKey('--tr-control', $vars, "[{$template}] layout.radius must emit radius tokens");
            $this->assertTrue(
                $this->consumes($template, '--tr-'),
                "[{$template}] must read --tr-* so layout.radius applies (ISS-B-302 fix)"
            );
        }

        // ISS-B-303 — pageview serves the colour + font tokens but its own CSS
        // hardcodes --pv-primary from $store->color and reads none of them.
        foreach (['--tc-', '--tf-', '--tr-', '--ts-'] as $prefix) {
            $this->assertFalse(
                $this->consumes('pageview', $prefix),
                "pageview now reads {$prefix}* — ISS-B-303 (pageview served tokens its own CSS "
                .'ignored) is FIXED; close the issue.'
            );
        }

        // ISS-B-304 — colors.paper is pageview-only: it validates, it persists,
        // and Tokens emits nothing for it at all.
        $schema = ThemeCustomizerService::schemaFor('pageview');
        $this->assertArrayHasKey('colors.paper', ThemeCustomizerService::fields($schema));
        $this->assertSame(
            [],
            ThemeCustomizerService::validate($schema, ['colors.paper' => '#ffffff']),
            'colors.paper validates — that is the trap: the owner can save it'
        );
        $this->assertSame(
            [],
            Tokens::forTemplate('pageview', ['colors.paper' => '#ffffff']),
            'colors.paper must emit no token — if it now does, ISS-B-304 is FIXED; close the issue'
        );
    }

    /* ─────────────────────────────── plumbing ────────────────────────────── */

    /**
     * Does `{$template}`'s own CSS/markup read any `var({$prefix}…)`?
     * Scans both roots a template can live in — the Blade views and the
     * published asset bundle — so a theme that switched where it keeps its
     * stylesheet is still covered.
     */
    private function consumes(string $template, string $prefix): bool
    {
        $needle = 'var('.$prefix;
        foreach ([
            base_path('resources/views/store_templates/'.$template),
            base_path('../assets/store_templates/'.$template),
        ] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach ($this->walk($dir) as $file) {
                if (! preg_match('/\.(css|blade\.php|js|scss)$/', $file)) {
                    continue;
                }
                if (stripos((string) file_get_contents($file), $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function walk(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    /** @return array{0:User,1:Store} */
    private function store(string $template): array
    {
        // The storefront shell resolves the CURRENT language row (direction,
        // locale) — with an empty languages table `$cur` is null and the page
        // 500s before any token is rendered.
        if (! Language::where('code', 'ar')->exists()) {
            Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        }
        // publicView reads `$store->user->plan()->status`, so the owner needs a
        // real (active) plan — without one the storefront 500s before it renders
        // anything, and the assertion would be about the fixture, not the theme.
        $plan = TestPlans::create([
            'name' => 'B3 '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'user_id' => $owner->id,
            'title' => 'B3 Apply',
            'slug' => 'b3-apply-'.$template.'-'.uniqid(),
            'color' => '#3fd463',
            'sub_title' => 's',
            'description' => 'd',
            'main_image' => 'x.png',
            'cover_image' => 'x.png',
            'address' => 'a',
            'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }
}
