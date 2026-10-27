<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * Storefront reports (rev/Templats-issues.md) — "the stories CTA opens a new
 * page of the same home page instead of navigating to the item or custom URL",
 * filed against orbit, velora, crumb and soiree.
 *
 * The themes were not at fault: every seeded demo story carries a cta_url equal
 * to the store's own home page, and the shared viewer rendered it with
 * target="_blank". Clicking "Order now" therefore opened a second tab showing
 * the page the visitor was already on — a dead control that looks like a bug.
 *
 * The viewer now refuses to render a CTA that resolves to the current page, and
 * keeps target="_blank" for genuinely external destinations only. These tests
 * pin that contract on the one shared partial that every theme uses.
 */
class StoryCtaDestinationTest extends TestCase
{
    private string $partial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partial = file_get_contents(
            base_path('resources/views/store_templates/_partials/story-cta-policy.blade.php')
        );
    }

    public function test_a_cta_pointing_at_the_current_page_is_not_rendered(): void
    {
        $this->assertStringContainsString(
            'isCurrentPage',
            $this->partial,
            'The story viewer must not render a CTA whose destination is the page the visitor is already on.'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(!url \|\| isCurrentPage\(url\)\)\s*\{\s*anchor\.hidden = true;/',
            $this->partial,
            'apply() must hide the anchor for an absent or same-page destination.'
        );
    }

    /** The policy is unconditional: the stories RAIL partial is opt-in and renders nothing on themes with a native rail. */
    public function test_the_layout_includes_the_policy_unconditionally(): void
    {
        $layout = file_get_contents(base_path('resources/views/store_templates/layout.blade.php'));

        $this->assertSame(
            1,
            substr_count($layout, 'store_templates._partials.story-cta-policy'),
            'The layout must include the story CTA policy exactly once.'
        );
    }

    /** Every retained theme's own viewer must defer to the shared policy. */
    public function test_every_retained_theme_defers_to_the_shared_policy(): void
    {
        $themes = KeptTemplates::MODERN;
        $offenders = [];

        foreach ($themes as $theme) {
            $js = base_path('../assets/store_templates/'.$theme.'/js/script.js');
            if (! is_file($js)) {
                continue;
            }
            $src = file_get_contents($js);
            if (str_contains($src, 'cta_url') && ! str_contains($src, 'mbStoryCta')) {
                $offenders[] = $theme;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These themes still render a story CTA without the shared destination policy: '.implode(', ', $offenders)
        );
    }

    /** A differing hash is a real destination (an anchor to a section/category). */
    public function test_the_same_page_check_respects_the_hash(): void
    {
        $this->assertStringContainsString(
            'window.location.hash',
            $this->partial,
            'An anchor destination must still count as navigation.'
        );
    }

    public function test_only_external_destinations_open_a_new_tab(): void
    {
        $this->assertStringContainsString(
            'isExternal',
            $this->partial,
            'Same-origin story destinations must navigate in place, not spawn a tab onto the store itself.'
        );
        $this->assertMatchesRegularExpression(
            "/if \(isExternal\(url\)\)\s*\{\s*anchor\.target = '_blank';/",
            $this->partial,
            'target=_blank must be set only for external destinations.'
        );
        $this->assertStringContainsString(
            "anchor.removeAttribute('target')",
            $this->partial,
            'A same-origin destination must have any inherited target removed.'
        );
    }

    /** An external CTA that opens a tab must not leak the opener. */
    public function test_external_destinations_keep_noopener(): void
    {
        $this->assertStringContainsString("anchor.rel = 'noopener'", $this->partial);
    }
}
