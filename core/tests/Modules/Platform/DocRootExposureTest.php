<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * P0-7: the web-server deny rules that keep the Laravel app under core/ from
 * being served directly (Apache/.htaccess + IIS/web.config). Guards against
 * the rules being dropped. See rev/platform-v2/decisions/doc-root.md — the
 * production nginx equivalent is a deploy requirement, not enforced here.
 */
class DocRootExposureTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(base_path()); // core/ -> repo root
    }

    public function test_htaccess_blocks_core_source_and_secrets(): void
    {
        $htaccess = @file_get_contents($this->repoRoot().'/.htaccess');
        $this->assertNotFalse($htaccess, '.htaccess should exist at repo root');
        $this->assertStringContainsString('core/(app|bootstrap|config|database|lang|resources|routes|tests|storage|vendor|node_modules)', $htaccess);
        $this->assertStringContainsString('core/(\.env', $htaccess);
    }

    public function test_webconfig_blocks_core_source_and_secrets(): void
    {
        $webconfig = @file_get_contents($this->repoRoot().'/web.config');
        $this->assertNotFalse($webconfig, 'web.config should exist at repo root');
        $this->assertStringContainsString('Block core app source', $webconfig);
        $this->assertStringContainsString('Block core sensitive files', $webconfig);
    }

    /**
     * INTEGRATIONS-PACK F0-9: the integrations/ package sources and the rev/
     * working docs are top-level dirs under the repo-root docroot, so the same
     * deny rules that protect core/ must cover them — on every front door.
     */
    public function test_integrations_and_rev_are_denied_on_every_front_door(): void
    {
        $root = $this->repoRoot();

        $htaccess = (string) @file_get_contents($root.'/.htaccess');
        $this->assertMatchesRegularExpression('/\^\(integrations\/\(make-app\|n8n-nodes-juwri\|zapier-app\)\|rev\)/', $htaccess, '.htaccess must deny integrations/ and rev/');
        $this->assertStringContainsString('RewriteRule ^rev(/|$) - [F,L]', $htaccess, '.htaccess must deny rev/');
        $this->assertStringContainsString('RewriteRule ^integrations/. - [F,L]', $htaccess, '.htaccess must deny existing files/dirs under integrations/');
        // P6-133: /integrations and /integrations/odoo are app routes, never a blanket deny of the prefix.
        $this->assertStringNotContainsString('^(integrations|rev)', $htaccess);
        $this->assertStringContainsString('RewriteRule ^integrations/?$ index.php [L]', $htaccess);

        $webconfig = (string) @file_get_contents($root.'/web.config');
        $this->assertStringContainsString('Block integrations and rev', $webconfig);

        $router = (string) @file_get_contents($root.'/router.php');
        $this->assertStringContainsString('integrations/(make-app|n8n-nodes-juwri|zapier-app)|rev', $router, 'router.php (built-in server) must deny integrations/ and rev/');
        $this->assertStringContainsString('#^/rev(/|$)#', $router, 'router.php (built-in server) must deny rev/');
        $this->assertStringContainsString('#^/integrations/.#', $router, 'router.php (built-in server) must deny integration package files');
    }

    /** The deny list must cover every package dir under integrations/ but never the dashboard routes (/integrations/odoo, /integrations/switch). */
    public function test_every_integrations_package_dir_is_denied_but_dashboard_routes_stay_open(): void
    {
        $root = $this->repoRoot();

        $router = (string) @file_get_contents($root.'/router.php');
        $this->assertSame(1, preg_match("/preg_match\('(#[^']+#)'/", $router, $m));
        foreach (glob($root.'/integrations/*', GLOB_ONLYDIR) as $dir) {
            $this->assertSame(1, preg_match($m[1], '/integrations/'.basename($dir).'/package.json'), basename($dir).' must stay denied');
        }
        foreach (['/integrations', '/integrations/odoo', '/integrations/switch', '/integrations/switch/12'] as $route) {
            $this->assertSame(0, preg_match($m[1], $route), $route.' is an app route');
        }
    }

    /** P6-133: the legacy redirect to a foreign host must stay gone, and uploads with a PHP extension anywhere never run. */
    public function test_htaccess_has_no_foreign_redirect_and_blocks_multi_extension_uploads(): void
    {
        $htaccess = (string) @file_get_contents($this->repoRoot().'/.htaccess');
        $this->assertStringNotContainsString('aurmenu', $htaccess);
        $this->assertDoesNotMatchRegularExpression('#RewriteRule[^\n]*\s+https?://#', $htaccess, 'no RewriteRule may redirect to an absolute foreign URL');
        $this->assertStringContainsString('(php[0-9]*|phtml|phar|pht|phps)(\\.|$)', $htaccess, 'storage deny must catch evil.php.jpg');

        $webconfig = (string) @file_get_contents($this->repoRoot().'/web.config');
        $this->assertStringNotContainsString('(integrations|rev)', $webconfig);
    }

    /** P6-173: a Composer vendor/ folder uploaded to the web root is never served on any front door. */
    public function test_root_vendor_is_denied_on_every_front_door(): void
    {
        $root = $this->repoRoot();

        $htaccess = (string) @file_get_contents($root.'/.htaccess');
        $this->assertStringContainsString('RewriteRule ^vendor(/|$) - [F,L]', $htaccess, '.htaccess must deny a root vendor/');

        $webconfig = (string) @file_get_contents($root.'/web.config');
        $this->assertStringContainsString('Block root vendor', $webconfig, 'web.config must have a labelled rule denying it');
        $this->assertStringContainsString('^vendor(/|$)', $webconfig, 'web.config regex must match vendor/ and /vendor');

        $router = (string) @file_get_contents($root.'/router.php');
        $this->assertStringContainsString('#^/vendor(/|$)#', $router, 'router.php must deny a root vendor/');
        $denyRegex = '#^/vendor(/|$)#';
        $this->assertSame(1, preg_match($denyRegex, '/vendor'), 'bare /vendor must hit the deny');
        $this->assertSame(1, preg_match($denyRegex, '/vendor/'), 'trailing slash must hit the deny');
        $this->assertSame(1, preg_match($denyRegex, '/vendor/autoload.php'), 'nested vendor file must hit the deny');
        $this->assertSame(0, preg_match($denyRegex, '/vendorfoo'), 'a non-match like /vendorfoo stays out');
    }
}
