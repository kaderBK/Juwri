<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * The inner SVG markup of a vendored Tabler icon, for <x-ui.icon>. A class instead of a closure inside the Blade view:
 * on PHP 8.4 every include of a file that holds a closure literal keeps about 250 bytes, executed or not, so a closure in
 * a view that every dashboard page renders dozens of times grew a worker by 20-100 KB per request (P6-36 soak).
 * The memo is keyed by file, so it is bounded by the number of icons shipped.
 */
final class TablerIcons
{
    /** @var array<string, string|null> */
    private static array $bodies = [];

    public static function body(string $file): ?string
    {
        if (array_key_exists($file, self::$bodies)) {
            return self::$bodies[$file];
        }
        $svg = is_file($file) ? (string) file_get_contents($file) : '';
        if (! preg_match('#<svg\b[^>]*>(.*)</svg>#s', $svg, $m)) {
            return self::$bodies[$file] = null;
        }
        // Drop Tabler's invisible 24×24 bounding-box path and the source indentation.
        $body = (string) preg_replace('#<path stroke="none" d="M0 0h24v24H0z" fill="none"\s*/>#', '', $m[1]);

        return self::$bodies[$file] = trim((string) preg_replace('/>\s+</', '><', $body)) ?: null;
    }
}
