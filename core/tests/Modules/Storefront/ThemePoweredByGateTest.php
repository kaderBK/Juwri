<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Tests\TestCase;

/**
 * The four newer storefront templates (vivid, pulse, aurora, minimal) used to
 * hardcode the "Powered by …" footer. Each must instead gate it on the merchant
 * plan's hide_branding feature (as classic-theme does) so a white-label plan can
 * remove the platform branding. (The templates' render smoke is covered by
 * SparseOptionsRenderTest; this pins the gate so it is never dropped again.)
 */
class ThemePoweredByGateTest extends TestCase
{
    public function test_newer_themes_gate_their_powered_by_footer_on_hide_branding(): void
    {
        foreach (['vivid', 'pulse', 'aurora', 'minimal'] as $theme) {
            $src = (string) file_get_contents(resource_path("views/store_templates/{$theme}/index.blade.php"));

            $this->assertStringContainsString('Powered by', $src, "{$theme}: has a powered-by footer");
            $this->assertStringContainsString(
                "store_plan_allows(\$store, 'hide_branding')",
                $src,
                "{$theme}: the powered-by footer must be gated on the hide_branding plan feature"
            );

            // And the gate must sit BEFORE the branding text (i.e. it wraps it), not
            // somewhere unrelated in the file.
            $gatePos = strpos($src, "store_plan_allows(\$store, 'hide_branding')");
            $poweredPos = strpos($src, 'Powered by');
            $this->assertNotFalse($gatePos);
            $this->assertNotFalse($poweredPos);
            $this->assertLessThan($poweredPos, $gatePos, "{$theme}: the hide_branding gate must wrap the powered-by footer");
        }
    }
}
