<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use PHPUnit\Framework\TestCase;
use Tests\Support\ViewRoots;

/**
 * P1-20 slice 4 (J28): every raw `{!! !!}` output in the storefront templates (store_templates, bio pages,
 * marketplace/directory pages) is listed here with why it is safe. A new raw output — or one more use of an
 * existing one — fails until it is escaped (`{{ }}`, @json) or reviewed and added with its reason.
 * Audit at P1-20: 57 outputs in 27 files; the two unescaped price outputs (pandabite, pizzaflow) were
 * switched to `{{ }}` (the currency sign comes from store settings).
 * P3-13 (the 8 D11 premium themes): 30 raw outputs → 1 — carely's 25 icons are an SVG sprite + escaped <use>, glow's
 * logo slot a partial, aurelia's string @json, freshly's mode icons literal SVG; ember's hero stays (reviewed). Every
 * raw output left in those themes carries a `raw-ok` comment right above it (second test).
 */
class StorefrontRawOutputAuditTest extends TestCase
{
    /** file => [expression prefix => [count, reason]] */
    private const ALLOW = [
        'store_templates/info-page.blade.php' => [
            'nl2br(e(' => [1, 'escaped with e() first; nl2br only adds <br>'],
        ],
        'store_templates/layout.blade.php' => [
            'seo_hreflang_tags_for_store(' => [1, 'tags built with e() (SeoHelper)'],
        ],
        'store_templates/marketplace-shell.blade.php' => [
            '$shellStyles[\'font_css\']' => [1, 'FontPairs::fontFaceCss — family/file/weight/range sanitised'],
            '$shellStyles[\'base_css\']' => [1, 'constant CSS'],
            '$shellStyles[\'tokens_css\']' => [1, 'Tokens::css — names + values regex-validated'],
            '$shellStyles[\'tokens_dark_css\']' => [1, 'Tokens::css (dark block) — names + values regex-validated'],
            '$shellStyles[\'dark_css\']' => [1, 'DarkTokens::css — token names and values regex-validated (no < ; } or quotes beyond colour/font tokens)'],
            '$shellStyles[\'sections_css\']' => [1, 'SectionRestyler::clean — no <, \\, comments; re-cleaned by Html::cleanPage, scoped'],
            '$body' => [1, 'Liquid theme render (marketplace-reviewed theme code; merchant values escaped by the engine); enforced nonce CSP on store hosts'],
        ],
        'store_templates/_partials/custom-blocks.blade.php' => [
            '$mbcbBlock->renderFor(' => [1, 'owner HTML through Html::cleanPage allow-list sanitiser (iframes sandboxed)'],
        ],
        'store_templates/_partials/jsonld-kit.blade.php' => [
            'json_ld(' => [1, 'json_ld() — JSON_HEX_TAG|AMP|APOS|QUOT (P6-305 kit product/store JSON-LD)'],
        ],
        'store_templates/_partials/jsonld-menu.blade.php' => [
            'json_ld(' => [1, 'json_ld() — JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
        'store_templates/_partials/mk-rating-prompt.blade.php' => [
            '$mkTrackRated' => [2, 'constant attribute text'],
        ],
        'store_templates/_partials/table-verify.blade.php' => [
            '$__tvIsOtp' => [1, 'constant HTML entities'],
        ],
        'store_templates/_partials/theme-fonts.blade.php' => [
            '$__fontFaceCss' => [1, 'FontPairs::fontFaceCss — sanitised'],
        ],
        'store_templates/_partials/theme-tokens.blade.php' => [
            '$__tokCss' => [1, 'Tokens::css — regex-validated'],
            '$__tokDarkCss' => [1, 'Tokens::css (dark block) — regex-validated'],
        ],
        'store_templates/crumb/index.blade.php' => [
            'json_encode(collect(app(\\Juwri\\Modules\\Auth\\Services\\Social' => [1, 'json_encode flags 15 = JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
        'store_templates/dough/index.blade.php' => [
            'json_encode(collect(app(\\Juwri\\Modules\\Auth\\Services\\Social' => [1, 'json_encode flags 15 = JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
        'store_templates/ember/index.blade.php' => [
            '$__emberHero' => [1, 'e() first, then an exact allow-list of <br>/<span class="hot"> restored'],
        ],
        'store_templates/lumiere/partials/_calendar.blade.php' => [
            '$ring(' => [1, 'numeric SVG from an int percentage'],
        ],
        'store_templates/lumiere/partials/_home.blade.php' => [
            '$ring(' => [1, 'numeric SVG from an int percentage'],
        ],
        'store_templates/picnic/index.blade.php' => [
            'json_encode(collect(app(\\Juwri\\Modules\\Auth\\Services\\Social' => [1, 'json_encode flags 15 = JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
        'store_templates/scoop/index.blade.php' => [
            'json_encode(collect(app(\\Juwri\\Modules\\Auth\\Services\\Social' => [1, 'json_encode flags 15 = JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
        'bio/html.blade.php' => [
            '\\Juwri\\Modules\\Content\\Services\\Builder\\BuilderRender::page(' => [1, 'owner HTML through Html::cleanPage allow-list sanitiser'],
        ],
        'bio/layout.blade.php' => [
            '$bioCss' => [1, 'BioAppearance::sanitize — owner appearance JSON validated to safe CSS'],
        ],
        'marketplace/layout.blade.php' => [
            '$mkCtx' => [1, 'constant attribute text'],
        ],
        'marketplace/show.blade.php' => [
            'json_ld(' => [1, 'json_ld() — JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
        'marketplace/directory/biz.blade.php' => [
            'json_ld(' => [1, 'json_ld() — JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
        'marketplace/directory/explore.blade.php' => [
            'json_ld(' => [1, 'json_ld() — JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
        'marketplace/partials/jsonld-restaurant.blade.php' => [
            'json_ld(' => [1, 'json_ld() — JSON_HEX_TAG|AMP|APOS|QUOT'],
        ],
    ];

    public function test_every_raw_storefront_output_is_reviewed(): void
    {
        $found = [];
        // resources/views and the ee modules' Views (P1-13: the Fusion/directory views moved to ee Marketplace); a file
        // keeps its path relative to its root, so the reviewed list below keys the same views.
        foreach (ViewRoots::all() as $root) {
            foreach (['store_templates', 'bio', 'marketplace'] as $dir) {
                if (! is_dir($root.'/'.$dir)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if (! str_ends_with($file->getFilename(), '.blade.php')) {
                        continue;
                    }
                    $rel = substr($file->getPathname(), strlen($root) + 1);
                    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
                    preg_match_all('/\{!!(.*?)!!\}/s', $src, $m);
                    foreach ($m[1] as $expr) {
                        $expr = trim((string) preg_replace('/\s+/', ' ', $expr));
                        if ($expr === '') {
                            continue;
                        }
                        $prefix = null;
                        foreach (array_keys(self::ALLOW[$rel] ?? []) as $p) {
                            if (str_starts_with($expr, $p)) {
                                $prefix = $p;
                                break;
                            }
                        }
                        $this->assertNotNull($prefix, "unreviewed raw output in {$rel}: {!! {$expr} !!}");
                        $found[$rel][$prefix] = ($found[$rel][$prefix] ?? 0) + 1;
                    }
                }
            }
        }

        foreach (ViewRoots::ee() as $root) {
            // An installed edition package's views are scanned (the directory views moved into one in P1-13 13d). Asked
            // of the installed package (its manifest's view roots), never of a folder: a leftover empty folder on a
            // Community checkout must not count as an installed package.
            if (is_file($root.'/marketplace/directory/explore.blade.php')) {
                $this->assertArrayHasKey('marketplace/directory/explore.blade.php', $found);
            }
        }

        foreach (self::ALLOW as $rel => $entries) {
            foreach ($entries as $prefix => [$count]) {
                $this->assertLessThanOrEqual($count, $found[$rel][$prefix] ?? 0, "more raw `{$prefix}` outputs in {$rel} than reviewed");
            }
        }
    }

    /**
     * P3-13: the D11 premium themes (aurora, velvet, ember, glow, vivid, carely, aurelia, freshly) — every raw output
     * left is justified in place: a Blade comment with `raw-ok` ends within the three lines above it.
     */
    public function test_every_raw_output_in_the_premium_themes_is_justified_where_it_is(): void
    {
        $unjustified = [];
        $total = 0;
        foreach (['aurora', 'velvet', 'ember', 'glow', 'vivid', 'carely', 'aurelia', 'freshly'] as $theme) {
            $dir = dirname(__DIR__, 3).'/resources/views/store_templates/'.$theme;
            $this->assertDirectoryExists($dir);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $lines = explode("\n", (string) file_get_contents($file->getPathname()));
                foreach ($lines as $n => $line) {
                    $code = (string) preg_replace('/\{\{--.*?--\}\}/', '', $line);
                    if (! str_contains($code, '{!!')) {
                        continue;
                    }
                    $total++;
                    $above = implode("\n", array_slice($lines, max(0, $n - 3), min(3, $n)));
                    if (! str_contains($above, 'raw-ok')) {
                        $unjustified[] = $theme.'/'.$file->getFilename().':'.($n + 1);
                    }
                }
            }
        }

        $this->assertSame([], $unjustified, 'raw {!! !!} output in a premium theme without a `raw-ok` justification comment above it');
        $this->assertLessThanOrEqual(1, $total, 'P3-13 left one reviewed raw output in the premium themes (ember hero)');
    }
}
