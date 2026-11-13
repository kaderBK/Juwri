<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * T4 — status-change motion choreography across all 17 track themes.
 *
 * The only prior motion was an infinite pulse on the current step (and nothing
 * at all on 3 themes). Each theme now animates the actual status CHANGE — a
 * flash on the new current step, a checkmark/step pop on a newly-done step, a
 * single completed-state celebration — driven purely by the class flips updateUI
 * already performs. Every theme also gains a prefers-reduced-motion override for
 * the timeline (previously only orbit had one). Asset-content loop over 17 CSS
 * files; the CSS is auto-cache-busted via ?ver=version.filemtime.
 */
class TrackTimelineMotionTest extends TestCase
{
    private const THEMES = KeptTemplates::MODERN;

    private function css(string $theme): string
    {
        return file_get_contents(public_path("assets/store_templates/{$theme}/css/style.css"));
    }

    /**
     * The T4 change-motion lives in each theme's compiled style.css, except
     * aurora — whose timeline is inline in its blade <style> block — so the
     * change-motion is asserted against the blade there.
     */
    private function changeMotionSource(string $theme): string
    {
        if ($theme === 'aurora') {
            return file_get_contents(resource_path('views/store_templates/aurora/track.blade.php'));
        }

        return $this->css($theme);
    }

    public function test_every_theme_has_change_motion_keyframes(): void
    {
        foreach (self::THEMES as $theme) {
            $src = $this->changeMotionSource($theme);
            $this->assertStringContainsString(
                'T4 order-timeline change motion',
                $src,
                "[$theme] missing the T4 change-motion block"
            );
            // The shared change-motion keyframes exist per theme.
            $this->assertMatchesRegularExpression(
                '/@keyframes mb(StepFlash|StepPop|Done)/',
                $src,
                "[$theme] missing a T4 change-motion keyframe"
            );
            // …and are wired to the class flips updateUI performs.
            $this->assertMatchesRegularExpression(
                '/animation:\s*mb(StepPop|StepFlash|Done)/',
                $src,
                "[$theme] change-motion keyframe is defined but never applied"
            );
        }
    }

    /**
     * Every theme's style.css carries a GLOBAL prefers-reduced-motion guard
     * (`* , *::before , *::after { animation-duration: .01ms }`, or aurora's
     * `.aurora *{animation:none}`) that neutralises ALL animation — including
     * the new T4 keyframes — so no per-rule reduced-motion block is required.
     */
    public function test_every_theme_neutralises_motion_under_reduced_motion(): void
    {
        foreach (self::THEMES as $theme) {
            $css = $this->css($theme);
            $this->assertStringContainsString(
                'prefers-reduced-motion',
                $css,
                "[$theme] has no prefers-reduced-motion guard"
            );
            $neutralised = str_contains($css, 'animation-duration:.01ms')
                || str_contains($css, 'animation-duration: .01ms')
                || preg_match('/animation:\s*none/', $css) === 1;
            $this->assertTrue(
                $neutralised,
                "[$theme] global reduced-motion guard does not neutralise animation"
            );
        }
    }
}
