<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Tests\TestCase;

/**
 * Regression (browser e2e, offers strip): the shared anchor helper decided how far to climb out of
 * the menu grid with a height ratio alone (parent <= 1.6 x grid). Crumb wraps its store header, its
 * category rows and its featured grid in one padding div, so as soon as the seeded grid got a little
 * shorter the ratio flipped and the offers strip was parked ABOVE the store header, ~1000px from the
 * menu. A block that already has [data-mb-grid-lead] rows glued above it IS the menu section and must
 * not be climbed out of.
 *
 * The behaviour itself is DOM geometry and is covered by the storefront-offers-strip browser journey;
 * this guards that the rule stays in the shared helper.
 */
class ThemeAnchorGridLeadTest extends TestCase
{
    public function test_the_anchor_does_not_climb_out_of_a_block_with_grid_lead_rows(): void
    {
        $src = (string) file_get_contents(resource_path('views/store_templates/_partials/theme-anchor.blade.php'));

        $guard = "block.previousElementSibling.hasAttribute('data-mb-grid-lead')) break;";
        $this->assertStringContainsString($guard, $src);
        // The guard sits inside the climb loop, before the height-ratio break.
        $this->assertLessThan(strpos($src, 'gridH * 1.6'), strpos($src, $guard));
    }

    public function test_crumb_marks_its_category_rows_as_grid_lead(): void
    {
        $crumb = (string) file_get_contents(resource_path('views/store_templates/crumb/index.blade.php'));

        $this->assertStringContainsString('<div class="section-head" data-mb-grid-lead>', $crumb);
        $this->assertStringContainsString('<div class="cat-row" id="catRow" data-mb-grid-lead></div>', $crumb);
    }
}
