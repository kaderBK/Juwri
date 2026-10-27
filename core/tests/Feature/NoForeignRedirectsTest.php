<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * P6-130: the web-server rules and route files must never send a visitor to a host that is not ours. Upstream shipped
 * `.htaccess` with a 301 from /grape_Leaves to a third-party menu host; this parses every redirect we ship and fails on
 * any absolute target whose host is not one of our own placeholders (the request host / the app URL, filled at runtime).
 */
class NoForeignRedirectsTest extends TestCase
{
    /** Hosts an absolute redirect may name: runtime placeholders for our own host, never a literal domain. */
    private const ALLOWED_HOSTS = ['%{HTTP_HOST}', '%{SERVER_NAME}', '{HTTP_HOST}', '{host}', '{http.request.host}', '{hostport}', '$host', '$http_host', '$server_name'];

    /** Old upstream brand domains that must not appear in any shipped server rule or route file. */
    private const OLD_BRAND = '/a(?:u)rmenu|m(?:e)nu[\s_-]?b(?:a)r[\s_-]?c(?:o)de|q(?:u)ick[\s_-]?(?:qr|cms)|b(?:y)lancer/i';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> relative path => contents */
    private static function files(): array
    {
        $root = self::root();
        $paths = array_merge(
            ['.htaccess', 'web.config', 'router.php', 'docker/Caddyfile', 'core/public/.htaccess', 'core/public/web.config'],
            array_map(fn (string $p) => substr($p, strlen($root) + 1), array_merge(
                glob($root.'/{,docker/,deploy/,deploy/*/,core/deploy/,docs/}{*.nginx,nginx*.conf,*.nginx.conf,nginx/*.conf,Caddyfile*}', GLOB_BRACE) ?: [],
                self::phpUnder($root.'/core/routes'),
                glob($root.'/core/{app,ee}/Modules/*/Routes/*.php', GLOB_BRACE) ?: [],
            )),
        );
        $out = [];
        foreach (array_unique($paths) as $rel) {
            if (is_file($root.'/'.$rel)) {
                $out[$rel] = (string) file_get_contents($root.'/'.$rel);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function phpUnder(string $dir): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $found[] = $f->getPathname();
            }
        }

        return $found;
    }

    /**
     * Every absolute redirect target in one file, by syntax: Apache RewriteRule/Redirect/RedirectMatch, IIS
     * <action type="Redirect" url>, Caddy redir, nginx return 30x / rewrite, PHP Location headers and Laravel redirects.
     *
     * @return list<string>
     */
    public static function redirectTargets(string $rel, string $text): array
    {
        $patterns = [
            '/^\s*RewriteRule\s+\S+\s+([a-z][a-z0-9+.-]*:\/\/\S+)/mi',
            '/^\s*Redirect(?:Match|Permanent|Temp)?\s+(?:\S+\s+)?\S+\s+([a-z][a-z0-9+.-]*:\/\/\S+)/mi',
            '/<action\b[^>]*\burl\s*=\s*"([a-z][a-z0-9+.-]*:\/\/[^"]+)"/i',
            '/^\s*redir\s+(?:\S+\s+)?([a-z][a-z0-9+.-]*:\/\/\S+)/mi',
            '/\breturn\s+30[12378]\s+([a-z][a-z0-9+.-]*:\/\/[^;\s]+)/i',
            '/^\s*rewrite\s+\S+\s+([a-z][a-z0-9+.-]*:\/\/\S+)/mi',
            '/Location:\s*([a-z][a-z0-9+.-]*:\/\/[^\'"\s]+)/i',
            '/(?:Route::(?:permanentR|r)edirect\s*\([^,]+,|redirect\s*\(|->away\s*\(|->to\s*\()\s*[\'"]([a-z][a-z0-9+.-]*:\/\/[^\'"]+)/i',
        ];
        $targets = [];
        foreach ($patterns as $re) {
            preg_match_all($re, $text, $m);
            array_push($targets, ...$m[1]);
        }

        return $targets;
    }

    /** The host part of an absolute target, kept verbatim so placeholders like %{HTTP_HOST} survive. */
    public static function host(string $target): string
    {
        $rest = (string) preg_replace('/^[a-z][a-z0-9+.-]*:\/\//i', '', $target);
        if (preg_match('/^(%\{[A-Z_]+\}|\{[a-z._]+\}|\$[a-z_]+)/i', $rest, $m)) {
            return $m[1];
        }

        return strtolower((string) preg_replace('/[\/?#:\[{$%].*$/s', '', $rest));
    }

    public function test_no_shipped_redirect_points_at_a_host_that_is_not_ours(): void
    {
        $files = self::files();
        $this->assertArrayHasKey('.htaccess', $files);
        $this->assertArrayHasKey('docker/Caddyfile', $files);
        $this->assertGreaterThan(20, count($files), 'route files were scanned');

        $foreign = [];
        foreach ($files as $rel => $text) {
            foreach (self::redirectTargets($rel, $text) as $target) {
                if (! in_array(self::host($target), self::ALLOWED_HOSTS, true)) {
                    $foreign[] = $rel.': '.$target;
                }
            }
        }
        $this->assertSame([], $foreign, 'absolute redirects to a foreign host');
    }

    public function test_the_old_brand_is_gone_from_the_server_rules_and_routes(): void
    {
        $hits = [];
        foreach (self::files() as $rel => $text) {
            if (preg_match(self::OLD_BRAND, $text, $m)) {
                $hits[] = $rel.': '.$m[0];
            }
        }
        $this->assertSame([], $hits);
        $this->assertStringNotContainsString('grape_Leaves', self::files()['.htaccess']);
    }

    public function test_the_parser_catches_each_syntax_and_honours_the_allow_list(): void
    {
        $cases = [
            '.htaccess' => "RewriteRule ^/?old https://evil.example/x? [R=301,L]\nRedirect 301 /a https://evil.example/\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]",
            'web.config' => '<action type="Redirect" url="https://evil.example/{R:1}" />',
            'Caddyfile' => "redir @old https://evil.example{uri} 301\nredir https://{host}{uri}",
            'site.nginx' => 'return 301 https://evil.example$request_uri; return 301 https://$host$request_uri;',
            'router.php' => "header('Location: https://evil.example/');",
            'web.php' => "Route::redirect('/a', 'https://evil.example/'); return redirect()->away('https://evil.example');",
        ];
        foreach ($cases as $rel => $text) {
            $hosts = array_map(fn (string $t) => self::host($t), self::redirectTargets($rel, $text));
            $this->assertContains('evil.example', $hosts, $rel);
            foreach ($hosts as $h) {
                $this->assertTrue($h === 'evil.example' || in_array($h, self::ALLOWED_HOSTS, true), $rel.': '.$h);
            }
        }
    }
}
