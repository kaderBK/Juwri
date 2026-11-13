<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P6-19 — Octane worker-safety static rules. A worker boots once and serves thousands of requests, so anything that
 * keeps request / tenant / user state in the process, or does heavy work at boot, leaks or stalls (P6-23: LocalizedRoutes
 * did per-boot work and stalled the worker). Source scan only, no application. Every allow-list entry carries a reason;
 * these rules guard the findings of the internal Octane audit.
 *
 * Rules: (1) no static property named after request/tenant/user state, (2) no env() outside config/, (3) provider
 * singletons do not capture request/auth/tenant in their factory closure, (4) singleton classes do not capture
 * app()/request()/auth() in their constructor, (5) no DB query or glob() in route files, (6) no DB query or glob() directly
 * in a provider register()/boot() (closures deferred to request time are allow-listed per file).
 */
class OctaneSafetyTest extends TestCase
{
    private const STATE_NAME = '/request|tenant|user|auth|session|account|visitor|customer|currentStore|currentLocale/i';

    /** @var array<string,string> relative path => reason; static properties whose name looks like request state but is not. */
    private const STATIC_ALLOW = [
        'app/Core/Services/Tenancy/TenantFill.php' => 'store id => account id memo, reset by RequestEpoch::id() stamp (bindToApp) and flushStores() at every job boundary',
        'app/Core/Http/Middleware/SetTenantContext.php' => '$nonTenantMiddleware = list of non-tenant gate MIDDLEWARE CLASS NAMES filled by ee providers at boot (BL-043); no request state',
        'app/Core/Services/Tenancy/TenantJobClasses.php' => '$tenant = list of tenant-scoped job CLASS NAMES filled by providers at boot; no request state',
        'app/Modules/Integrations/Services/Gbp/FakeGbpClient.php' => 'test double bound only in tests / E2E_STUBS; each test resets it',
    ];

    /** @var array<string,string> env() allowed outside config/ (E2E-only seeders / stub guards, never read in request code). */
    private const ENV_ALLOW = [
        'app/Providers/E2eStubServiceProvider.php' => 'boot-time guard: only active when local/testing AND E2E_STUBS',
        'app/Modules/Payments/Http/Controllers/User/PaymentMethods/StubController.php' => 'E2E stub guard; 404 in production',
        'app/Modules/Storefront/Database/Seeders/E2ePlatformSeeder.php' => 'seeder, runs in console only',
        'app/Modules/Storefront/Database/Seeders/E2eAdminSeeder.php' => 'seeder, runs in console only',
        'app/Services/Ai/E2eStubAiService.php' => 'E2E stub guard, local/testing only',
    ];

    /** @var array<string,string> route files / providers where a query or glob is acceptable. */
    private const BOOT_WORK_ALLOW = [
        'routes/web.php' => 'glob() of the route-file directories (a..f lane files): a cheap directory listing, once per boot; the route tree is registered once per worker',
        'app/Providers/ViewServiceProvider.php' => 'queries sit inside view-composer closures, evaluated per render, not at boot',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> relative paths */
    private static function phpFiles(string $dir): array
    {
        $base = self::root().'/'.$dir;
        if (! is_dir($base)) {
            return [];
        }
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[] = $dir.'/'.substr($f->getPathname(), strlen($base) + 1);
            }
        }
        sort($out);

        return $out;
    }

    /** Source without comments and string-free of docblocks, so prose does not trigger a rule. */
    private static function code(string $rel): string
    {
        $src = (string) file_get_contents(self::root().'/'.$rel);
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($t[1], "\n"));

                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /** Text of the balanced (...) starting at $open (index of "("). */
    private static function balanced(string $s, int $open): string
    {
        $depth = 0;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')' && --$depth === 0) {
                return substr($s, $open, $i - $open + 1);
            }
        }

        return substr($s, $open);
    }

    /** Text of the braces body of the method $name, or ''. */
    private static function method(string $s, string $name): string
    {
        if (! preg_match('/function\s+'.$name.'\s*\([^)]*\)[^{;]*\{/', $s, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $i = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        for ($n = strlen($s); $i < $n; $i++) {
            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}' && --$depth === 0) {
                return substr($s, $m[0][1], $i - $m[0][1] + 1);
            }
        }

        return '';
    }

    /** Bodies of closures inside $s are removed, so only work done directly at call time remains. */
    private static function withoutClosures(string $s): string
    {
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            if (preg_match('/\G(?:static\s+)?(?:function\s*\([^)]*\)[^{]*|fn\s*\([^)]*\)[^=]*=>)/', $s, $m, 0, $i) && $m[0] !== '') {
                $j = $i + strlen($m[0]);
                if ($s[$j - 1] === '>' || ($s[$j] ?? '') === ' ' && false) {
                    // arrow fn: skip to the end of the enclosing argument
                    $depth = 0;
                    for (; $j < $n; $j++) {
                        if (in_array($s[$j], ['(', '[', '{'], true)) {
                            $depth++;
                        } elseif (in_array($s[$j], [')', ']', '}'], true)) {
                            if ($depth-- === 0) {
                                break;
                            }
                        } elseif ($s[$j] === ',' && $depth === 0) {
                            break;
                        } elseif ($s[$j] === ';' && $depth === 0) {
                            break;
                        }
                    }
                    $i = $j - 1;

                    continue;
                }
                if (($s[$j] ?? '') === '{') {
                    $depth = 0;
                    for (; $j < $n; $j++) {
                        if ($s[$j] === '{') {
                            $depth++;
                        } elseif ($s[$j] === '}' && --$depth === 0) {
                            break;
                        }
                    }
                    $i = $j;

                    continue;
                }
            }
            $out .= $s[$i];
        }

        return $out;
    }

    public function test_no_static_property_holds_request_tenant_or_user_state(): void
    {
        $bad = [];
        foreach (self::phpFiles('app') as $rel) {
            if (isset(self::STATIC_ALLOW[$rel])) {
                continue;
            }
            $code = self::code($rel);
            if (preg_match_all('/^\s*(?:(?:private|protected|public|readonly)\s+)*static\s+(?:\??[\w\\\\|]+\s+)?\$(\w+)/m', $code, $m)) {
                foreach ($m[1] as $name) {
                    if (preg_match(self::STATE_NAME, $name)) {
                        $bad[] = $rel.'::$'.$name;
                    }
                }
            }
        }
        $this->assertSame([], $bad, "Static properties named after request/tenant/user state (a worker would serve one request's value to the next). Use the container (scoped) or a RequestEpoch-stamped memo, or allow-list with a reason:\n".implode("\n", $bad));
    }

    public function test_env_is_only_read_in_config(): void
    {
        $bad = [];
        foreach (array_merge(self::phpFiles('app'), self::phpFiles('routes'), self::phpFiles('bootstrap')) as $rel) {
            if (isset(self::ENV_ALLOW[$rel])) {
                continue;
            }
            if (preg_match('/(?<![\w>:$])(?<!function )env\s*\(/', self::code($rel))) {
                $bad[] = $rel;
            }
        }
        $this->assertSame([], $bad, "env() outside config/ returns null once config is cached:\n".implode("\n", $bad));
    }

    public function test_provider_singletons_do_not_capture_request_auth_or_tenant(): void
    {
        $bad = [];
        foreach (self::phpFiles('app') as $rel) {
            if (! str_contains($rel, 'Provider')) {
                continue;
            }
            $code = self::code($rel);
            preg_match_all('/->(?:singleton|singletonIf)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$text, $off]) {
                $call = self::balanced($code, $off + strlen($text) - 1);
                if (preg_match('/\brequest\s*\(|\bauth\s*\(|\bAuth::|\bRequest::|\$app\s*\[\s*[\'"]request|->make\s*\(\s*(?:\\\\?Illuminate\\\\Http\\\\)?Request::class|TenantContext::class|\bsession\s*\(|\bSession::/', $call)) {
                    $bad[] = $rel.': '.trim(preg_replace('/\s+/', ' ', substr($call, 0, 90)));
                }
            }
        }
        $this->assertSame([], $bad, "A singleton built once per worker captures request/auth/tenant. Bind it scoped() or add it to octane.flush:\n".implode("\n", $bad));
    }

    public function test_singleton_constructors_do_not_capture_app_request_or_auth(): void
    {
        $classes = [];
        foreach (self::phpFiles('app') as $rel) {
            if (! str_contains($rel, 'Provider')) {
                continue;
            }
            if (preg_match_all('/->singleton(?:If)?\s*\(\s*([\\\\\w]+)::class/', self::code($rel), $m)) {
                foreach ($m[1] as $short) {
                    $classes[ltrim($short, '\\')] = $rel;
                }
            }
        }
        $byShort = [];
        foreach (self::phpFiles('app') as $rel) {
            $byShort[basename($rel, '.php')][] = $rel;
        }
        $bad = [];
        foreach ($classes as $short => $provider) {
            $short = basename(str_replace('\\', '/', $short));
            foreach ($byShort[$short] ?? [] as $rel) {
                $ctor = self::method(self::code($rel), '__construct');
                if ($ctor !== '' && preg_match('/\bapp\s*\(|\brequest\s*\(|\bauth\s*\(|\bAuth::|\bRequest::|\bsession\s*\(|\bSession::|\bTenantContext::/', $ctor)) {
                    $bad[] = $rel.' (singleton in '.$provider.')';
                }
            }
        }
        $this->assertSame([], $bad, "Singleton constructor resolves app()/request()/auth() once per worker; resolve lazily inside methods:\n".implode("\n", $bad));
    }

    public function test_route_files_do_no_database_work(): void
    {
        $bad = [];
        foreach (self::phpFiles('routes') as $rel) {
            if (isset(self::BOOT_WORK_ALLOW[$rel])) {
                continue;
            }
            if ($hit = self::heavy(self::code($rel))) {
                $bad[] = $rel.': '.$hit;
            }
        }
        $this->assertSame([], $bad, "Route files run at boot (and once per locale under LocalizedRoutes):\n".implode("\n", $bad));
    }

    public function test_providers_do_no_database_or_filesystem_work_in_register_or_boot(): void
    {
        $bad = [];
        foreach (self::phpFiles('app') as $rel) {
            if (! str_contains($rel, 'Provider') || isset(self::BOOT_WORK_ALLOW[$rel])) {
                continue;
            }
            $code = self::code($rel);
            foreach (['register', 'boot'] as $method) {
                $body = self::withoutClosures(self::method($code, $method));
                if ($hit = self::heavy($body)) {
                    $bad[] = $rel.'::'.$method.'(): '.$hit;
                }
            }
        }
        $this->assertSame([], $bad, "Provider register()/boot() runs on every worker boot; defer to a closure or to BeginRequest:\n".implode("\n", $bad));
    }

    /** First database / filesystem-scan call found in $code, or null. */
    private static function heavy(string $code): ?string
    {
        if (preg_match('/\bDB::(?!listen|connection\s*\(\s*\)\s*->\s*getDriverName)\w+|\b(?!Route\b|Config\b|Cache\b|Hooks\b)[A-Z]\w+::(?:query|where|all|first|find|count|pluck|exists)\s*\(|\bSchema::(?:has|get)\w*|\bglob\s*\(|\bscandir\s*\(|\bFile::(?:files|allFiles|directories|glob)\s*\(|\bFinder::create\s*\(/', $code, $m)) {
            return trim($m[0]);
        }

        return null;
    }
}
