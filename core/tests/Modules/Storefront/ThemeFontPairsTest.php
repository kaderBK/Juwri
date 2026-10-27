<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Juwri\Modules\Storefront\Services\Theme\SchemaV2;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use Tests\TestCase;

/**
 * Customizer v2 — WI-T2: the self-hosted font-pair registry.
 *
 * Four things are pinned, in order of how badly they'd hurt:
 *  1. every WOFF2 the registry names exists on disk (a missing file is a font
 *     that silently never loads on a live storefront);
 *  2. every pair has an Arabic face in BOTH stacks — the platform's default
 *     locale is `ar`, so a Latin-only pair is a broken pair;
 *  3. selecting a pair emits --tf-heading/--tf-body and that pair's @font-face
 *     blocks, and only that pair's;
 *  4. no pair selected → no @font-face, no --tf-* var, no change to what a
 *     store renders today. That is the parity contract for this WI.
 */
class ThemeFontPairsTest extends TestCase
{
    use RefreshDatabase;

    /** Every first-party template includes the shared `tokens` fragment. */
    private const TEMPLATES = [...KeptTemplates::MODERN, 'pageview'];

    protected function setUp(): void
    {
        parent::setUp();
        SchemaV2::flushFragments();
        ThemeCustomizerService::flushSchemaMemo();
        FontPairs::flush();
    }

    /* ───────────────────────────── the registry ─────────────────────────── */

    public function test_the_registry_is_not_empty(): void
    {
        $this->assertNotEmpty(FontPairs::all(), 'config/theme-fonts.php must declare at least one pair');
    }

    /**
     * The one that matters most: a path in the registry that is not on disk is a
     * 404 per face on every storefront that picks the pair, and nothing else in
     * the stack would notice.
     */
    public function test_every_woff2_a_pair_references_exists_on_disk(): void
    {
        foreach (FontPairs::all() as $id => $pair) {
            $files = FontPairs::files($id);
            $this->assertNotEmpty($files, "[{$id}] declares no font files");

            foreach ($files as $file) {
                $this->assertSame(
                    $file,
                    FontPairs::sanitizeFile($file),
                    "[{$id}] path [{$file}] is not a plain committed .woff2 asset path"
                );
                $this->assertFileExists(
                    base_path('../'.$file),
                    "[{$id}] references [{$file}], which is not committed to this repo. "
                    .'WI-T2 may only build pairs out of files that already exist — no CDN, no downloads.'
                );
            }
        }
    }

    public function test_no_pair_reaches_outside_the_repo_for_a_font(): void
    {
        foreach (FontPairs::all() as $id => $pair) {
            foreach (FontPairs::faces($id) as $face) {
                $this->assertStringNotContainsString('//', (string) $face['file'], "[{$id}] uses an absolute URL");
                $this->assertStringNotContainsString(
                    'googleapis',
                    (string) $face['file'],
                    "[{$id}] pulls from Google Fonts — the registry is self-hosted only."
                );
            }
        }
    }

    /**
     * Rule 2 of the registry: the default locale is ar. A pair with no Arabic
     * face would drop every Arabic storefront that selects it to a system
     * substitute the owner never chose.
     */
    public function test_every_pair_has_an_arabic_face_in_both_stacks(): void
    {
        $arabic = FontPairs::arabicFamily();

        foreach (FontPairs::all() as $id => $pair) {
            $scripts = array_column(FontPairs::faces($id), 'script');
            $this->assertContains(
                'arabic',
                $scripts,
                "[{$id}] has no Arabic face. The platform default locale is ar — a Latin-only pair is a broken pair."
            );
            $this->assertContains('latin', $scripts, "[{$id}] has no Latin face");

            $families = array_column(FontPairs::faces($id), 'family');
            $this->assertContains($arabic, $families, "[{$id}] must declare @font-face for [{$arabic}]");

            $stacks = FontPairs::stacks($id);
            $this->assertNotSame([], $stacks, "[{$id}] must yield both stacks");
            foreach (['heading', 'body'] as $slot) {
                $this->assertStringContainsString(
                    $arabic,
                    $stacks[$slot],
                    "[{$id}] --tf-{$slot} must fall through to [{$arabic}] for Arabic text"
                );
            }
        }
    }

    public function test_every_face_declares_a_family_weight_and_style_that_survive_sanitising(): void
    {
        foreach (FontPairs::all() as $id => $pair) {
            $this->assertNotSame('', (string) ($pair['label'] ?? ''), "[{$id}] needs a label");

            $seen = [];
            foreach (FontPairs::faces($id) as $face) {
                $family = (string) ($face['family'] ?? '');
                $this->assertSame($family, FontPairs::sanitizeFamily($family), "[{$id}] bad family [{$family}]");

                $weight = (string) ($face['weight'] ?? '');
                $this->assertSame($weight, FontPairs::sanitizeWeight($weight), "[{$id}] bad weight [{$weight}]");

                $style = (string) ($face['style'] ?? 'normal');
                $this->assertContains($style, ['normal', 'italic'], "[{$id}] bad style [{$style}]");

                $range = (string) ($face['unicode_range'] ?? '');
                if ($range !== '') {
                    $this->assertSame($range, FontPairs::sanitizeUnicodeRange($range), "[{$id}] bad unicode-range");
                }

                // Two files under one family/weight/style with no unicode-range:
                // the second @font-face simply wins and the first never loads.
                $key = $family.'|'.$weight.'|'.$style;
                if (isset($seen[$key])) {
                    $this->assertNotSame(
                        '',
                        $range,
                        "[{$id}] declares [{$key}] twice without a unicode-range — one of the two files is dead weight."
                    );
                }
                $seen[$key] = true;
            }
        }
    }

    /* ─────────────────────────── the schema field ───────────────────────── */

    public function test_the_shared_fragment_offers_exactly_the_registry_ids(): void
    {
        $fragment = SchemaV2::fragment('tokens');
        $field = null;
        foreach ($fragment['fields'] as $f) {
            if (($f['id'] ?? null) === 'fonts.pair') {
                $field = $f;
            }
        }

        $this->assertNotNull($field, 'the tokens fragment must declare fonts.pair');
        // `select` is the only type ThemeCustomizerService::validate() enforces
        // options for — anything else would be an unvalidated hole.
        $this->assertSame('select', $field['type']);
        $this->assertSame(FontPairs::optionValues(), $field['options'], 'fragment options drifted from the registry');
        $this->assertSame(
            FontPairs::noneValue(),
            $field['default'],
            'the default must be the inert "no pair" sentinel — a real pair would repaint every existing store'
        );

        foreach (FontPairs::ids() as $id) {
            $this->assertArrayHasKey($id, $field['option_labels'] ?? [], "[{$id}] needs a readable option label");
        }
        $this->assertArrayHasKey(
            FontPairs::noneValue(),
            $field['option_labels'] ?? [],
            'the "no pair" option needs a label too'
        );
    }

    /**
     * The sentinel may not be '' and may not be a pair id.
     *
     * The editor renders selects with Alpine's `<option :value="opt">`, and Alpine
     * REMOVES an attribute bound to the empty string — an <option> with no value
     * attribute then reports its TEXT as its value, so picking "Theme default"
     * submitted the literal label and the validator rejected it. That left an
     * owner who had chosen a pair unable to get back to their theme's own type.
     * Caught in Chrome on /theme-customizer; this is the regression guard.
     */
    public function test_the_no_pair_sentinel_is_a_real_non_empty_value(): void
    {
        $none = FontPairs::noneValue();

        $this->assertNotSame('', $none, 'an empty option value is dropped by the editor');
        $this->assertNotContains($none, FontPairs::ids(), 'the sentinel may not also name a pair');
        $this->assertFalse(FontPairs::has($none));
        $this->assertSame([], FontPairs::stacks($none));
        $this->assertSame('', FontPairs::fontFaceCss($none));
        $this->assertSame($none, FontPairs::optionValues()[0], 'the sentinel is the first option');

        // Every select option must survive a round-trip through the validator —
        // "reset to the theme default" is exactly the value most likely to be
        // rejected, and it must not be.
        $schema = ThemeCustomizerService::schemaFor('crave');
        foreach (FontPairs::optionValues() as $option) {
            $this->assertSame([], ThemeCustomizerService::validate($schema, ['fonts.pair' => $option]), "[{$option}] must validate");
        }
    }

    public function test_every_first_party_theme_offers_the_control_with_the_inert_default(): void
    {
        foreach (self::TEMPLATES as $template) {
            $fields = ThemeCustomizerService::fields(ThemeCustomizerService::schemaFor($template));
            $this->assertArrayHasKey('fonts.pair', $fields, "[{$template}] must declare fonts.pair");
            $this->assertSame(
                FontPairs::noneValue(),
                $fields['fonts.pair']['default'],
                "[{$template}] must default to no pair"
            );
        }
    }

    public function test_the_validator_accepts_a_registry_id_and_refuses_anything_else(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');

        $this->assertSame([], ThemeCustomizerService::validate($schema, ['fonts.pair' => 'inter']));
        $this->assertSame([], ThemeCustomizerService::validate($schema, ['fonts.pair' => FontPairs::noneValue()]));
        $this->assertArrayHasKey('fonts.pair', ThemeCustomizerService::validate($schema, ['fonts.pair' => 'comic-sans']));
        // The label, not the value — what the Alpine bug used to submit.
        $this->assertArrayHasKey('fonts.pair', ThemeCustomizerService::validate($schema, ['fonts.pair' => 'Theme default']));
    }

    /* ──────────────────────────── the emitted vars ──────────────────────── */

    public function test_selecting_a_pair_emits_both_font_vars(): void
    {
        $vars = Tokens::forTemplate('crave', ['fonts.pair' => 'fraunces-jakarta']);

        $this->assertArrayHasKey('--tf-heading', $vars);
        $this->assertArrayHasKey('--tf-body', $vars);
        $this->assertStringContainsString('Fraunces', $vars['--tf-heading']);
        $this->assertStringContainsString('Plus Jakarta Sans', $vars['--tf-body']);
        $this->assertStringContainsString(FontPairs::arabicFamily(), $vars['--tf-heading']);

        // …and they survive serialization (the css() charset has to allow quotes
        // and hyphens for font stacks without letting a declaration close early).
        $css = Tokens::css($vars);
        $this->assertStringContainsString('--tf-heading:', $css);
        $this->assertStringContainsString('--tf-body:', $css);
    }

    public function test_no_pair_emits_no_font_var(): void
    {
        $cases = [[], ['fonts.pair' => FontPairs::noneValue()], ['fonts.pair' => ''],
            ['fonts.pair' => null], ['fonts.pair' => 'nope']];
        foreach ($cases as $values) {
            $vars = Tokens::forTemplate('crave', $values);
            $this->assertArrayNotHasKey('--tf-heading', $vars);
            $this->assertArrayNotHasKey('--tf-body', $vars);
        }
    }

    public function test_css_still_refuses_a_value_that_could_close_the_declaration(): void
    {
        $this->assertSame('', Tokens::css(['--tf-body' => 'Inter; } body { display:none']));
        $this->assertSame('', Tokens::css(['--tf-body' => 'url(https://evil.test/x.woff2)']));
    }

    /* ─────────────────────────── the @font-face partial ─────────────────── */

    public function test_the_partial_emits_only_the_selected_pairs_faces(): void
    {
        $store = $this->storeOn('crave', ['fonts.pair' => 'fraunces-jakarta']);

        $html = view('store_templates._partials.theme-fonts', ['store' => $store])->render();

        $this->assertStringContainsString('<style', $html);
        $this->assertStringContainsString('@font-face', $html);
        foreach (FontPairs::files('fraunces-jakarta') as $file) {
            $this->assertStringContainsString(asset($file), $html, "the [{$file}] face must be emitted");
        }
        $this->assertStringContainsString("font-family:'Fraunces'", $html);
        $this->assertStringContainsString("font-family:'".FontPairs::arabicFamily()."'", $html);
        $this->assertStringContainsString('font-display:swap', $html);

        // Not the whole registry — six pairs is well over a megabyte of woff2.
        $this->assertStringNotContainsString('syne-normal-latin.woff2', $html);
        $this->assertStringNotContainsString('geist-mono-var.woff2', $html);
        $this->assertSame(count(FontPairs::faces('fraunces-jakarta')), substr_count($html, '@font-face'));
    }

    /** The vars and the faces are two partials; a store needs both to actually change face. */
    public function test_the_token_partial_emits_the_font_vars_for_the_selected_pair(): void
    {
        $store = $this->storeOn('crave', ['fonts.pair' => 'geist-inter']);

        $html = view('store_templates._partials.theme-tokens', ['store' => $store])->render();

        $this->assertStringContainsString('--tf-heading:', $html);
        $this->assertStringContainsString('Geist Mono', $html);
        $this->assertStringContainsString('--tf-body:', $html);

        $plain = view('store_templates._partials.theme-tokens', ['store' => $this->storeOn('crave', null)])->render();
        $this->assertStringNotContainsString('--tf-', $plain, 'no pair selected must emit no font var');
    }

    public function test_the_partial_never_points_at_a_third_party_host(): void
    {
        $store = $this->storeOn('velora', ['fonts.pair' => 'syne-instrument']);

        $html = view('store_templates._partials.theme-fonts', ['store' => $store])->render();

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        $this->assertStringContainsString(asset('assets/fonts/syne-latin.woff2'), $html);
    }

    /**
     * The parity contract: a store that never opened the control gets nothing —
     * no <style>, no @font-face, no bytes — so its rendering is untouched.
     */
    public function test_no_pair_selected_renders_absolutely_nothing(): void
    {
        $cases = [null, [], ['colors.primary' => '#ff0000'],
            ['fonts.pair' => FontPairs::noneValue()], ['fonts.pair' => '']];
        foreach ($cases as $settings) {
            $store = $this->storeOn('crave', $settings);
            $html = trim(view('store_templates._partials.theme-fonts', ['store' => $store])->render());

            $this->assertSame('', $html, 'a store with no font pair must render no @font-face at all');
        }
    }

    public function test_the_partial_is_silent_without_a_post_or_on_a_template_without_a_schema(): void
    {
        $this->assertSame('', trim(view('store_templates._partials.theme-fonts', [])->render()));
        $this->assertSame('', trim(view('store_templates._partials.theme-fonts', ['store' => null])->render()));

        $store = $this->storeOn('nebula', ['fonts.pair' => 'inter']);
        $this->assertSame('', trim(view('store_templates._partials.theme-fonts', ['store' => $store])->render()));
    }

    /**
     * The shared storefront layout no longer asks Google for a font. (The other
     * `fonts.googleapis` links in this repo — the per-theme blades and theme CSS
     * — belong to WI-T4/T5 and are deliberately out of scope here.)
     */
    public function test_the_shared_layout_no_longer_fetches_the_body_font_from_google(): void
    {
        $layout = (string) file_get_contents(resource_path('views/store_templates/layout.blade.php'));

        $this->assertDoesNotMatchRegularExpression(
            '#<link[^>]*fonts\.googleapis\.com[^>]*fonts\.body#s',
            $layout,
            'the CDN stylesheet driven by fonts.body must stay removed'
        );
        $this->assertStringNotContainsString(
            "urlencode(\$__cv['fonts.body'])",
            $layout,
            'nothing may build a remote font URL out of fonts.body'
        );
        // …but the legacy family name still resolves into --menu-font.
        $this->assertStringContainsString("\$__cv['fonts.body']", $layout);
        $this->assertStringContainsString('--menu-font', $layout);
    }

    public function test_both_storefront_layouts_include_the_font_partial(): void
    {
        foreach (['layout.blade.php', 'pageview/layout.blade.php'] as $file) {
            $source = (string) file_get_contents(resource_path('views/store_templates/'.$file));
            $this->assertStringContainsString(
                "@include('store_templates._partials.theme-fonts')",
                $source,
                "[{$file}] must include the @font-face partial next to the token partial"
            );
            $this->assertStringContainsString("@include('store_templates._partials.theme-tokens')", $source);
        }
    }

    private function storeOn(string $template, ?array $settings): Store
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'title' => 'Font Demo', 'slug' => 'fonts-'.$template.'-'.uniqid(),
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd', 'main_image' => 'x.png',
            'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        if ($settings !== null) {
            ThemeSetting::create(['store_id' => $store->id, 'template' => $template, 'settings' => $settings]);
        }

        return $store;
    }
}
