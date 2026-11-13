<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** P4-19: Turbo's runtime progress-bar style needs the page's CSP nonce (found by the WebKit run: a report-only violation on every dashboard page). */
class TurboCspNonceTest extends TestCase
{
    public function test_the_owner_shell_publishes_the_csp_nonce_for_turbo(): void
    {
        $html = Blade::render('<x-ui.shell.legacy-styles />');

        $nonce = (string) app('csp-nonce');
        $this->assertNotSame('', $nonce);
        $this->assertStringContainsString('<meta name="csp-nonce" content="'.$nonce.'">', $html);
        $this->assertLessThan(strpos($html, 'turbo.min.js'), strpos($html, 'name="csp-nonce"'), 'the meta comes before Turbo loads');
    }
}
