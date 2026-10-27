<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * T1 — the 17 storefront track status maps.
 *
 * Every theme's client status map must handle `delivered` (terminal — finish the
 * timeline, never snap back to "Received"), `pending_approval` (step 0) and
 * `call_bill` (dine-in — stay at the served step). crave had drifted (missing
 * `new`); the 16 non-aurora STEP maps must stay byte-identical to kill 17-way
 * drift permanently. aurora (also the fallback view) carries the same statuses
 * in its bespoke config object.
 */
class TrackStatusMapTest extends TestCase
{
    /** The 16 themes that share the STEP/word inline pattern. */
    /**
     * The kept templates that share the STEP/word inline pattern — every MODERN
     * theme EXCEPT aurora (which ships a different step map). Derived from the
     * single keep-list source so it never drifts (KeptTemplateRegistryTest).
     */
    private static function stdThemes(): array
    {
        return array_values(array_diff(KeptTemplates::MODERN, ['aurora']));
    }

    private const CANONICAL_STEP = 'var STEP = { unpaid: -1, new: 0, pending_approval: 0, pending: 0, preparing: 1, ready: 2, completed: 3, delivered: 3, call_bill: 2, cancelled: -2 };';

    private function track(string $theme): string
    {
        return file_get_contents(
            resource_path("views/store_templates/{$theme}/track.blade.php")
        );
    }

    public function test_all_16_standard_step_maps_are_identical_and_handle_new_statuses(): void
    {
        foreach (self::stdThemes() as $theme) {
            $src = $this->track($theme);
            // Equivalence: no theme may drift from the canonical STEP map.
            $this->assertStringContainsString(
                self::CANONICAL_STEP,
                $src,
                "STEP map drifted in theme [{$theme}]."
            );
            // Word labels for the three previously-unmapped statuses.
            $this->assertStringContainsString("delivered: @json(___('Delivered'))", $src, "delivered word missing in [{$theme}]");
            $this->assertStringContainsString("pending_approval: @json(___('Awaiting approval'))", $src, "pending_approval word missing in [{$theme}]");
            $this->assertStringContainsString("call_bill: @json(___('Bill requested'))", $src, "call_bill word missing in [{$theme}]");
        }
    }

    public function test_crave_no_longer_drops_the_new_status(): void
    {
        // The original crave drift: STEP + word both lacked `new`, so a brand-new
        // order rendered as step 0 by luck but had no `new` word.
        $src = $this->track('crave');
        $this->assertStringContainsString('new: 0', $src);
        $this->assertStringContainsString("new: @json(___('Received'))", $src);
    }

    public function test_aurora_fallback_config_handles_the_three_statuses(): void
    {
        $src = $this->track('aurora');
        // delivered → terminal, progress to the completed step (not pending fallback).
        $this->assertMatchesRegularExpression(
            "/delivered:\s*\{.*?activeStep:\s*'step-completed'/s",
            $src,
            'aurora delivered must finish the timeline, not fall back to pending.'
        );
        $this->assertStringContainsString('pending_approval: {', $src);
        // call_bill stays at the served/ready step.
        $this->assertMatchesRegularExpression(
            "/call_bill:\s*\{.*?activeStep:\s*'step-ready'/s",
            $src,
            'aurora call_bill must hold the served step.'
        );
    }
}
