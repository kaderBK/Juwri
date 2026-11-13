<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ApexCharts injects a runtime <style>; under the enforced dashboard CSP it needs the request nonce
 * (chart.nonce), or the style is blocked (found by the Phase 3 exit run: /analytics console errors).
 */
class ApexChartsCspNonceTest extends TestCase
{
    public function test_every_owner_chart_passes_the_csp_nonce(): void
    {
        foreach (['owner/reports/analytics', 'owner/dashboard/index'] as $view) {
            $src = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString("chart: { nonce: '{{ csp_nonce() }}'", $src, $view);
        }
    }
}
