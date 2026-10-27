<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Services\ThemeCustomizerService;
use Juwri\Modules\Storefront\Services\Theme\SchemaV2;
use Juwri\Modules\Storefront\Services\Theme\ThemeKeys;
use Tests\TestCase;

/**
 * Customizer v2 — WI-C0-1 (schema normalizer), WI-C0-2 (canonical keys),
 * WI-C0-4 (shared fragments).
 *
 * The contract these pin down: whatever version a theme.json declares, every
 * caller sees ONE shape; and every concept has exactly one stored id, with the
 * legacy ids resolving to it for one release.
 */
class ThemeSchemaV2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaV2::flushFragments();
        ThemeKeys::flush();
        // Schemas are memoized per template for the life of the process, so a
        // test that ran earlier could otherwise hand this one a stale copy.
        ThemeCustomizerService::flushSchemaMemo();
    }

    public function test_a_v1_schema_is_normalized_to_the_v2_shape(): void
    {
        $v1 = [
            'version' => 1,
            'settings' => [
                ['group' => 'Colors', 'fields' => [
                    ['id' => 'colors.primary', 'type' => 'color', 'label' => 'Accent', 'default' => '#111111'],
                ]],
            ],
            'sections' => [
                ['id' => 'menu', 'label' => 'Menu grid', 'toggleable' => true, 'default' => true],
            ],
        ];

        $out = SchemaV2::normalize($v1);

        $this->assertSame(2, $out['version']);
        $this->assertSame([], $out['tokens']);
        $this->assertSame([], $out['presets']);
        $this->assertFalse($out['texts']['localized']);
        $this->assertSame('colors.primary', $out['settings'][0]['fields'][0]['id']);
        $this->assertSame('#111111', $out['settings'][0]['fields'][0]['default']);
        // Every section gains the full set of flags, defaulted conservatively.
        $this->assertSame(
            ['id' => 'menu', 'label' => 'Menu grid', 'default' => true, 'toggleable' => true, 'orderable' => false],
            $out['sections'][0]
        );
    }

    public function test_a_v2_schema_passes_through_with_its_tokens_presets_and_conditions(): void
    {
        $v2 = [
            'version' => 2,
            'tokens' => ['colors' => ['primary' => '#0f0']],
            'texts' => ['localized' => true],
            'presets' => [['id' => 'midnight', 'label' => 'Midnight', 'values' => ['colors.primary' => '#000']]],
            'settings' => [
                ['group' => 'Colors', 'icon' => 'palette', 'fields' => [
                    ['id' => 'colors.primary', 'type' => 'color', 'default' => '#0f0'],
                    ['id' => 'colors.tint', 'type' => 'color', 'visible_if' => ['colors.primary' => '#0f0']],
                ]],
            ],
            'sections' => [['id' => 'menu', 'label' => 'Menu', 'orderable' => true]],
        ];

        $out = SchemaV2::normalize($v2);

        $this->assertSame(['colors' => ['primary' => '#0f0']], $out['tokens']);
        $this->assertTrue($out['texts']['localized']);
        $this->assertSame('midnight', $out['presets'][0]['id']);
        $this->assertSame('palette', $out['settings'][0]['icon']);
        $this->assertSame(['colors.primary' => '#0f0'], $out['settings'][0]['fields'][1]['visible_if']);
        $this->assertTrue($out['sections'][0]['orderable']);
        // A field with no label falls back to its id rather than rendering blank.
        $this->assertSame('colors.primary', $out['settings'][0]['fields'][0]['label']);
    }

    public function test_an_include_expands_the_shared_fragment(): void
    {
        $out = SchemaV2::normalize(['version' => 2, 'settings' => [['include' => 'splash']]]);

        $ids = array_column($out['settings'][0]['fields'], 'id');
        $this->assertContains('splash.enabled', $ids);
        $this->assertContains('splash.style', $ids);
        $this->assertContains('splash.duration', $ids);
        $this->assertSame('splash', $out['settings'][0]['from']);
    }

    public function test_an_include_may_override_a_label_and_default_but_not_an_id(): void
    {
        $out = SchemaV2::normalize(['version' => 2, 'settings' => [[
            'include' => 'splash',
            'group' => 'Intro',
            'fields' => ['splash.title' => ['label' => 'Tagline', 'default' => 'Fresh daily', 'id' => 'evil.key']],
        ]]]);

        $this->assertSame('Intro', $out['settings'][0]['group']);
        $field = collect($out['settings'][0]['fields'])->firstWhere('label', 'Tagline');
        $this->assertSame('splash.title', $field['id'], 'an override must never re-point a field id');
        $this->assertSame('Fresh daily', $field['default']);
    }

    public function test_an_unknown_include_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        SchemaV2::normalize(['version' => 2, 'settings' => [['include' => 'no-such-fragment']]]);
    }

    public function test_visible_if_is_evaluated_with_and_semantics(): void
    {
        $field = ['id' => 'splash.duration', 'visible_if' => ['splash.enabled' => true, 'splash.style' => 'shared']];

        $this->assertTrue(SchemaV2::isVisible($field, ['splash.enabled' => true, 'splash.style' => 'shared']));
        $this->assertFalse(SchemaV2::isVisible($field, ['splash.enabled' => true, 'splash.style' => 'theme']));
        $this->assertFalse(SchemaV2::isVisible($field, ['splash.style' => 'shared']));
        $this->assertTrue(SchemaV2::isVisible(['id' => 'x'], []), 'a field with no condition is always visible');
    }

    public function test_every_shipped_theme_schema_loads_and_builds_defaults(): void
    {
        foreach ($this->themeSlugs() as $slug) {
            $schema = ThemeCustomizerService::schemaFor($slug);
            $this->assertSame(2, $schema['version'] ?? null, "{$slug} did not normalize to v2");

            $defaults = ThemeCustomizerService::defaults($schema);
            $this->assertIsArray($defaults);
            // Defaults must themselves survive validation — otherwise the very
            // first save of an untouched store would be rejected.
            $this->assertSame([], ThemeCustomizerService::validate($schema, $defaults), "{$slug} defaults do not validate");
        }
    }

    public function test_a_legacy_key_resolves_to_its_canonical_id(): void
    {
        $this->assertSame('splash.enabled', ThemeKeys::canonical('crave', 'toggles.show_splash'));
        $this->assertSame('splash.title', ThemeKeys::canonical('crave', 'texts.splash_title'));
        $this->assertSame('search.enabled', ThemeKeys::canonical('aurora', 'toggles.show_search'));
        // Per-theme: crave's hero section WAS the splash, aurora's is a real hero.
        $this->assertSame('splash.enabled', ThemeKeys::canonical('crave', 'sections.hero'));
        $this->assertSame('sections.hero', ThemeKeys::canonical('aurora', 'sections.hero'));
        // Anything not aliased is already canonical.
        $this->assertSame('colors.primary', ThemeKeys::canonical('crave', 'colors.primary'));
    }

    public function test_folding_writes_canonical_ids_and_lets_the_canonical_value_win(): void
    {
        $folded = ThemeKeys::fold('crave', [
            'toggles.show_splash' => false,
            'colors.primary' => '#abcdef',
        ]);
        $this->assertSame(['splash.enabled' => false, 'colors.primary' => '#abcdef'], $folded);

        $conflict = ThemeKeys::fold('crave', [
            'toggles.show_splash' => false,
            'splash.enabled' => true,
        ]);
        $this->assertSame(['splash.enabled' => true], $conflict, 'the canonical id must win a conflict');
        $this->assertArrayNotHasKey('toggles.show_splash', $conflict);
    }

    /** @return array<int,string> */
    private function themeSlugs(): array
    {
        $slugs = [];
        foreach (glob(base_path('../assets/store_templates/*/theme.json')) as $path) {
            $slugs[] = basename(dirname($path));
        }
        $this->assertNotEmpty($slugs, 'no theme schemas found');

        return $slugs;
    }
}
