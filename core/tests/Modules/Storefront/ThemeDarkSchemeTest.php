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
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Theme\ContrastGuard;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Juwri\Modules\Storefront\Services\Theme\SchemaV2;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use ReflectionClass;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-01: dark scheme tokens (scheme.mode + colors_dark.*), the `initial` override-leak
 * reset, ContrastGuard on both blocks + the derived --x-* checks, and the owner save
 * refusing a failing colour. Themes that do not declare scheme.mode are unaffected.
 */
class ThemeDarkSchemeTest extends TestCase
{
    use RefreshDatabase;

    /** table's own light + dark defaults (assets/store_templates/table/theme.json). */
    private const TABLE_DARK = [
        'colors_dark.primary' => '#F07A52', 'colors_dark.background' => '#141210', 'colors_dark.surface' => '#1E1B18',
        'colors_dark.text' => '#F6F1EA', 'colors_dark.accent' => '#F2B65A',
    ];

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

    /* ───────────────────────────── field ids + opt-in ───────────────────────── */

    public function test_the_dark_scheme_ids_are_in_field_ids_and_every_kit_theme_declares_them(): void
    {
        $this->assertSame(
            ['scheme.mode', 'colors_dark.primary', 'colors_dark.background', 'colors_dark.surface', 'colors_dark.text', 'colors_dark.accent'],
            Tokens::schemeFieldIds()
        );
        foreach (Tokens::schemeFieldIds() as $id) {
            $this->assertContains($id, Tokens::fieldIds());
        }
        foreach (KitThemes::SLUGS as $slug) {
            $fields = ThemeCustomizerService::fields(ThemeCustomizerService::schemaFor($slug));
            foreach (Tokens::schemeFieldIds() as $id) {
                $this->assertArrayHasKey($id, $fields, "$slug declares $id");
            }
            $this->assertSame(['light', 'dark', 'auto'], $fields['scheme.mode']['options']);
            $this->assertSame('auto', Tokens::schemeMode($slug), "$slug defaults to auto");
        }
    }

    public function test_a_theme_without_scheme_mode_is_unchanged_whatever_is_stored(): void
    {
        $this->assertNull(Tokens::schemeMode('crave'));
        $stored = [
            'colors.primary' => '#ff8800', 'colors.background' => '#101010', 'layout.radius' => 'pill',
            // stale dark keys on a theme that does not declare them are ignored
            'scheme.mode' => 'dark', 'colors_dark.primary' => '#00ff00',
        ];
        $expected = Tokens::build(Tokens::resolve('crave', ['colors.primary' => '#ff8800', 'colors.background' => '#101010', 'layout.radius' => 'pill'], false));

        $this->assertSame($expected, Tokens::forTemplate('crave', $stored));
        $this->assertSame([], Tokens::darkBlock('crave', $stored));
        $this->assertSame(':root{'.Tokens::css($expected).'}', Tokens::stylesheet('crave', $stored));
        $this->assertSame([], ContrastGuard::failures('crave', $stored), 'the legacy guard stays warn-only');
    }

    /* ───────────────────────────── emission: light / dark / auto ────────────── */

    public function test_light_mode_emits_one_block_from_the_light_roles(): void
    {
        $values = ['scheme.mode' => 'light', 'colors.primary' => '#b4331a'] + self::TABLE_DARK;

        $vars = Tokens::forTemplate('table', $values);
        $this->assertSame('#b4331a', $vars['--tc-primary']);
        $this->assertSame([], Tokens::darkBlock('table', $values));
        $this->assertStringNotContainsString('prefers-color-scheme', Tokens::stylesheet('table', $values));
    }

    public function test_dark_mode_emits_the_dark_roles_under_the_normal_names_and_never_the_light_ones(): void
    {
        $values = ['scheme.mode' => 'dark', 'colors.primary' => '#b4331a', 'colors.text' => '#1f1a17'] + self::TABLE_DARK;

        $vars = Tokens::forTemplate('table', $values);
        $this->assertSame('#f07a52', $vars['--tc-primary']);
        $this->assertSame('#f6f1ea', $vars['--tc-text']);
        $this->assertSame('#141210', $vars['--tc-background']);
        // derived tokens are computed from the DARK role
        $this->assertSame(Tokens::mix('#f07a52', '#ffffff', 0.24), $vars['--tc-primary-300']);
        $this->assertSame(Tokens::readableInk('#f07a52'), $vars['--tc-on-primary']);
        $this->assertSame(Tokens::mix('#f6f1ea', '#141210', 0.45), $vars['--tc-text-muted']);
        $this->assertSame([], Tokens::darkBlock('table', $values), 'dark mode is one block');
        $this->assertStringNotContainsString('prefers-color-scheme', Tokens::stylesheet('table', $values));
        $this->assertStringNotContainsString('#b4331a', Tokens::stylesheet('table', $values), 'no light value in dark mode');
    }

    public function test_dark_mode_with_only_light_overrides_emits_no_colour_at_all(): void
    {
        $vars = Tokens::forTemplate('table', ['scheme.mode' => 'dark', 'colors.primary' => '#b4331a']);

        $this->assertSame([], $vars);
    }

    public function test_auto_mode_emits_the_light_block_plus_the_dark_block_in_a_media_query(): void
    {
        $values = ['scheme.mode' => 'auto', 'colors.primary' => '#b4331a'] + self::TABLE_DARK;

        $this->assertSame('#b4331a', Tokens::forTemplate('table', $values)['--tc-primary']);
        $dark = Tokens::darkBlock('table', $values);
        $this->assertSame('#f07a52', $dark['--tc-primary']);
        $this->assertSame('#141210', $dark['--tc-background']);

        $css = Tokens::stylesheet('table', $values);
        $this->assertStringStartsWith(':root{', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark){:root{', $css);
        $this->assertSame(1, substr_count($css, 'prefers-color-scheme: dark'));
        $this->assertStringContainsString('--tc-primary:#f07a52;', explode('@media', $css, 2)[1]);
        $this->assertStringContainsString('--tc-primary:#b4331a;', explode('@media', $css, 2)[0]);
    }

    public function test_no_owner_colours_means_no_output_even_in_auto(): void
    {
        $this->assertSame([], Tokens::forTemplate('table', ['scheme.mode' => 'auto']));
        $this->assertSame([], Tokens::darkBlock('table', ['scheme.mode' => 'auto']));
        $this->assertSame('', Tokens::stylesheet('table', ['scheme.mode' => 'auto']));
    }

    public function test_a_light_only_override_never_leaks_into_the_dark_block(): void
    {
        // The owner changed colors.primary (and nothing dark): the dark block must reset
        // every --tc-* the light block writes for it, so the theme's dark fallback applies.
        $light = Tokens::forTemplate('table', ['scheme.mode' => 'auto', 'colors.primary' => '#00aa55']);
        $dark = Tokens::darkBlock('table', ['scheme.mode' => 'auto', 'colors.primary' => '#00aa55']);

        $this->assertArrayHasKey('--tc-primary', $light);
        $this->assertArrayHasKey('--tc-on-primary', $light);
        foreach (['--tc-primary', '--tc-primary-300', '--tc-primary-400', '--tc-primary-600', '--tc-primary-700', '--tc-primary-rgb', '--tc-on-primary'] as $name) {
            $this->assertSame('initial', $dark[$name], "$name must be reset in the dark block");
        }
        foreach (array_keys($light) as $name) {
            if (str_starts_with($name, '--tc-')) {
                $this->assertArrayHasKey($name, $dark, "$name present in the dark block");
            }
        }
        $this->assertStringNotContainsString('#00aa55', implode(';', $dark));
        // a non-colour var is never reset
        $withRadius = Tokens::darkBlock('table', ['scheme.mode' => 'auto', 'colors.primary' => '#00aa55', 'layout.radius' => 'pill']);
        $this->assertArrayNotHasKey('--tr-card', $withRadius);
    }

    public function test_text_and_background_light_overrides_reset_the_derived_muted_in_dark(): void
    {
        $values = ['scheme.mode' => 'auto', 'colors.text' => '#202020', 'colors.background' => '#fafafa'];
        $dark = Tokens::darkBlock('table', $values);

        $this->assertSame('initial', $dark['--tc-text-muted']);
        $this->assertSame('initial', $dark['--tc-text']);
        $this->assertSame('initial', $dark['--tc-background-rgb']);

        // with both dark twins set, muted is computed from the dark pair instead
        $both = Tokens::darkBlock('table', $values + ['colors_dark.text' => '#eeeeee', 'colors_dark.background' => '#101010']);
        $this->assertSame(Tokens::mix('#eeeeee', '#101010', 0.45), $both['--tc-text-muted']);
        $this->assertSame('#eeeeee', $both['--tc-text']);
        $this->assertArrayNotHasKey('--tc-primary', $both);
    }

    public function test_the_initial_keyword_survives_the_css_filter(): void
    {
        $css = Tokens::css(Tokens::darkBlock('table', ['scheme.mode' => 'auto', 'colors.accent' => '#ff00aa']));

        $this->assertStringContainsString('--tc-accent:initial;', $css);
        $this->assertStringContainsString('--tc-on-accent:initial;', $css);
    }

    /** Core's emission reproduces the kit generator's own preset CSS for every kit theme x preset x scheme. */
    public function test_emission_matches_the_kit_generators_preset_css(): void
    {
        $python = trim((string) shell_exec('command -v python3'));
        if ($python === '') {
            $this->markTestSkipped('python3 is not installed on this machine.');
        }
        $src = dirname(base_path()).'/tools/themes';
        $tmp = sys_get_temp_dir().'/kit-dark-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        try {
            shell_exec('cp -R '.escapeshellarg("$src/tools").' '.escapeshellarg("$src/tokens").' '.escapeshellarg($tmp).'/');
            shell_exec('cd '.escapeshellarg($tmp).' && '.escapeshellarg($python).' tools/build_theme_css.py >/dev/null 2>&1');

            $checked = 0;
            foreach (KitThemes::SLUGS as $slug) {
                $schema = ThemeCustomizerService::schemaFor($slug);
                foreach ($schema['presets'] as $preset) {
                    $file = "$tmp/kit/$slug/css/preset-{$preset['id']}.css";
                    $this->assertFileExists($file);
                    [$light, $dark] = $this->parsePresetCss((string) file_get_contents($file));
                    $this->assertCount(7, $light, "$slug/{$preset['id']} light roles + inks");
                    $values = ThemeCustomizerService::presetValues($schema, $preset['id']);

                    $core = Tokens::forTemplate($slug, array_merge($values, ['scheme.mode' => 'light']));
                    foreach ($light as $name => $value) {
                        $this->assertSame($value, $core[$name] ?? null, "$slug/{$preset['id']} light $name");
                    }
                    $core = Tokens::forTemplate($slug, array_merge($values, ['scheme.mode' => 'dark']));
                    foreach ($dark as $name => $value) {
                        $this->assertSame($value, $core[$name] ?? null, "$slug/{$preset['id']} dark $name");
                    }
                    $auto = Tokens::darkBlock($slug, array_merge($values, ['scheme.mode' => 'auto']));
                    foreach ($dark as $name => $value) {
                        $this->assertSame($value, $auto[$name] ?? null, "$slug/{$preset['id']} auto/dark $name");
                    }
                    $checked++;
                }
            }
            $this->assertGreaterThanOrEqual(19, $checked);
        } finally {
            shell_exec('rm -rf '.escapeshellarg($tmp));
        }
    }

    /** @return array{0:array<string,string>,1:array<string,string>} [light vars, dark vars] */
    private function parsePresetCss(string $css): array
    {
        $blocks = [];
        preg_match_all('/(:root(?:\[data-scheme="(\w+)"\])?)\s*\{([^}]*)\}/', $css, $m, PREG_SET_ORDER);
        foreach ($m as $b) {
            $scheme = $b[2] ?? '';
            if ($scheme === 'auto') {
                continue;
            }
            preg_match_all('/(--tc-[a-z-]+):\s*(#[0-9a-f]{6});/', $b[3], $v, PREG_SET_ORDER);
            $blocks[$scheme === '' ? 'light' : $scheme] = array_column($v, 2, 1);
        }

        return [$blocks['light'], $blocks['dark']];
    }

    /* ─────────────────────────── the rendered partial ───────────────────────── */

    public function test_the_partial_renders_the_dark_media_block_for_an_auto_store(): void
    {
        $store = $this->storeOn('table', ['scheme.mode' => 'auto', 'colors.primary' => '#00aa55'] + self::TABLE_DARK);

        $html = view('store_templates._partials.theme-tokens', ['store' => $store])->render();

        $this->assertStringContainsString('--tc-primary:#00aa55;', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark){:root{', $html);
        $this->assertStringContainsString('--tc-primary:#f07a52;', $html);
        $this->assertSame(1, substr_count($html, '<style'));
    }

    public function test_the_partial_of_a_dark_store_has_no_media_block(): void
    {
        $store = $this->storeOn('table', ['scheme.mode' => 'dark', 'colors.primary' => '#00aa55'] + self::TABLE_DARK);

        $html = view('store_templates._partials.theme-tokens', ['store' => $store])->render();

        $this->assertStringContainsString('--tc-primary:#f07a52;', $html);
        $this->assertStringNotContainsString('#00aa55', $html);
        $this->assertStringNotContainsString('prefers-color-scheme', $html);
    }

    public function test_an_auto_store_with_only_a_light_override_renders_initial_in_the_media_block(): void
    {
        $store = $this->storeOn('table', ['scheme.mode' => 'auto', 'colors.primary' => '#00aa55']);

        $html = view('store_templates._partials.theme-tokens', ['store' => $store])->render();

        $this->assertStringContainsString('@media (prefers-color-scheme: dark){:root{', $html);
        $this->assertStringContainsString('--tc-primary:initial;', $html);
    }

    public function test_a_legacy_store_renders_exactly_the_old_block(): void
    {
        $store = $this->storeOn('crave', ['colors.primary' => '#ff8800']);

        $html = view('store_templates._partials.theme-tokens', ['store' => $store])->render();

        $this->assertStringContainsString('--tc-primary:#ff8800;', $html);
        $this->assertStringNotContainsString('prefers-color-scheme', $html);
    }

    /* ───────────────────────────── ContrastGuard on both blocks ─────────────── */

    public function test_the_kit_defaults_pass_in_both_blocks(): void
    {
        foreach (KitThemes::SLUGS as $slug) {
            $this->assertSame([], ContrastGuard::failures($slug, []), "$slug defaults");
        }
    }

    public function test_a_failing_dark_text_is_reported_in_the_dark_scheme_with_a_passing_fix(): void
    {
        $failures = ContrastGuard::failures('table', ['scheme.mode' => 'auto', 'colors_dark.text' => '#2a2623']);

        $this->assertNotSame([], $failures);
        $body = collect($failures)->firstWhere('key', 'dark:text/background');
        $this->assertNotNull($body);
        $this->assertSame('dark', $body['scheme']);
        $this->assertSame('colors_dark.text', $body['field']);
        $this->assertSame('Body text on background', $body['label']);
        $this->assertLessThan(4.5, $body['ratio']);
        $this->assertNotNull($body['suggestion']);
        $this->assertGreaterThanOrEqual(4.5, Tokens::contrastRatio($body['suggestion'], '#141210'));
        $this->assertSame([], array_filter($failures, fn ($f) => $f['scheme'] === 'light'), 'the light block is untouched');
    }

    public function test_a_light_text_that_passes_alone_but_breaks_the_derived_muted_is_caught(): void
    {
        // #767676 on white is 4.54:1 (passes AA), but --x-muted (72% of it into the background)
        // drops under 4.5:1. The plain pair check would miss this.
        $values = ['colors.text' => '#767676', 'colors.background' => '#ffffff', 'colors.surface' => '#ffffff'];
        $failures = ContrastGuard::failures('table', $values);
        $keys = array_column($failures, 'key');

        $this->assertNotContains('light:text/background', $keys);
        $this->assertContains('light:muted/background', $keys);
        $this->assertContains('light:muted/surface', $keys);
        $muted = collect($failures)->firstWhere('key', 'light:muted/background');
        $this->assertSame('colors.text', $muted['field']);
        $this->assertSame('light', $muted['scheme']);
        $this->assertNotNull($muted['suggestion']);

        // and the legacy four-pair warning on the same values says nothing
        $this->assertSame([], array_filter(ContrastGuard::warnings('table', $values), fn ($w) => $w['pair'] === 'Body text on background'));
    }

    public function test_a_failing_ui_border_uses_the_three_to_one_threshold(): void
    {
        // text close to the surface: the derived border-strong (non-text, 3:1) fails first or with it
        $failures = ContrastGuard::failures('table', ['colors.text' => '#9a9a9a', 'colors.background' => '#ffffff', 'colors.surface' => '#ffffff']);
        $border = collect($failures)->firstWhere('key', 'light:border_strong/surface');

        $this->assertNotNull($border);
        $this->assertSame(3.0, $border['need']);
    }

    public function test_refusals_only_charge_a_save_with_the_pairs_it_introduces(): void
    {
        $bad = ['colors.text' => '#767676', 'colors.background' => '#ffffff', 'colors.surface' => '#ffffff'];

        $this->assertNotSame([], ContrastGuard::refusals('table', [], $bad));
        // already failing before: not charged again
        $this->assertSame([], ContrastGuard::refusals('table', $bad, $bad + ['colors.accent' => '#e8a33d']));
        // a different, harmless change on top of a failing state is fine
        $this->assertSame([], ContrastGuard::refusals('crave', [], $bad), 'legacy themes are never refused');
    }

    /* ───────────────────────────── the owner save refuses ───────────────────── */

    public function test_saving_a_light_colour_that_fails_is_refused_and_nothing_is_saved(): void
    {
        [, $store] = $this->owner('table');

        try {
            ThemeCustomizerService::saveDraft($store, 'table', ['colors.text' => '#767676', 'colors.background' => '#ffffff', 'colors.surface' => '#ffffff']);
            $this->fail('expected a refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('colors.text', $e->errors());
            $message = $e->errors()['colors.text'][0];
            $this->assertStringContainsString('2.75:1', $message);
            $this->assertStringContainsString('4.5:1', $message);
            $this->assertStringContainsString(
                (string) ___('Secondary text on background'),
                $message,
                'the message names the failing pair'
            );
            // the light-scheme wording (translated in whatever locale the test runs in), not the dark one
            $this->assertSame(
                (string) ___(':pair is too hard to read in the light scheme (contrast :ratio, at least :need is needed). Nothing was saved. Pick a stronger colour.', [
                    'pair' => ___('Secondary text on background'), 'ratio' => '2.75:1', 'need' => '4.5:1',
                ]),
                $message
            );
        }
        $this->assertSame(0, ThemeSetting::where('store_id', $store->id)->count(), 'nothing saved');
    }

    public function test_saving_a_dark_colour_that_fails_is_refused_and_nothing_is_saved(): void
    {
        [, $store] = $this->owner('table');
        ThemeCustomizerService::saveDraft($store, 'table', ['colors.accent' => '#e8a33d']);
        $before = ThemeSetting::where('store_id', $store->id)->first()->draft;

        try {
            ThemeCustomizerService::saveDraft($store, 'table', ['colors_dark.text' => '#2a2623']);
            $this->fail('expected a refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('colors_dark.text', $e->errors());
            $this->assertSame(
                (string) ___(':pair is too hard to read in the dark scheme (contrast :ratio, at least :need is needed). Nothing was saved. Pick a stronger colour.', [
                    'pair' => ___('Body text on background'), 'ratio' => '1.25:1', 'need' => '4.5:1',
                ]),
                $e->errors()['colors_dark.text'][0]
            );
        }
        $this->assertSame($before, ThemeSetting::where('store_id', $store->id)->first()->draft, 'the failing change did not land');
    }

    public function test_a_passing_dark_and_light_change_saves(): void
    {
        [, $store] = $this->owner('table');

        $ts = ThemeCustomizerService::saveDraft($store, 'table', ['scheme.mode' => 'dark', 'colors_dark.primary' => '#ff9966', 'colors.primary' => '#a02810']);

        $this->assertSame('dark', $ts->draft['scheme.mode']);
        $this->assertSame('#ff9966', $ts->draft['colors_dark.primary']);
    }

    public function test_the_draft_endpoint_answers_422_with_the_field_and_a_clear_message(): void
    {
        [$owner, $store] = $this->owner('table');

        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['colors_dark.text' => '#2a2623'],
        ])->assertStatus(422)->assertJsonValidationErrors(['colors_dark.text']);

        $this->assertSame(0, ThemeSetting::where('store_id', $store->id)->count());
    }

    public function test_publish_is_refused_for_a_failing_draft_that_bypassed_the_save_check(): void
    {
        [$owner, $store] = $this->owner('table');
        // an old draft written before this check existed
        ThemeSetting::create([
            'store_id' => $store->id, 'template' => 'table', 'settings' => ThemeCustomizerService::defaults(ThemeCustomizerService::schemaFor('table')),
            'draft' => ['colors_dark.text' => '#2a2623'],
        ]);

        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertStatus(422);

        $this->assertNotNull(ThemeSetting::where('store_id', $store->id)->first()->draft, 'the draft is kept');
    }

    public function test_legacy_themes_keep_warn_only_saves_and_publishes(): void
    {
        [$owner, $store] = $this->owner('crave');

        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['colors.text' => '#2d2d2d', 'colors.background' => '#242424'],
        ])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();
    }

    public function test_the_contrast_endpoint_reports_both_schemes_for_a_dark_capable_theme(): void
    {
        [$owner, $store] = $this->owner('table');

        $response = $this->actingAs($owner)->postJson(route('theme.customizer.contrast', $store->id), [
            'values' => ['colors_dark.text' => '#2a2623', 'colors.text' => '#767676', 'colors.background' => '#ffffff', 'colors.surface' => '#ffffff'],
        ])->assertOk();

        $schemes = collect($response->json('contrast'))->pluck('scheme')->unique()->sort()->values()->all();
        $this->assertSame(['dark', 'light'], $schemes);
    }

    public function test_the_tokens_endpoint_returns_the_dark_block_for_the_preview(): void
    {
        [$owner, $store] = $this->owner('table');

        $response = $this->actingAs($owner)->postJson(route('theme.customizer.tokens', $store->id), [
            'values' => ['scheme.mode' => 'auto', 'colors.primary' => '#a02810', 'colors_dark.primary' => '#ff9966'],
        ])->assertOk();

        $this->assertSame('#a02810', $response->json('vars')['--tc-primary']);
        $this->assertSame('#ff9966', $response->json('dark_vars')['--tc-primary']);
    }

    public function test_every_guard_label_and_the_refusal_message_is_translated_in_all_eleven_locales(): void
    {
        $labels = array_values(array_unique(array_column((new ReflectionClass(ContrastGuard::class))->getConstant('CHECKS'), 3)));
        $messages = [
            ':pair is too hard to read in the light scheme (contrast :ratio, at least :need is needed). Nothing was saved. Pick a stronger colour.',
            ':pair is too hard to read in the dark scheme (contrast :ratio, at least :need is needed). Nothing was saved. Pick a stronger colour.',
        ];
        $this->assertCount(19, $labels);
        foreach (['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            $lang = include base_path("lang/$locale/lang.php");
            foreach (array_merge($labels, $messages) as $text) {
                $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($text)), '_');
                $this->assertArrayHasKey($key, $lang, "$locale lacks [$text]");
                if ($locale !== 'en') {
                    $this->assertNotSame($text, $lang[$key], "$locale.$key is an English copy");
                }
            }
            foreach ($messages as $text) {
                $value = $lang[trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($text)), '_')];
                foreach ([':pair', ':ratio', ':need'] as $placeholder) {
                    $this->assertStringContainsString($placeholder, $value, "$locale lost $placeholder");
                }
            }
        }
    }

    /* ───────────────────────────────── helpers ──────────────────────────────── */

    /** @return array{0:User,1:Store} */
    private function owner(string $template): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }

    private function storeOn(string $template, array $settings): Store
    {
        [, $store] = $this->owner($template);
        ThemeSetting::create(['store_id' => $store->id, 'template' => $template, 'settings' => $settings]);

        return $store;
    }
}
