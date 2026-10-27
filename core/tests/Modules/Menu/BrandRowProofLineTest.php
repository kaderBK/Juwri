<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Tests\TestCase;

/**
 * BRAND-ROW B2 — the shared proof partial renders count and/or live viewers on one
 * line (single source; the status-strip viewers chip is removed), else the CTA.
 */
class BrandRowProofLineTest extends TestCase
{
    private function render(int $followerCount, int $scansToday, bool $showFollowerCount = true): string
    {
        return view('store_templates._partials.follower-count', [
            'store' => (object) ['id' => 0],
            'followerCount' => $followerCount,
            'scansToday' => $scansToday,
            'showFollowerCount' => $showFollowerCount,
        ])->render();
    }

    public function test_owner_toggle_off_hides_the_number_but_keeps_viewers(): void
    {
        // B11: brand.show_follower_count = false → never the count, viewers still show.
        $html = $this->render(1200, 34, false);
        $this->assertStringNotContainsString('id="cvFollowerCount"', $html);
        $this->assertStringContainsString(___('viewing now'), $html);
    }

    public function test_owner_toggle_off_with_no_viewers_falls_back_to_cta(): void
    {
        $html = $this->render(1200, 0, false);
        $this->assertStringContainsString('brand-followers--cta', $html);
        $this->assertStringNotContainsString('id="cvFollowerCount"', $html);
    }

    public function test_count_and_viewers_share_one_line(): void
    {
        $html = $this->render(1200, 34);
        $this->assertStringContainsString('id="cvFollowerCount"', $html);
        $this->assertStringContainsString('1.2k', $html);
        $this->assertStringContainsString(___('followers'), $html);
        $this->assertStringContainsString('34', $html);
        $this->assertStringContainsString(___('viewing now'), $html);
        $this->assertStringContainsString('brand-dot', $html); // separator present when both show
    }

    public function test_count_only_when_no_viewers(): void
    {
        $html = $this->render(12, 0);
        $this->assertStringContainsString('id="cvFollowerCount"', $html);
        $this->assertStringNotContainsString(___('viewing now'), $html);
        $this->assertStringNotContainsString('brand-dot', $html);
    }

    public function test_viewers_only_when_count_below_threshold(): void
    {
        $html = $this->render(3, 5);
        $this->assertStringNotContainsString('id="cvFollowerCount"', $html);
        $this->assertStringContainsString('5', $html);
        $this->assertStringContainsString(___('viewing now'), $html);
    }

    public function test_cta_when_neither_present(): void
    {
        $html = $this->render(3, 0);
        $this->assertStringContainsString('brand-followers--cta', $html);
        // ___() so the assertion holds under the suite's ar default (the blade
        // renders ___('Follow for offers & new dishes'); & escapes via {{ }}).
        $this->assertStringContainsString(___('Follow for offers & new dishes'), $html);
        $this->assertStringNotContainsString('id="cvFollowerCount"', $html);
    }
}
