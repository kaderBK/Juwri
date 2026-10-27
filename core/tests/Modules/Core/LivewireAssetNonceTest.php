<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * P3-13: ResellerParityC1Test failed only in a broad run with an un-nonced `<style >`. Shared state: Livewire (pulled
 * in by Pulse) remembers "a component rendered this request" in a static flag that only its own test helpers reset,
 * so after PulseTest rendered the dashboard every later HTML response got Livewire's assets auto-injected — and those
 * carried no nonce, because Livewire takes it from Vite::cspNonce(), which nothing set. Both halves are fixed: Vite
 * now carries the request's CSP nonce (so the real Pulse page works under the enforced dashboard CSP) and the base
 * TestCase resets the static flag with the application.
 */
class LivewireAssetNonceTest extends TestCase
{
    use RefreshDatabase;

    public function test_vite_carries_the_request_csp_nonce(): void
    {
        $this->assertNotSame('', csp_nonce());
        $this->assertSame(csp_nonce(), Vite::cspNonce());
    }

    public function test_livewire_auto_injected_assets_carry_the_request_nonce(): void
    {
        Route::middleware('web')->get('/_p313/livewire-probe', fn () => response('<!DOCTYPE html><html><head><title>x</title></head><body>x</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true; // as if a Livewire card rendered

        $html = (string) $this->get('/_p313/livewire-probe')->assertOk()->getContent();

        preg_match_all('/<(style|script)\b[^>]*>/i', $html, $tags);
        $this->assertNotEmpty(array_filter($tags[0], fn ($t) => str_starts_with(strtolower($t), '<style')), 'Livewire styles injected');
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.csp_nonce().'"', $tag, "un-nonced injected tag $tag");
        }
    }

    public function test_a_the_rendered_component_flag_is_left_set_by_this_test(): void
    {
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true;
        $this->assertTrue(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest);
    }

    public function test_b_the_next_test_starts_without_it(): void
    {
        // Runs after test_a_… in the same process: the base TestCase reset the flag when that test's app was torn down.
        $this->assertFalse(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest);
        $this->assertFalse(SupportAutoInjectedAssets::$forceAssetInjection);
    }
}
