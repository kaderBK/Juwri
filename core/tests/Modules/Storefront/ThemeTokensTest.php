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
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Juwri\Modules\Storefront\Services\Theme\SchemaV2;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use Tests\TestCase;

/**
 * Customizer v2 — WI-T1 (colour roles + derived shades) and WI-T3 (radius +
 * density scales).
 *
 * Three things are pinned here, in order of how expensive they'd be to get wrong:
 *  1. the mix maths, against hand-computed values (RESEARCH.md §2);
 *  2. that the shared partial actually renders a store's roles;
 *  3. that a store which customized NOTHING gets tokens whose values are exactly
 *     the literals already in its theme's stylesheet — the pixel-parity claim,
 *     guarded at the CSS-variable level.
 */
class ThemeTokensTest extends TestCase
{
    use RefreshDatabase;

    /** The first-party templates (pageview included — it has a schema and a layout of its own). */
    private const TEMPLATES = [...KeptTemplates::MODERN, 'pageview'];

    protected function setUp(): void
    {
        parent::setUp();
        SchemaV2::flushFragments();
        ThemeCustomizerService::flushSchemaMemo();
    }

    /* ─────────────────────────────── the maths ─────────────────────────────── */

    public function test_mix_is_the_per_channel_srgb_formula(): void
    {
        // mix($a,$b,$t) = round($a*(1-$t)+$b*$t) per channel.
        $this->assertSame(128, Tokens::mixChannel(0, 255, 0.5));   // round(127.5) = 128
        $this->assertSame(0, Tokens::mixChannel(0, 255, 0.0));
        $this->assertSame(255, Tokens::mixChannel(0, 255, 1.0));
        $this->assertSame('#808080', Tokens::mix('#000000', '#ffffff', 0.5));
    }

    public function test_tints_and_shades_match_hand_computed_values(): void
    {
        // #3fd463 = (63, 212, 99) — crave's accent.
        //   tint .12 : 63*.88+255*.12 = 86.04 → 86 (56) | 212*.88+30.6 = 217.16 → 217 (d9) | 99*.88+30.6 = 117.72 → 118 (76)
        //   tint .24 : 47.88+61.2 = 109.08 → 109 (6d)   | 161.12+61.2 = 222.32 → 222 (de)  | 75.24+61.2 = 136.44 → 136 (88)
        //   shade .12: 55.44 → 55 (37)                  | 186.56 → 187 (bb)                | 87.12 → 87 (57)
        //   shade .24: 47.88 → 48 (30)                  | 161.12 → 161 (a1)                | 75.24 → 75 (4b)
        $this->assertSame('#56d976', Tokens::tint('#3fd463', 0.12));
        $this->assertSame('#6dde88', Tokens::tint('#3fd463', 0.24));
        $this->assertSame('#37bb57', Tokens::shade('#3fd463', 0.12));
        $this->assertSame('#30a14b', Tokens::shade('#3fd463', 0.24));
    }

    public function test_hex_is_normalized_and_garbage_is_refused_rather_than_guessed(): void
    {
        $this->assertSame('#aabbcc', Tokens::normalizeHex('#abc'));
        $this->assertSame('#ff8800', Tokens::normalizeHex('#FF8800'));
        // A colour that cannot be parsed must vanish, so the theme's own literal
        // stands. Guessing black is how the legacy sscanf ramp broke themes.
        $this->assertNull(Tokens::normalizeHex('rgba(17,1,,1)'));
        $this->assertNull(Tokens::normalizeHex('red'));
        $this->assertNull(Tokens::normalizeHex(''));
        $this->assertNull(Tokens::normalizeHex(null));
    }

    public function test_contrast_ratio_matches_the_wcag_reference_pairs(): void
    {
        $this->assertEqualsWithDelta(21.0, Tokens::contrastRatio('#000000', '#ffffff'), 0.001);
        $this->assertEqualsWithDelta(1.0, Tokens::contrastRatio('#123456', '#123456'), 0.001);
        // White ink on a pale brand colour is the failure the --tc-on-* tokens exist for.
        $this->assertSame('#111111', Tokens::readableInk('#CDF34B'));
        $this->assertSame('#ffffff', Tokens::readableInk('#15171a'));
    }

    /* ─────────────────────────── the emitted token set ─────────────────────── */

    public function test_build_emits_the_documented_ramp_for_every_role(): void
    {
        $vars = Tokens::build([
            'colors.primary' => '#3fd463',
            'colors.background' => '#15171a',
            'colors.surface' => '#22262b',
            'colors.text' => '#f4f6f5',
            'colors.accent' => '#ffb02e',
            'layout.radius' => 'round',
            'layout.density' => 'cozy',
        ]);

        foreach (Tokens::COLOR_ROLES as $role) {
            foreach (['', '-300', '-400', '-600', '-700', '-rgb'] as $suffix) {
                $this->assertArrayHasKey("--tc-{$role}{$suffix}", $vars);
            }
        }
        $this->assertSame('#3fd463', $vars['--tc-primary']);
        $this->assertSame('#56d976', $vars['--tc-primary-400']);
        $this->assertSame('#37bb57', $vars['--tc-primary-600']);
        $this->assertSame('63, 212, 99', $vars['--tc-primary-rgb']);

        // muted = the text role at 55% over the background (RESEARCH §2).
        $this->assertSame(Tokens::mix('#f4f6f5', '#15171a', 0.45), $vars['--tc-text-muted']);
        $this->assertSame('#111111', $vars['--tc-on-primary']);

        $this->assertSame(['18px', '14px', '999px'], [$vars['--tr-card'], $vars['--tr-control'], $vars['--tr-pill']]);
        $this->assertSame(['6px', '12px', '18px', '26px'], [
            $vars['--ts-gap-1'], $vars['--ts-gap-2'], $vars['--ts-gap-3'], $vars['--ts-gap-4'],
        ]);
    }

    public function test_an_unparseable_or_missing_role_emits_no_var_at_all(): void
    {
        $vars = Tokens::build(['colors.primary' => 'not-a-colour', 'layout.radius' => 'wobbly']);

        $this->assertSame([], $vars);
    }

    public function test_css_only_serializes_tokens_this_layer_owns(): void
    {
        $css = Tokens::css([
            '--tc-primary' => '#3fd463',
            '--evil' => 'red',                 // wrong prefix
            '--tc-x' => '}body{display:none',  // not a value this layer builds
        ]);

        $this->assertSame('--tc-primary:#3fd463;', $css);
    }

    /* ──────────────────────── the per-theme parity contract ────────────────── */

    public function test_every_first_party_theme_declares_all_seven_token_fields(): void
    {
        foreach (self::TEMPLATES as $template) {
            $fields = ThemeCustomizerService::fields(ThemeCustomizerService::schemaFor($template));
            // P3-01: the dark-scheme ids are opt-in (kit themes), not part of the legacy seven.
            foreach (array_diff(Tokens::fieldIds(), Tokens::schemeFieldIds()) as $id) {
                $this->assertArrayHasKey($id, $fields, "[{$template}] must declare the token field [{$id}]");
                $this->assertArrayHasKey('default', $fields[$id], "[{$template}] must give [{$id}] a default");
            }
        }
    }

    /**
     * The defaults are what a store with zero customization renders with, so a
     * theme that forgot to override the fragment's placeholder would silently
     * repaint itself. Every theme must state its own literals.
     */
    public function test_no_theme_leaves_a_shared_fragment_colour_default_in_place(): void
    {
        foreach (self::TEMPLATES as $template) {
            $raw = ThemeCustomizerService::rawSchemaFor($template);
            $overrides = [];
            foreach ($raw['settings'] ?? [] as $group) {
                if (($group['include'] ?? null) === 'tokens') {
                    $overrides = $group['fields'] ?? [];
                }
            }
            $this->assertNotSame([], $overrides, "[{$template}] must include the shared 'tokens' fragment");

            foreach (Tokens::COLOR_ROLES as $role) {
                $this->assertArrayHasKey(
                    'default',
                    $overrides['colors.'.$role] ?? [],
                    "[{$template}] must carry its OWN default for colors.{$role} — the fragment's "
                    .'placeholder would repaint the theme for every store that customized nothing.'
                );
            }
        }
    }

    /**
     * The hard requirement, stated correctly: with no theme_settings row, the
     * token layer emits NOTHING. Each theme consumes a token as
     * `var(--tc-primary, <its own default>)`, so with no override the theme's own
     * fallback stands — and for several themes that fallback is not a CSS literal
     * but the store's legacy `$store->color` (via the shared `--theme-color-1`).
     * Emitting a default-valued `--tc-primary` here would silently override that
     * saved brand colour. Overrides-only emission is what makes the T4/T5 refactor
     * `#3fd463` → `var(--tc-primary, #3fd463)` move zero pixels on an untouched store.
     */
    public function test_a_store_with_no_settings_emits_no_tokens(): void
    {
        foreach (self::TEMPLATES as $template) {
            $this->assertSame(
                [],
                Tokens::forTemplate($template, []),
                "[{$template}] must emit no token vars until the owner sets one — otherwise a "
                .'default-valued --tc-primary overrides the store legacy colour some themes render'
            );
        }
    }

    public function test_only_the_roles_the_owner_set_are_emitted(): void
    {
        // Set primary only; the other roles must NOT appear, so the theme's own
        // background/text/etc. fallbacks keep rendering.
        $vars = Tokens::forTemplate('crave', ['colors.primary' => '#3fd463']);

        $this->assertSame('#3fd463', $vars['--tc-primary']);
        $this->assertArrayHasKey('--tc-primary-600', $vars);
        $this->assertArrayNotHasKey('--tc-background', $vars);
        $this->assertArrayNotHasKey('--tc-text', $vars);
    }

    public function test_an_owner_override_wins_over_the_theme_default(): void
    {
        $vars = Tokens::forTemplate('crave', ['colors.primary' => '#ff0000']);

        $this->assertSame('#ff0000', $vars['--tc-primary']);
        // (255,0,0) tinted 12%: red stays 255, the other channels are 0*.88+30.6 = 30.6 → 31 = 0x1f.
        $this->assertSame('#ff1f1f', $vars['--tc-primary-400']);
        // Untouched roles are not emitted at all, so crave's own --bg literal stands.
        $this->assertArrayNotHasKey('--tc-background', $vars);
    }

    /* ───────────────────────────── the shared partial ──────────────────────── */

    public function test_the_partial_renders_a_stores_roles(): void
    {
        $store = $this->storeOn('crave', ['colors.primary' => '#ff8800', 'layout.radius' => 'pill']);

        $html = view('store_templates._partials.theme-tokens', ['store' => $store])->render();

        $this->assertStringContainsString('--tc-primary:#ff8800;', $html);
        $this->assertStringContainsString('--tc-primary-600:#e07800;', $html);
        $this->assertStringContainsString('--tr-control:999px;', $html);
        $this->assertStringContainsString('<style', $html);
        $this->assertStringContainsString(':root{', $html);
    }

    public function test_the_partial_emits_no_style_block_when_nothing_is_stored(): void
    {
        $store = $this->storeOn('velora', null);

        $html = trim(view('store_templates._partials.theme-tokens', ['store' => $store])->render());

        // An untouched store carries no token override, so the partial renders
        // nothing at all and velora's own `var(--tc-primary, #b5d336)` fallback
        // (its lime, straight out of its CSS) stands — pixel-parity.
        $this->assertSame('', $html);
    }

    public function test_the_partial_is_silent_without_a_post(): void
    {
        $this->assertSame('', trim(view('store_templates._partials.theme-tokens', [])->render()));
        $this->assertSame('', trim(view('store_templates._partials.theme-tokens', ['store' => null])->render()));
    }

    public function test_a_store_on_a_template_without_a_schema_gets_no_tokens(): void
    {
        $store = $this->storeOn('nebula', null);

        $this->assertSame('', trim(view('store_templates._partials.theme-tokens', ['store' => $store])->render()));
    }

    private function storeOn(string $template, ?array $settings): Store
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'title' => 'Token Demo', 'slug' => 'tokens-'.$template.'-'.uniqid(),
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
