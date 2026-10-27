<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Tests\TestCase;

/**
 * Customizer Card Picker ("CC" run) — lane A asset contract,
 * rev/customizer-cards/SPEC.md §1/§2/§8.
 *
 * Pins the 12 preview tiles (well-formed, inert, budget-sized), the tcc-
 * selector scoping of tc-cards.css (shared by the customizer partial and
 * /admin/menu-templates — a stray selector would leak into the dashboard),
 * and the pinned cross-lane markup contract of tc-card-select.blade.php so
 * lane B's include keeps working against exactly this partial. File-level
 * assertions only — no page render, so no shareLayoutGlobals() needed.
 */
class StoriesCardAssetsTest extends TestCase
{
    /** The 12 tile slugs pinned by SPEC §1 / §8. */
    private const SLUGS = [
        'inherit', 'none', 'ribbon', 'orb', 'toolbar', 'capsule',
        'tassel', 'seal', 'dogear', 'filmstrip', 'medallion', 'sheet',
    ];

    /** Repo-root asset path (docroot is the repo root, not core/public). */
    private function previewPath(string $file): string
    {
        return base_path('../assets/global/stories-entries/previews/' . $file);
    }

    private function partialPath(): string
    {
        return resource_path('views/owner/design/partials/tc-card-select.blade.php');
    }

    /* ------------------------------------------------------------ tiles --- */

    public function test_all_twelve_tiles_exist_within_budget(): void
    {
        foreach (self::SLUGS as $slug) {
            $path = $this->previewPath($slug . '.svg');
            $this->assertFileExists($path, "$slug.svg missing");
            $this->assertLessThanOrEqual(4096, filesize($path), "$slug.svg over the 4KB budget");
        }
    }

    public function test_tiles_are_well_formed_svg_with_the_shared_viewbox(): void
    {
        foreach (self::SLUGS as $slug) {
            $body = file_get_contents($this->previewPath($slug . '.svg'));
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_use_internal_errors($prev);
            $this->assertNotFalse($xml, "$slug.svg is not well-formed XML");
            $this->assertSame('svg', $xml->getName(), "$slug.svg root element");
            $this->assertSame('0 0 96 72', (string) $xml['viewBox'], "$slug.svg viewBox drifted");
        }
    }

    public function test_tiles_are_inert_for_img_loading(): void
    {
        // Tiles load via img tags: no scripts, no foreignObject, no inline
        // handlers, no href/external references of any kind. The only URL
        // allowed is the SVG xmlns declaration itself.
        foreach (self::SLUGS as $slug) {
            $body = file_get_contents($this->previewPath($slug . '.svg'));
            $this->assertSame(0, preg_match_all('/<\s*script/i', $body), "$slug.svg has a script element");
            $this->assertSame(0, preg_match_all('/<\s*foreignObject/i', $body), "$slug.svg has a foreignObject");
            $this->assertSame(0, preg_match_all('/\son\w+\s*=/i', $body), "$slug.svg has an inline handler");
            $this->assertSame(0, preg_match_all('/\bhref\s*=/i', $body), "$slug.svg carries an href");
            $stripped = str_replace('xmlns="http://www.w3.org/2000/svg"', '', $body);
            $this->assertStringNotContainsString('http', $stripped, "$slug.svg references an external URL");
        }
    }

    /* -------------------------------------------------------- tc-cards.css --- */

    public function test_card_css_exists_and_every_selector_is_tcc_prefixed(): void
    {
        $path = $this->previewPath('tc-cards.css');
        $this->assertFileExists($path);
        $css = file_get_contents($path);

        // Strip comments, then sweep every "selector {" group. At-rules are
        // skipped as groups; keyframe offsets (0%/50%/from/to) are skipped as
        // parts. Everything else must START with .tcc- (SPEC §8 contract —
        // this file is linked into dashboard pages, a bare selector leaks).
        $flat = preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{/', $flat, $m);
        $offenders = [];
        foreach ($m[1] as $group) {
            $group = trim($group);
            if ($group === '' || str_starts_with($group, '@')) {
                continue;
            }
            foreach (explode(',', $group) as $part) {
                $part = trim($part);
                if ($part === '' || preg_match('/^(\d+%|from|to)$/', $part)) {
                    continue;
                }
                if (! str_starts_with($part, '.tcc-')) {
                    $offenders[] = $part;
                }
            }
        }
        $this->assertSame([], $offenders, "selectors without the .tcc- prefix:\n" . implode("\n", $offenders));
    }

    public function test_card_css_animations_are_guarded_and_composited(): void
    {
        $css = file_get_contents($this->previewPath('tc-cards.css'));

        // Hover micro-animations must sit behind reduced-motion (SPEC dec. 3).
        $this->assertStringContainsString('@media (prefers-reduced-motion: no-preference)', $css);

        // Keyframe bodies may only animate transform/opacity (no layout thrash).
        preg_match_all('/@keyframes[^{]+\{((?:[^{}]*\{[^{}]*\})+)\s*\}/s', $css, $kb);
        $this->assertNotEmpty($kb[1], 'expected keyframes in tc-cards.css');
        foreach ($kb[1] as $body) {
            preg_match_all('/([a-z-]+)\s*:/', $body, $props);
            foreach ($props[1] as $prop) {
                $this->assertContains($prop, ['transform', 'opacity'], "keyframe animates '$prop' (transform/opacity only)");
            }
        }

        // CSP hygiene: a literal style/script token would trip the ratchet lint.
        $this->assertSame(0, preg_match_all('/<\s*(script|style)/i', $css));
    }

    /* ------------------------------------------------------------ partial --- */

    public function test_partial_exists_and_keeps_the_pinned_cross_lane_contract(): void
    {
        $this->assertFileExists($this->partialPath());
        $blade = file_get_contents($this->partialPath());

        // The exact guard lane B's include relies on (SPEC §8): one template
        // block that only claims selects carrying option_previews.
        $this->assertStringContainsString("f.type==='select' && f.option_previews", $blade);
        $this->assertSame(1, substr_count($blade, '<template x-if='), 'exactly one x-if template block');

        // Radio-card markup contract: hidden native radios in label tiles,
        // radiogroup container, select-identical persistence path.
        $this->assertStringContainsString('role="radiogroup"', $blade);
        $this->assertStringContainsString('<label class="tcc-card"', $blade);
        $this->assertStringContainsString('type="radio"', $blade);
        $this->assertStringContainsString(':checked="values[f.id]===opt"', $blade);
        $this->assertStringContainsString('@change="set(f.id, opt)"', $blade);
        $this->assertStringContainsString('f.option_labels[opt]) || opt', $blade);
        $this->assertStringContainsString('f.option_sublabels', $blade);

        // The stylesheet ships once (double-emit guarded) from the shared path.
        $this->assertStringContainsString('@once', $blade);
        $this->assertStringContainsString('global/stories-entries/previews/tc-cards.css', $blade);

        // CSP hygiene: no inline handlers, no literal script/style tokens
        // (Alpine's @change is x-on sugar, not an on*= attribute).
        $this->assertSame(0, preg_match_all('/\son\w+\s*=/i', $blade));
        $this->assertSame(0, preg_match_all('/<\s*(script|style)[\s>]/i', $blade));
    }
}
