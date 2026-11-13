<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use Juwri\Modules\Storefront\Services\Theme\ContrastGuard;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use PHPUnit\Framework\TestCase;

/**
 * P3-01: the PHP contrast maths (ContrastGuard::kitMix / derive / evaluate and
 * Tokens::contrastRatio) must reproduce the kit's Python (tools/themes/tools/
 * build_theme_css.py + check_derived.py) on a fixed set of colours. The expected
 * values are in tests/Fixtures/theme/contrast_parity.json, generated ONCE by
 * tools/themes/tools/make_contrast_fixture.py from the Python tool, never by PHP.
 */
class ThemeContrastParityTest extends TestCase
{
    private const EPS = 1e-9;

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/theme/contrast_parity.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_contrast_ratio_matches_contrast_py_on_the_fixed_pairs(): void
    {
        $pairs = $this->fixture()['pairs'];
        $this->assertGreaterThanOrEqual(80, count($pairs));
        foreach ($pairs as $p) {
            $this->assertEqualsWithDelta($p['ratio'], Tokens::contrastRatio($p['a'], $p['b']), self::EPS, "{$p['a']} on {$p['b']}");
            // the ratio is symmetric
            $this->assertEqualsWithDelta($p['ratio'], Tokens::contrastRatio($p['b'], $p['a']), self::EPS);
        }
    }

    public function test_the_colour_mix_matches_python_including_exact_half_channels(): void
    {
        $mixes = $this->fixture()['mixes'];
        foreach ($mixes as $m) {
            $this->assertSame($m['result'], ContrastGuard::kitMix($m['a'], $m['b'], $m['pct']), "mix {$m['a']} {$m['pct']}% into {$m['b']}");
        }
        // Python rounds .5 to even (0.5 -> 0, 1.5 -> 2); PHP's round() would give 1 here.
        $this->assertSame('#000000', ContrastGuard::kitMix('#010101', '#000000', 50));
        $this->assertSame('#020202', ContrastGuard::kitMix('#030303', '#000000', 50));
    }

    public function test_derived_colours_inks_and_every_check_match_for_all_kit_presets_and_random_owner_palettes(): void
    {
        $fx = $this->fixture();
        $cases = array_merge($fx['presets'], $fx['owner']);
        $this->assertCount(44 + 120, $cases);
        foreach ($cases as $i => $c) {
            $where = "case $i ({$c['scheme']})";
            $palette = ContrastGuard::palette($c['roles'], $c['scheme']);
            foreach ($c['derived'] as $token => $hex) {
                $this->assertSame($hex, $palette[$token], "$where derived $token");
            }
            $this->assertSame($c['on_primary'], $palette['on_primary'], "$where on_primary");
            $this->assertSame($c['on_accent'], $palette['on_accent'], "$where on_accent");

            $results = ContrastGuard::evaluate($c['roles'], $c['scheme']);
            $this->assertCount(count($c['checks']), $results, $where);
            foreach ($c['checks'] as $k => [$fg, $bg, $ratio, $need, $pass]) {
                $r = $results[$k];
                $this->assertSame([$fg, $bg], [$r['fg'], $r['bg']], "$where pair order");
                $this->assertEqualsWithDelta($ratio, $r['ratio'], self::EPS, "$where $fg/$bg");
                $this->assertSame((float) $need, (float) $r['need'], "$where $fg/$bg threshold");
                $this->assertSame($pass, $r['pass'], "$where $fg/$bg pass");
            }
        }
    }

    public function test_every_kit_preset_passes_in_both_schemes_like_check_derived_py(): void
    {
        $presets = $this->fixture()['presets'];
        foreach ($presets as $c) {
            foreach (ContrastGuard::evaluate($c['roles'], $c['scheme']) as $r) {
                $this->assertTrue($r['pass'], "{$c['theme']}/{$c['preset']} {$c['scheme']} {$r['fg']}/{$r['bg']} {$r['ratio']}");
            }
        }
    }

    public function test_the_thresholds_are_the_kits(): void
    {
        $this->assertSame(4.5, ContrastGuard::AA_NORMAL);
        $this->assertSame(3.0, ContrastGuard::AA_LARGE);
        $this->assertSame(3.0, ContrastGuard::AA_UI);
        $needs = array_unique(array_map(fn ($r) => $r['need'], ContrastGuard::evaluate(
            ['primary' => '#b4331a', 'background' => '#faf6f0', 'surface' => '#ffffff', 'text' => '#1f1a17', 'accent' => '#e8a33d'],
            'light'
        )));
        sort($needs);
        $this->assertSame([3.0, 4.5], $needs);
    }
}
