<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P6-67: store/platform options must never be written by looping over the raw
 * request (`$request->all()` / `->except()` / `->input()`), which lets a form
 * post set any option key (gateway credentials, compliance flags). Writers use
 * an explicit allowlist (`->only(KEYS)` or a per-key writable() filter).
 */
class NoOptionMassAssignTest extends TestCase
{
    /** Files that iterate the request but filter each key through an allowlist. */
    private const FILTERED = [
        'app/Core/Http/Requests/Admin/SettingsRequest.php', // SettingsSections::writable() per key
    ];

    public function test_no_option_writer_iterates_the_raw_request(): void
    {
        $root = dirname(__DIR__, 2);
        $writer = '/updateStoreOption\(|StoreSettings::(set|put)\w*\(|update_option\(|PlatformSetting::(updateOrCreate|create|query\(\)->update)/';
        $offenders = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if (in_array($rel, self::FILTERED, true)) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (! preg_match($writer, $src)) {
                continue;
            }
            // foreach directly over the raw request, or a variable assigned from it then iterated.
            if (preg_match('/foreach\s*\(\s*(\$request|\$this)->(all|except|input)\(/', $src)) {
                $offenders[] = $rel;

                continue;
            }
            if (preg_match_all('/(\$\w+)\s*=\s*(?:\$request|\$this)->(?:all|except|input)\(\s*(?:\)|[\'"])/', $src, $m)) {
                foreach ($m[1] as $var) {
                    if (preg_match('/foreach\s*\(\s*'.preg_quote($var, '/').'\s+as\s+\$\w+\s*=>/', $src)) {
                        $offenders[] = $rel;
                        break;
                    }
                }
            }
        }
        $this->assertSame([], $offenders, 'Option writers must use an explicit key allowlist, not the raw request: '.implode(', ', $offenders));
    }
}
