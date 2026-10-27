<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Juwri\Core\Editions\EditionPackages;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The Community build is published without the enterprise directory. Anything the Community boot path names has to resolve
 * to a file the Community build ships, or be guarded by a class_exists() check.
 *
 * The private repo cannot see this at run time (the enterprise code is present, so every class loads even
 * when the edition is unlicensed). The 13i mirror build found it the hard way: AppServiceProvider aliased
 * App\Mails\TakedownConfirmation, a legacy name of a class that exists only in the enterprise code, and the app crashed
 * at provider boot. So this test is static and file based (no app boot, no autoloader): it resolves names
 * through composer's PSR-4 map plus the legacy alias map and fails when a name resolves ONLY to the enterprise code.
 *
 * Checked: (1) every class_alias() loop over a literal name list (target = prefix . name) in app/, config/,
 * routes/, bootstrap/, database/ must be guarded by class_exists(prefix . name) when a target is ee-only;
 * (2) the class names (::class, use imports, string FQCNs) in the provider and alias registrations:
 * config/app.php and app/Providers/AppServiceProvider.php.
 */
class CommunityResolvesWithoutEeTest extends TestCase
{
    /** Spelled out at run time: this Community file must not carry the enterprise names it looks for. */
    private static function eePrefix(): string
    {
        return 'Juwri\\'.'Ee\\';
    }

    private static function eeTestPrefix(): string
    {
        return 'Tests\\'.'Ee\\';
    }

    private static function eeDir(): string
    {
        return 'e'.'e/';
    }

    private const SCAN_DIRS = ['app', 'config', 'routes', 'bootstrap', 'database'];

    private const REGISTRATION_FILES = ['config/app.php', 'app/Providers/AppServiceProvider.php'];

    public function test_class_alias_loops_do_not_alias_ee_only_classes_unguarded(): void
    {
        $bad = [];
        foreach ($this->communityPhpFiles() as $file) {
            $src = (string) file_get_contents($file);
            if (! str_contains($src, 'class_alias(')) {
                continue;
            }
            // class_alias('Prefix\\'.$var, ...) inside foreach ([...literal names...] as $var)
            if (! preg_match_all('/class_alias\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\.\s*\$(\w+)/', $src, $calls, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($calls as [$whole, $rawPrefix, $var]) {
                $prefix = str_replace('\\\\', '\\', $rawPrefix);
                if (! preg_match('/foreach\s*\(\s*\[(.*?)\]\s+as\s+\$'.$var.'\b/s', $src, $loop)) {
                    $bad[] = $this->rel($file).': class_alias target built from $'.$var.' without a literal name list (cannot be checked)';

                    continue;
                }
                preg_match_all('/\'([^\']+)\'/', $loop[1], $names);
                $guard = '/class_exists\(\s*\''.preg_quote($rawPrefix, '/').'\'\s*\.\s*\$'.$var.'\s*\)/';
                foreach ($names[1] as $name) {
                    $fqcn = $prefix.$name;
                    if ($this->resolves($fqcn) === 'ee' && ! preg_match($guard, $src)) {
                        $bad[] = $this->rel($file).': class_alias target '.$fqcn.' resolves only to enterprise code and is not guarded by class_exists()';
                    }
                }
            }
        }

        $this->assertSame([], $bad, "Community boot would crash without the enterprise code:\n".implode("\n", $bad));
    }

    public function test_provider_and_alias_registrations_name_no_ee_only_class(): void
    {
        $bad = [];
        foreach (self::REGISTRATION_FILES as $relative) {
            $file = dirname(__DIR__, 2).'/'.$relative;
            $this->assertFileExists($file);
            foreach ($this->classNamesIn((string) file_get_contents($file)) as $fqcn) {
                if ($this->resolves($fqcn) === 'ee') {
                    $bad[] = $relative.': '.$fqcn;
                }
            }
        }

        $this->assertSame([], $bad, "These names resolve only to enterprise code (not shipped in Community):\n".implode("\n", $bad));
    }

    /** The scanner itself: the failing shape from the 13i mirror is recognised. */
    public function test_resolver_sees_the_takedown_alias_as_ee_only(): void
    {
        if (EditionPackages::aliasFiles() === []) {
            $this->markTestSkipped('no edition package installed: its aliases cannot be resolved here (Community build)');
        }
        $this->assertSame('ee', $this->resolves('App\\Mails\\TakedownConfirmation'));
        $this->assertSame('ee', $this->resolves('App\\Mails\\AppReviewResult')); // AppStore review queue is ee (13h)
        $this->assertSame('community', $this->resolves('App\\Providers\\AppServiceProvider'));
        $this->assertSame('ee', $this->resolves(self::eePrefix().'Anything\\AtAll'));
    }

    /** @return list<string> */
    private function classNamesIn(string $src): array
    {
        $namespace = '';
        if (preg_match('/^namespace\s+([\w\\\\]+);/m', $src, $m)) {
            $namespace = $m[1];
        }
        $imports = [];
        $names = [];
        if (preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $src, $uses, PREG_SET_ORDER)) {
            foreach ($uses as $u) {
                $imports[$u[2] ?? substr(strrchr('\\'.$u[1], '\\'), 1)] = $u[1];
                $names[] = $u[1];
            }
        }
        if (preg_match_all('/(\\\\?[A-Za-z_][\w\\\\]*)::class/', $src, $refs)) {
            foreach ($refs[1] as $ref) {
                if (str_starts_with($ref, '\\')) {
                    $names[] = ltrim($ref, '\\');

                    continue;
                }
                $first = explode('\\', $ref)[0];
                if (isset($imports[$first])) {
                    $names[] = $imports[$first].substr($ref, strlen($first));
                } elseif (! in_array($ref, ['self', 'static', 'parent'], true) && $namespace !== '') {
                    $names[] = $namespace.'\\'.$ref;
                }
            }
        }
        if (preg_match_all('/\'((?:[A-Za-z_]\w*\\\\\\\\)+[A-Za-z_]\w*)\'/', $src, $strings)) {
            foreach ($strings[1] as $s) {
                $names[] = str_replace('\\\\', '\\', $s);
            }
        }

        return array_values(array_unique($names));
    }

    /** 'ee' (only the enterprise code has it), 'community' (a Community file has it) or 'unknown' (vendor, framework, etc.). */
    private function resolves(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $aliases = $this->aliases();
        for ($i = 0; $i < 5 && isset($aliases[$fqcn]); $i++) {
            $fqcn = $aliases[$fqcn];
        }
        if (str_starts_with($fqcn, self::eePrefix()) || str_starts_with($fqcn, self::eeTestPrefix())) {
            return 'ee';
        }
        foreach ($this->psr4() as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix)) {
                $path = dirname(__DIR__, 2).'/'.$dir.str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';

                return is_file($path) ? 'community' : 'unknown';
            }
        }

        return 'unknown';
    }

    /** @return array<string, string> old FQCN => current FQCN */
    private function aliases(): array
    {
        static $aliases = null;
        if ($aliases === null) {
            // P1-13 13i: Community's alias map + the installed edition packages' alias files (an alias whose target
            // moved into a package lives in that package's file).
            $aliases = [];
            foreach ([dirname(__DIR__, 2).'/app/Core/Support/legacy_class_aliases.php', ...EditionPackages::aliasFiles()] as $file) {
                $aliases += is_file($file) ? (require $file)['aliases'] : [];
            }
        }

        return $aliases;
    }

    /** @return array<string, string> Community PSR-4 prefixes (never the ee ones), longest first */
    private function psr4(): array
    {
        static $map = null;
        if ($map === null) {
            $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
            $map = [];
            foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $dir) {
                if (! str_starts_with($prefix, self::eePrefix()) && ! str_starts_with((string) $dir, self::eeDir())) {
                    $map[$prefix] = (string) $dir;
                }
            }
            uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));
        }

        return $map;
    }

    /** @return list<string> */
    private function communityPhpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (self::SCAN_DIRS as $dir) {
            if (! is_dir($root.'/'.$dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    private function rel(string $file): string
    {
        return ltrim(substr($file, strlen(dirname(__DIR__, 2))), '/');
    }
}
