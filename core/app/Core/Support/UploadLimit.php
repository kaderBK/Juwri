<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * P6-212: the PHP host's effective per-request upload ceiling.
 *
 * A single upload is bounded by the smaller of `upload_max_filesize` (PHP's per-file
 * cap) and `post_max_size` (the request body cap) — both of which shared hosting
 * tiers routinely pin at 2–8 MB. Without a friendly reading of these, owners hit a
 * raw 413 "the server stopped before the file finished" instead of a useful message.
 *
 * `phpBytes()` parses "2M", "512K", "1024" and falls through to `int` for any other
 * shape (PHP raises a warning on garbage and returns false). `effectiveMaxMb()` picks
 * the smaller of the two and rounds DOWN — the real ceiling a user can ever send.
 */
final class UploadLimit
{
    /** Convert a PHP ini shorthand size (K/M/G suffix, optional trailing "B") to bytes. */
    public static function phpBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $multiplier = match ($unit) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };
        $number = $unit === 'g' || $unit === 'm' || $unit === 'k'
            ? substr($value, 0, -1)
            : $value;
        $number = rtrim($number, 'bB');
        if (! is_numeric($number)) {
            return 0;
        }
        $bytes = (int) $number * $multiplier;

        return $bytes > 0 ? $bytes : 0;
    }

    /** Read a single ini directive as bytes; 0 on parse failure (treat as unknown). */
    public static function iniBytes(string $name): int
    {
        $raw = @ini_get($name);
        if (! is_string($raw) || $raw === '') {
            return 0;
        }
        $clean = trim($raw);
        if ($clean === '' || strtolower($clean) === 'off' || $clean === '-1') {
            return 0;
        }

        return self::phpBytes($clean);
    }

    /**
     * Effective upload ceiling — the smaller of `upload_max_filesize` and `post_max_size`,
     * expressed in whole megabytes (rounded down so it is always safe to claim).
     *
     * On hosts where either directive could not be read the other still bounds the
     * request, so the value is whichever is known. Returns 0 only when neither
     * directive is readable, in which case the form hint hides.
     */
    public static function effectiveMaxMb(): int
    {
        $upload = self::iniBytes('upload_max_filesize');
        $store = self::iniBytes('post_max_size');
        $bytes = match (true) {
            $upload > 0 && $store > 0 => min($upload, $store),
            $upload > 0 => $upload,
            $store > 0 => $store,
            default => 0,
        };
        if ($bytes <= 0) {
            return 0;
        }

        return intdiv($bytes, 1024 * 1024);
    }
}
