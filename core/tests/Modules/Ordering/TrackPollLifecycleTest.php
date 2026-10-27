<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Illuminate\Support\Facades\Blade;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * T2 — one poller per tab, visibility-gated, terminal-stop, live token.
 *
 * The 17 themes each ran their own 5s status setInterval AND the prep-eta partial
 * ran a second 15s interval — no terminal stop, no visibility handling, so a
 * finished order (or a hidden tab) polled forever. All 17 now register with the
 * shared _partials.track-poll, which owns the single lifecycle.
 */
class TrackPollLifecycleTest extends TestCase
{
    private const THEMES = KeptTemplates::MODERN;

    private function partial(string $name): string
    {
        return file_get_contents(resource_path("views/store_templates/_partials/{$name}.blade.php"));
    }

    private function track(string $theme): string
    {
        return file_get_contents(resource_path("views/store_templates/{$theme}/track.blade.php"));
    }

    public function test_shared_poller_owns_visibility_terminal_and_live_updates(): void
    {
        $p = $this->partial('track-poll');
        $this->assertStringContainsString('window.mbTrackPoll', $p);
        $this->assertStringContainsString("addEventListener('visibilitychange'", $p);
        // Slow to 30s when hidden, do not stop (must still catch "ready").
        $this->assertStringContainsString('arm(30000)', $p);
        $this->assertStringContainsString('document.hidden', $p);
        // Terminal statuses stop the poll.
        $this->assertMatchesRegularExpression('/TERMINAL\s*=\s*\{\s*completed:\s*1,\s*cancelled:\s*1,\s*delivered:\s*1/', $p);
        // Live prep-eta + board token, painted via textContent.
        $this->assertStringContainsString("getElementById('orderTokenNumber')", $p);
        $this->assertStringContainsString("getElementById('prepEtaText')", $p);
        $this->assertStringContainsString('.textContent =', $p);
        // Nonce present on the inline script.
        $this->assertStringContainsString('csp_nonce()', $p);
        // Compiles as valid Blade.
        Blade::compileString($p);
    }

    public function test_all_17_delegate_to_the_shared_poller_and_drop_own_intervals(): void
    {
        foreach (self::THEMES as $theme) {
            $src = $this->track($theme);
            $this->assertStringContainsString("@include('store_templates._partials.track-poll')", $src, "[$theme] missing poll include");
            $this->assertStringContainsString('window.mbTrackPoll(', $src, "[$theme] does not register with the shared poller");
            // The per-theme status setInterval must be gone.
            $this->assertStringNotContainsString('setInterval', $src, "[$theme] still runs its own interval");
        }
    }

    public function test_prep_eta_partial_no_longer_self_polls(): void
    {
        $p = $this->partial('prep-eta');
        $this->assertStringNotContainsString('setInterval', $p);
        $this->assertStringNotContainsString('fetch(', $p);
    }

    public function test_order_token_number_is_addressable_for_live_update(): void
    {
        $this->assertStringContainsString('id="orderTokenNumber"', $this->partial('order-token'));
    }
}
