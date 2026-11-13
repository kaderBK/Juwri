<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Tests\TestCase;

/**
 * T6 · X3 — owner text overrides are wired into the focus-theme storefront indexes.
 *
 * X1 added a `texts.*` key set to the 7 focus themes' theme.json; X3 wires the
 * rendered literals to `theme_setting($store->id, 'texts.<key>', ___('<default>'))`
 * so an owner override wins while the theme's current wording is preserved as the
 * i18n fallback. This is a static guard: it proves every focus index actually reads
 * `texts.*` overrides (i.e. the wiring did not silently regress), and that reads use
 * the fallback form so no store's current text changes until an override is set.
 */
class FocusThemeTextOverrideTest extends TestCase
{
    /** Focus themes (D11). */
    private const FOCUS = ['crave', 'aurora', 'vivid', 'feast', 'velvet', 'glow', 'matcha'];

    public function test_every_focus_theme_index_reads_texts_overrides(): void
    {
        foreach (self::FOCUS as $slug) {
            $path = resource_path("views/store_templates/{$slug}/index.blade.php");
            $this->assertFileExists($path, "focus theme {$slug} index missing");
            $src = file_get_contents($path);

            // At least one owner text-override read must be present.
            $this->assertMatchesRegularExpression(
                "/theme_setting\(\\\$store->id,\s*'texts\./",
                $src,
                "theme {$slug}: no texts.* override read wired (X3 regression)"
            );
        }
    }

    public function test_texts_reads_keep_a_translated_fallback(): void
    {
        // Every texts.* read must pass a default (the ___() fallback or a literal),
        // never a bare two-arg call — otherwise an unset override renders blank.
        foreach (self::FOCUS as $slug) {
            $src = file_get_contents(resource_path("views/store_templates/{$slug}/index.blade.php"));
            if (! preg_match_all("/theme_setting\(\\\$store->id,\s*'texts\.[a-z_]+'\s*(,?)/", $src, $m)) {
                continue;
            }
            foreach ($m[1] as $i => $comma) {
                $this->assertSame(
                    ',',
                    $comma,
                    "theme {$slug}: a texts.* read #{$i} has no fallback default (would render blank when unset)"
                );
            }
        }
    }
}
