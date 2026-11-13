<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * BL-036 R1: the one rule for a brand colour that reaches CSS (`<style>`, `style=`), a web manifest or a theme-color
 * meta. Only strict hex is a colour: `#rgb` is expanded to `#rrggbb` (renderers read six digits), anything else
 * (names, rgb(), CSS fragments such as `red;}body{...`) is not a colour. Store colours through {@see normalize()},
 * print them through {@see safe()}.
 */
final class HexColor
{
    /** The platform default accent, used when neither the colour nor the fallback is valid. */
    public const DEFAULT = '#6366f1';

    /** Validation regex for a form field: `#rgb` or `#rrggbb`. */
    public const RULE = 'regex:/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/';

    /** The colour as lowercase `#rrggbb`, or null when it is not a strict hex colour. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);
        if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $v, $m) === 1) {
            return strtolower('#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3]);
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? strtolower($v) : null;
    }

    /** A colour safe to print into CSS: the value as `#rrggbb`, else the (also checked) fallback, else the default. */
    public static function safe(mixed $value, ?string $fallback = self::DEFAULT): string
    {
        return self::normalize($value) ?? self::normalize($fallback) ?? self::DEFAULT;
    }
}
