<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every vendored Alpine build carries the x-show hide-cascade fix (alpinejs/alpine#4204, Alpine 3.14.0). Alpine
 * 3.13.10 ran `.then(([i]) => i())` on a hide promise that a second hide within the same frame had already consumed,
 * throwing "TypeError: u is not a function" (minified) — the flaky e2e b-signup pageerror. The behaviour itself is
 * driven in the browser by tests/e2e (lib/platform.js alpineHideRaceErrors, b-signup).
 */
class VendoredAlpineTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function builds(): array
    {
        return [
            'owner shell' => ['assets/vendor/alpinejs/alpine.min.js'],
            'POS' => ['assets/pos/alpine.min.js'],
        ];
    }

    #[DataProvider('builds')]
    public function test_the_build_has_the_hide_cascade_fix(string $path): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3).'/'.$path);
        $this->assertNotSame('', $js, $path);

        $this->assertSame(1, preg_match('/version:"(\d+)\.(\d+)\.(\d+)"/', $js, $m), "{$path}: no Alpine version");
        $this->assertTrue(
            version_compare("{$m[1]}.{$m[2]}.{$m[3]}", '3.14.0', '>='),
            "{$path}: Alpine {$m[1]}.{$m[2]}.{$m[3]} predates the x-show hide-cascade fix (needs >= 3.14.0)"
        );
        // The unguarded call itself (minified: `.then(([u])=>u())`); fixed builds call `u?.()`.
        $this->assertSame(0, preg_match('/\.then\(\(\[(\w+)\]\)=>\1\(\)\)/', $js), "{$path}: unguarded hide-promise call");
    }
}
