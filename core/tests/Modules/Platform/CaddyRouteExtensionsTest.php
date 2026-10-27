<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * P4-17 ZAP scan of the Docker stack: Caddy's "sensitive extension" rule answered an empty 404 for every path ending
 * in .json / .md, so Laravel routes like the storefront's boot.json / snapshot.json, the store manifest.json and
 * /api/v1/openapi.json never reached PHP. The rule now only matches files that exist on disk (composer.json, *.md, …).
 */
class CaddyRouteExtensionsTest extends TestCase
{
    public function test_the_sensitive_extension_rule_only_matches_existing_files(): void
    {
        $file = dirname(base_path()).'/docker/Caddyfile';
        if (! is_file($file)) {
            $this->markTestSkipped('no docker/Caddyfile in this tree');
        }
        $caddy = (string) file_get_contents($file);
        $this->assertMatchesRegularExpression('/@sensitive \{\s*(#[^\n]*\n\s*)*file\s*\n\s*path_regexp sens /', $caddy);
    }

    public function test_svg_files_are_served_with_nosniff_and_a_sandbox_csp(): void
    {
        $root = dirname(base_path());
        foreach (['docker/Caddyfile', '.htaccess'] as $rel) {
            if (! is_file($root.'/'.$rel)) {
                $this->markTestSkipped("no {$rel} in this tree");
            }
            $conf = (string) file_get_contents($root.'/'.$rel);
            $this->assertStringContainsString("default-src 'none'; style-src 'unsafe-inline'; sandbox", $conf, $rel);
            $this->assertStringContainsString('nosniff', $conf, $rel);
        }
    }

    public function test_svg_object_storage_puts_carry_content_type_and_disposition(): void
    {
        $svg = \Juwri\Modules\Media\Services\StorageService::s3PutOptions('logo.svg');
        $this->assertSame('image/svg+xml', $svg['ContentType']);
        $this->assertSame('attachment', $svg['ContentDisposition']);
        $this->assertSame(['visibility' => 'public'], \Juwri\Modules\Media\Services\StorageService::s3PutOptions('a.webp'));
    }
}
