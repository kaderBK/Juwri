<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S3: dashboard + admin + staff POS/KDS.
 *
 * Prices are echoed on the owner dashboard, both admin shells (NexLink admin and
 * the superadmin layout), and the staff POS/KDS screens. Each of those surfaces
 * carries its own font stack — the storefront wiring (S2) does not reach them —
 * so the riyal glyph would render as tofu there unless:
 *  1. the always-on @font-face partial is included in the surface's <head>, and
 *  2. 'Saudi Riyal' leads that surface's effective body/price font stack.
 *
 * Source assertions (the pattern ThemeFontPairsTest already uses) — cheap and
 * discriminating, no auth/session bootstrap needed.
 */
class SaudiRiyalAdminFontTest extends TestCase
{
    private function viewSrc(string $file): string
    {
        return (string) file_get_contents(resource_path('views/'.$file));
    }

    private function asset(string $relative): string
    {
        return (string) file_get_contents(base_path('../'.$relative));
    }

    public function test_the_shared_partial_emits_the_face(): void
    {
        $html = view('partials.riyal-font')->render();

        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString("font-family:'Saudi Riyal'", $html);
        $this->assertStringContainsString('unicode-range:U+20C1;', $html);
    }

    /** The storefront partial must delegate to the shared one — single source of truth. */
    public function test_storefront_partial_delegates_to_the_shared_partial(): void
    {
        $src = $this->viewSrc('store_templates/_partials/riyal-font.blade.php');
        $this->assertStringContainsString("@include('partials.riyal-font')", $src);
    }

    public function test_owner_dashboard_and_nexlink_admin_head_wires_the_face_and_body_var(): void
    {
        // WI-16a/16f: the owner dashboard head is the kit shell head; its body font
        // var is the kit stack, which leads with Saudi Riyal (tokens.css --ui-font).
        $head = $this->viewSrc('components/ui/shell/head.blade.php');

        $this->assertStringContainsString("@include('partials.riyal-font')", $head);
        $this->assertStringContainsString('--bs-body-font-family:var(--ui-font)', $head);
        $this->assertMatchesRegularExpression(
            "/--ui-font:\\s*'Saudi Riyal',/",
            (string) file_get_contents(public_path('assets/owner-ui/tokens.css')),
            'the body font stack must lead with Saudi Riyal so dashboard prices render the glyph'
        );
    }

    public function test_superadmin_styles_wires_the_face_and_body_var(): void
    {
        $styles = $this->viewSrc('admin/includes/styles.blade.php');

        $this->assertStringContainsString("@include('partials.riyal-font')", $styles);
        // WI-10: no Bootstrap body font any more; the kit stack (tokens.css
        // --ui-font) leads with the Riyal face.
        $this->assertStringContainsString('assets/owner-ui/tokens.css', $styles);
        $this->assertMatchesRegularExpression("/--ui-font:\\s*'Saudi Riyal',/", (string) file_get_contents(public_path('assets/owner-ui/tokens.css')));
    }

    public function test_staff_pos_screens_wire_the_face(): void
    {
        foreach (['register', 'display', 'floor'] as $screen) {
            $src = $this->viewSrc('staff/pos/'.$screen.'.blade.php');
            $this->assertStringContainsString(
                "@include('partials.riyal-font')",
                $src,
                "[staff/pos/{$screen}] must include the always-on Saudi Riyal @font-face"
            );
        }
    }

    public function test_staff_pos_body_stacks_lead_with_saudi_riyal(): void
    {
        // register reads its body font from the extracted stylesheet…
        $this->assertStringContainsString(
            "font-family: 'Saudi Riyal', Inter, 'IBM Plex Sans Arabic', system-ui, sans-serif;",
            $this->asset('assets/pos/pos-register.css'),
            'pos-register.css body font must lead with Saudi Riyal'
        );

        // …display + floor set it inline.
        foreach (['display', 'floor'] as $screen) {
            $this->assertStringContainsString(
                "font-family: 'Saudi Riyal', Inter, 'IBM Plex Sans Arabic', system-ui, sans-serif;",
                $this->viewSrc('staff/pos/'.$screen.'.blade.php'),
                "[staff/pos/{$screen}] body font must lead with Saudi Riyal"
            );
        }
    }
}
