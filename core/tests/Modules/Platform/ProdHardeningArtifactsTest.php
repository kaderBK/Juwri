<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * P9-3: the production deployment artifacts for the ratified shared/cPanel-Apache
 * stack — the cPanel doc-root guide + the env-hardening checklist. Guards that
 * they exist and keep covering the load-bearing invariants (so they can't be
 * silently dropped or gutted). The live prod curl is the owner's at deploy time.
 */
class ProdHardeningArtifactsTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(base_path()); // core/ -> repo root
    }

    public function test_cpanel_doc_root_guide_exists_and_covers_the_essentials(): void
    {
        $path = $this->repoRoot().'/deploy/cpanel/README-doc-root.md';
        $body = @file_get_contents($path);
        $this->assertNotFalse($body, 'deploy/cpanel/README-doc-root.md should exist');

        // relies on the shipped .htaccess + AllowOverride, protects core/, and
        // ships the live verification probes.
        $this->assertStringContainsString('AllowOverride All', $body);
        $this->assertStringContainsString('.htaccess', $body);
        $this->assertStringContainsString('/core/.env', $body);
        $this->assertStringContainsString('core/plugins/', $body, 'must note plugin assets stay served');
    }

    public function test_prod_hardening_checklist_exists_and_covers_the_invariants(): void
    {
        $path = $this->repoRoot().'/deploy/PROD-HARDENING-CHECKLIST.md';
        $body = @file_get_contents($path);
        $this->assertNotFalse($body, 'deploy/PROD-HARDENING-CHECKLIST.md should exist');

        foreach ([
            'APP_DEBUG=false',        // P0-6 debug guard
            'SESSION_SECURE_COOKIE=true', // hardened cookies
            'allowed_origins',        // CORS not '*'
            'CSP_ENFORCE=true',       // ties to P9-2
            'config:cache',           // build step
        ] as $needle) {
            $this->assertStringContainsString($needle, $body, "checklist must cover: $needle");
        }
    }
}
