<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Ops;

/**
 * Read-only health report for the .env file (H8).
 *
 * NEVER returns or renders an env VALUE — only key names and verdicts. The
 * superadmin panel that consumes this is a diagnostics surface, not a secret
 * viewer, and the repo already refuses to make .env web-editable
 * (see the "H3: APP_DEBUG toggle removed" note in admin/settings/general.blade.php).
 */
class EnvFileHealth
{
    /**
     * @return array{ok:bool, readable:bool, issues:list<array{key:string, code:string}>}
     */
    public static function report(): array
    {
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_readable($path)) {
            return ['ok' => false, 'readable' => false, 'issues' => []];
        }

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $values = [];
        $issues = [];
        $seen = [];

        foreach ($lines as $raw) {
            $ln = trim($raw);
            if ($ln === '' || str_starts_with($ln, '#') || ! str_contains($ln, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $ln, 2);
            $k = trim($k);
            $values[$k] = $values[$k] ?? $v;      // first assignment wins, as dotenv does
            $seen[$k] = ($seen[$k] ?? 0) + 1;

            // An unquoted value containing whitespace makes the whole file
            // unparseable; one containing '#' is silently truncated.
            $bare = $v !== '' && $v[0] !== '"' && $v[0] !== "'";
            if ($bare && preg_match('/[\s#]/', $v)) {
                $issues[] = ['key' => $k, 'code' => 'unquoted_unsafe_value'];
            }
            // A value that opens unquoted and then contains a quote is the
            // signature of the old unanchored str_replace.
            if ($bare && str_contains($v, '"')) {
                $issues[] = ['key' => $k, 'code' => 'malformed_value'];
            }
        }

        foreach ($seen as $k => $n) {
            if ($n > 1) {
                $issues[] = ['key' => $k, 'code' => 'duplicate_key'];
            }
        }

        $appKey = trim((string) ($values['APP_KEY'] ?? ''), '"\'');
        if ($appKey !== '') {
            foreach (['PUSHER_APP_KEY', 'VITE_PUSHER_APP_KEY'] as $k) {
                $other = trim((string) ($values[$k] ?? ''), '"\'');
                if ($other !== '' && hash_equals($appKey, $other)) {
                    $issues[] = ['key' => $k, 'code' => 'app_key_leak'];
                }
            }
        }

        return ['ok' => $issues === [], 'readable' => true, 'issues' => $issues];
    }
}
