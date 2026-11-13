<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The Community build ships without the edition package folder, so Community code and Community tests must find an
 * installed edition's files through Composer (EditionPackages::all(), ViewRoots, EditionFolders) — never through a fixed
 * base_path() of the package folder. Found by the Community mirror suite on MySQL/PostgreSQL (P1-12 final sweep):
 * CookieConsentTest scanned base_path() of the package folder → DirectoryNotFoundException in the mirror, while the
 * private suite (folder present) stayed green.
 */
class NoFixedEditionPathTest extends TestCase
{
    /** Spelled out at run time so this file does not carry the pattern it looks for. */
    private static function pattern(): string
    {
        $dir = 'e'.'e';

        return '~base_path\(\s*([\'"])'.$dir.'(/[^\'"]*)?\1\s*\)~';
    }

    public function test_community_code_and_tests_never_name_the_edition_folder_by_path(): void
    {
        $core = dirname(__DIR__, 2);
        $bad = [];
        foreach (['app', 'config', 'routes', 'bootstrap', 'database', 'tests'] as $dir) {
            if (! is_dir($core.'/'.$dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($core.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                if (! str_ends_with($path, '.php')) {
                    continue;
                }
                foreach (file($path) ?: [] as $n => $line) {
                    if (preg_match(self::pattern(), $line) === 1) {
                        $bad[] = substr($path, strlen($core) + 1).':'.($n + 1).': '.trim($line);
                    }
                }
            }
        }
        $this->assertSame([], $bad, "Fixed edition-folder paths (use EditionPackages::all() / ViewRoots / EditionFolders):\n".implode("\n", $bad));
    }

    public function test_the_pattern_finds_a_fixed_path_and_ignores_others(): void
    {
        $dir = 'e'.'e';
        $this->assertSame(1, preg_match(self::pattern(), "\$roots = [base_path('".$dir."')];"));
        $this->assertSame(1, preg_match(self::pattern(), 'base_path("'.$dir.'/Modules")'));
        $this->assertSame(0, preg_match(self::pattern(), "base_path('".$dir."x')"));
        $this->assertSame(0, preg_match(self::pattern(), "base_path('routes')"));
    }
}
