<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * P1-6 — UI-kit conformance. The menu-engineering quadrant pills were the known
 * dark-mode offender (hardcoded hex fills); they must now use theme-aware
 * kit tones (ui-pill data-tone). Guards against regressions to inline-hex badge fills.
 */
class UiKitConformanceTest extends TestCase
{
    public function test_menu_engineering_pills_use_theme_variants_not_hex(): void
    {
        $blade = file_get_contents(resource_path('views/owner/reports/analytics.blade.php'));

        // Theme-aware kit tone present.
        $this->assertStringContainsString('<span class="ui-pill" data-tone="', $blade);
        // No hardcoded hex fill on the quadrant pill.
        $this->assertStringNotContainsString('style="color:#fff;background:', $blade);
        $this->assertDoesNotMatchRegularExpression('/color:\s*[\'"]?#(?:16a34a|d97706|2563eb|dc2626|0891b2|6b7280)/', $blade);
    }

    public function test_ui_kit_contract_file_exists(): void
    {
        $this->assertFileExists(base_path('../docs/dev/owner-ui-kit.md'));
    }
}
