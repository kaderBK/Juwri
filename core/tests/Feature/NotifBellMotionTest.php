<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * N1 — dashboard notification bell motion (badge pop + bell shake + row stagger).
 *
 * Motion lives in the owner dashboard CSS as keyframes toggled by Alpine
 * (notifBell.pop) only on a genuine count RISE, and by Bootstrap's .show on the
 * dropdown for the staggered rows. Every animation ships with a
 * prefers-reduced-motion override (§0.9). These are file-content assertions —
 * the exact wiring is load-bearing and would silently drift otherwise.
 */
class NotifBellMotionTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('assets/owner-ui/shell-extras.css'));
    }

    private function topbar(): string
    {
        // WI-16a: bell markup lives in the kit top bar, its Alpine component in layout.js.
        return file_get_contents(resource_path('views/components/ui/shell/topbar.blade.php'))
            .file_get_contents(public_path('assets/owner-ui/layout.js'));
    }

    public function test_dashboard_css_defines_bell_motion_keyframes(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('@keyframes notifBadgePop', $css);
        $this->assertStringContainsString('@keyframes notifBellShake', $css);
        $this->assertStringContainsString('@keyframes notifRowIn', $css);
        // Badge scales in place — the translate(-50%,-50%) offset must survive every
        // keyframe so the badge never moves (button clips overflow).
        $this->assertStringContainsString('translate(-50%, -50%) scale(1.5)', $css);
        // Staggered rows on dropdown open.
        $this->assertStringContainsString('.dropdown-menu.show > .notif-row:nth-of-type(2)', $css);
    }

    public function test_bell_motion_has_reduced_motion_override(): void
    {
        $css = $this->css();
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.notif-badge--pop[^}]*animation:\s*none/s',
            $css,
            'Bell motion must be neutralised under prefers-reduced-motion.'
        );
    }

    public function test_topbar_wires_bell_motion_on_rise_only(): void
    {
        $tb = $this->topbar();
        // Bindings toggled by Alpine state `pop`.
        $this->assertStringContainsString("'notif-badge--pop': pop", $tb);
        $this->assertStringContainsString("'notif-bell--shake': pop", $tb);
        $this->assertStringContainsString('notif-row', $tb);
        // The pulse method + the baseline-guarded rise trigger.
        $this->assertStringContainsString('pulse()', $tb);
        $this->assertStringContainsString('prevTotal', $tb);
        // Discriminating: motion fires ONLY on a real rise, never on first load /
        // Turbo visit — the baseline-first guard must be present.
        $this->assertStringContainsString('this.prevTotal !== null && total > this.prevTotal', $tb);
    }
}
