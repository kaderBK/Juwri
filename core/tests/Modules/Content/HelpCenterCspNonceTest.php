<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every inline <style>/<script> on the help-center shell carries the request's
 * CSP nonce, so the enforced policy does not block it (found by the Phase 3 exit run).
 */
class HelpCenterCspNonceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_help_center_inline_blocks_carry_the_csp_nonce(): void
    {
        $html = $this->get('/help-center')->assertOk()->getContent();

        $this->assertSame(0, preg_match('/<style(?![^>]*\snonce=)/', $html), 'un-nonced <style>');
        $this->assertSame(0, preg_match('/<script(?![^>]*\s(nonce|src)=)(?![^>]*ld\+json)/', $html), 'un-nonced <script>');
        $this->assertStringContainsString('<style nonce="'.csp_nonce().'"', $html);
    }
}
