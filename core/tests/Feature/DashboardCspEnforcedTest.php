<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Tests\TestCase;

/**
 * P4-17 follow-up (ZAP Medium "CSP header not set"): the dashboard / admin / reseller / staff policy is ENFORCED by
 * default; every inline <script>/<style> of those views carries the request nonce (a static ratchet, so a new one
 * cannot slip in un-nonced); the platform analytics hosts are allowed only when that analytics is on; the dashboard
 * fonts are self-hosted (no Google Fonts request for Instrument Sans / Syne).
 */
class DashboardCspEnforcedTest extends TestCase
{
    private function header(): string
    {
        $res = new AddDashboardCspHeaders()->handle(Request::create('/dashboard', 'GET'), fn () => new Response('ok'));
        $this->assertFalse($res->headers->has('Content-Security-Policy-Report-Only'));

        return (string) $res->headers->get('Content-Security-Policy');
    }

    public function test_the_dashboard_policy_is_enforced_by_default(): void
    {
        $this->assertStringContainsString("script-src 'self' 'unsafe-eval' 'nonce-", $this->header());
    }

    public function test_analytics_hosts_only_when_platform_analytics_is_on(): void
    {
        config(['settings.google_analytics' => ['status' => '0', 'measurement_id' => '']]);
        $this->assertStringNotContainsString('googletagmanager', $this->header());
        config(['settings.google_analytics' => ['status' => '1', 'measurement_id' => 'G-TEST1']]);
        $this->assertMatchesRegularExpression('#script-src [^;]*https://www\.googletagmanager\.com#', $this->header());
        $this->assertMatchesRegularExpression('#connect-src [^;]*https://www\.google-analytics\.com#', $this->header());
    }

    public function test_every_inline_script_and_style_of_the_privileged_views_is_nonced(): void
    {
        $bad = [];
        $roots = [resource_path('views'), app_path('Modules')];
        foreach (EditionPackages::all() as $package) {
            $roots[] = $package['path'].'/Modules'; // the installed editions' views
        }
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = (string) $file;
                if (! str_ends_with($path, '.blade.php') || preg_match('#/(store_templates|emails|mail)/|storefront|mail[^/]*\.blade\.php$#i', $path)) {
                    continue;
                }
                foreach (explode("\n", (string) file_get_contents($path)) as $i => $line) {
                    // a real tag starts the line (comments, translated strings and JS strings are not tags)
                    if (preg_match('#^\s*<(script|style)\b([^>]*)>#', $line, $m) && ! preg_match('#src=|nonce|json|text/template#', $m[2])) {
                        $bad[] = str_replace(base_path().'/', '', $path).':'.($i + 1);
                    }
                }
            }
        }
        $this->assertSame([], $bad, 'inline tags without nonce="{{ csp_nonce() }}" (blocked by the enforced policy)');
    }

    public function test_dashboard_fonts_are_self_hosted(): void
    {
        foreach (['components/ui/shell/head', 'components/ui/auth', 'admin/includes/styles', 'studio/index'] as $view) {
            $src = (string) file_get_contents(resource_path('views/'.$view.'.blade.php'));
            $this->assertDoesNotMatchRegularExpression('#fonts\.googleapis\.com/css2\?family=(Instrument|Syne|Alexandria)#', $src, $view);
            $this->assertStringContainsString("asset('assets/fonts/fonts.css')", $src, $view);
        }
    }

    public function test_alexandria_is_self_hosted_with_its_licence(): void
    {
        $fonts = dirname(base_path()).'/assets/fonts/';
        $css = (string) file_get_contents($fonts.'fonts.css');
        $this->assertMatchesRegularExpression("#font-family: 'Alexandria';[^}]*url\\('alexandria-var\\.woff2'\\) format\\('woff2'\\)#s", $css);
        $this->assertSame('wOF2', substr((string) file_get_contents($fonts.'alexandria-var.woff2'), 0, 4));
        $this->assertStringContainsString('SIL OPEN FONT LICENSE', strtoupper((string) file_get_contents($fonts.'ALEXANDRIA-OFL.txt')));
    }

    public function test_tawk_and_turnstile_hosts_only_when_configured(): void
    {
        config(['settings.tawk_to' => ['status' => '0', 'chat_link' => ''], 'settings.abuse_turnstile_site_key' => '']);
        $this->assertStringNotContainsString('tawk.to', $this->header());
        $this->assertStringNotContainsString('challenges.cloudflare.com', $this->header());
        config(['settings.tawk_to' => ['status' => '1', 'chat_link' => 'https://tawk.to/chat/abc/def'], 'settings.abuse_turnstile_site_key' => 'sitekey']);
        $this->assertMatchesRegularExpression('#script-src [^;]*https://embed\.tawk\.to#', $this->header());
        $this->assertMatchesRegularExpression('#connect-src [^;]*wss://\*\.tawk\.to#', $this->header());
        $this->assertMatchesRegularExpression('#frame-src [^;]*https://challenges\.cloudflare\.com#', $this->header());
    }
}
