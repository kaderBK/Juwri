<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * N2 — unified dashboard toast motion + a11y.
 *
 * Two toast surfaces are unified: the Alpine $store.toast (old-theme.js, rendered by
 * flash.blade) and the DOM-fallback window.dashToast (toast.blade). Both gain a
 * slide/fade choreography, pause-on-hover that suspends the auto-dismiss timer,
 * a max-stack of 4, and a prefers-reduced-motion instant path. The live regions
 * carry aria-live=polite + aria-atomic=true + role=status.
 */
class NotifToastMotionTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('assets/owner-ui/shell-extras.css'));
    }

    private function ownerJs(): string
    {
        return file_get_contents(public_path('assets/owner-ui/services.js'));
    }

    private function flash(): string
    {
        return file_get_contents(resource_path('views/owner/partials/flash.blade.php'));
    }

    private function toast(): string
    {
        return file_get_contents(resource_path('views/owner/partials/toast.blade.php'));
    }

    public function test_css_defines_toast_choreography_with_rtl_and_reduced_motion(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('@keyframes dashToastIn', $css);
        $this->assertStringContainsString('@keyframes dashToastOut', $css);
        // RTL flips the slide direction (memory: rtl-flex-carousel-translatex).
        $this->assertStringContainsString('@keyframes dashToastInRtl', $css);
        $this->assertStringContainsString('[dir="rtl"] .dash-toast', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.dash-toast[^}]*animation:\s*none/s',
            $css
        );
    }

    public function test_store_pauses_timer_on_hover_and_caps_the_stack(): void
    {
        $js = $this->ownerJs();
        // Timer suspension (not just visual) + resume.
        $this->assertStringContainsString('pause(id)', $js);
        $this->assertStringContainsString('resume(id)', $js);
        // Leave choreography + reduced-motion short-circuit.
        $this->assertStringContainsString('dismiss(id)', $js);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $js);
        // Max-stack of 4.
        $this->assertStringContainsString('this.items.length >= 4', $js);
    }

    public function test_flash_host_is_a_polite_atomic_live_region(): void
    {
        $flash = $this->flash();
        $this->assertStringContainsString('aria-live="polite"', $flash);
        $this->assertStringContainsString('aria-atomic="true"', $flash);
        $this->assertStringContainsString("'is-leaving': t.leaving", $flash);
        $this->assertStringContainsString('$store.toast.pause(t.id)', $flash);
        $this->assertStringContainsString('$store.toast.resume(t.id)', $flash);
    }

    public function test_dom_fallback_toast_honours_reduced_motion_and_caps_stack(): void
    {
        $toast = $this->toast();
        // Host already a live region.
        $this->assertStringContainsString('aria-live="polite"', $toast);
        $this->assertStringContainsString('aria-atomic="true"', $toast);
        // Reduced-motion instant path + max-stack + pause-on-hover.
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $toast);
        $this->assertStringContainsString('host.children.length >= 4', $toast);
        $this->assertStringContainsString("addEventListener('mouseenter'", $toast);
    }
}
