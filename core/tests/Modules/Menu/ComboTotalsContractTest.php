<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * COMBOS-V2 Phase A (rev/combos-v2): the QUOTED == CHARGED combo contract.
 *
 * The shipped bug: the shared combos partial (_partials/combos.blade.php)
 * keeps its own basket and rides the send-order POST via the forwarded
 * `combos` field; the server (ComboService::resolve) prices those lines into
 * the ORDER subtotal and taxes them like any other line — but no theme folded
 * the combo total into its DISPLAYED quote. A diner clicked "Place order
 * $61.52" and was charged $117.87 (order #100116 on crave-demo, 2026-08-23).
 *
 * The fix: the partial publishes display state on
 * `window.mbCombos = {count, total, lines}` (+ the `mb:combos-changed`
 * event), and every kept theme's script.js folds `comboTotal()` into its
 * subtotal path, renders the read-only combos group, and allows combos-only
 * checkout. This test pins that every kept theme carries the integration, so
 * an 18th theme cannot ship the mismatch again.
 *
 * Like ThemeLoginOtpContractTest this is a static source contract, not a
 * browser e2e (no Dusk/chromedriver on the runners): it asserts the presence
 * of the integration markers in each theme's shipped script.js. The live
 * proof for the reference theme is order #100117 (quote $66.35 == DB
 * grand_total 66.350, combos-only).
 */
class ComboTotalsContractTest extends TestCase
{
    /** Repo-root assets dir (the storefront docroot is the REPO root, not core/public). */
    private function themeScript(string $theme): string
    {
        $path = base_path('../assets/store_templates/'.$theme.'/js/script.js');
        $this->assertFileExists($path, "theme [$theme] ships no script.js");

        return (string) file_get_contents($path);
    }

    public function test_every_kept_theme_folds_the_combo_basket_into_its_quote(): void
    {
        foreach (KeptTemplates::MODERN as $theme) {
            $js = $this->themeScript($theme);

            $this->assertStringContainsString(
                'mbCombos',
                $js,
                "theme [$theme] script.js never reads window.mbCombos — its quoted total will exclude combo lines (QUOTED != CHARGED)."
            );

            $this->assertStringContainsString(
                'comboTotal()',
                $js,
                "theme [$theme] script.js has no comboTotal() fold-in — the combo basket is not part of its subtotal."
            );

            $this->assertStringContainsString(
                'mb:combos-changed',
                $js,
                "theme [$theme] script.js never listens for mb:combos-changed — the quote goes stale when the combo basket changes."
            );

            $this->assertStringContainsString(
                'data-combo-open',
                $js,
                "theme [$theme] script.js renders no combos group in its cart/checkout summary."
            );
        }
    }

    public function test_the_shared_partial_publishes_the_display_state(): void
    {
        $partial = (string) file_get_contents(
            resource_path('views/store_templates/_partials/combos.blade.php')
        );

        // The themes' side of the contract is meaningless unless the partial
        // keeps publishing the state they consume.
        $this->assertStringContainsString('window.mbCombos', $partial);
        $this->assertStringContainsString('mb:combos-changed', $partial);
    }
}
