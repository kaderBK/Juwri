<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database;

/**
 * P6-38: released migrations are immutable. An install that already ran a migration never runs it again, so an
 * in-place edit (a new column, a changed CHECK) silently never reaches upgraded installs. This class fingerprints
 * every migration file and keeps the fingerprints in database/migration-checksums.json; MigrationChecksumsTest
 * fails when a listed file's code changes or disappears. Change the schema with a NEW migration file instead.
 *
 * The fingerprint is the sha256 of the PHP tokens with comments and whitespace dropped, so SPDX headers and
 * doc-comment rewording do not count as edits — only code does.
 *
 * Update the manifest (after adding a new migration): `php artisan juwri:migrations:checksums`.
 */
class MigrationChecksums
{
    public const MANIFEST = 'database/migration-checksums.json';

    /**
     * P6-171: frozen fingerprints of released migrations. Only ever grows (new keys); an existing value never
     * changes. A manifest value that differs from it must be explained by an MEn entry in MIGRATION-EDITS.md.
     */
    public const BASELINE = 'database/migration-checksums.baseline.json';

    /** MIGRATION-EDITS.md, relative to the repository root (the parent of core/). */
    public const EDITS_LOG = 'core/database/MIGRATION-EDITS.md';

    /** Globs relative to core/ that hold migrations. */
    private const GLOBS = [
        'database/migrations/*.php',
        'app/Core/Database/Migrations/*.php',
        'app/Modules/*/Database/Migrations/*.php',
        'plugins/*/database/migrations/*.php',
    ];

    /** @return array<string, string> path relative to core/ => fingerprint, sorted by path */
    public static function compute(string $coreDir): array
    {
        $out = [];
        foreach (self::GLOBS as $glob) {
            foreach (glob($coreDir.'/'.$glob) ?: [] as $file) {
                $out[substr($file, strlen($coreDir) + 1)] = self::fingerprint((string) file_get_contents($file));
            }
        }
        ksort($out);

        return $out;
    }

    public static function fingerprint(string $php): string
    {
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    $code .= ' ';

                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return hash('sha256', (string) preg_replace('/\s+/', ' ', trim($code)));
    }

    /** @return array<string, string> */
    public static function manifest(string $coreDir): array
    {
        return self::readJson($coreDir.'/'.self::MANIFEST);
    }

    /** @return array<string, string> */
    public static function baseline(string $coreDir): array
    {
        return self::readJson($coreDir.'/'.self::BASELINE);
    }

    /**
     * Parses the `files: <path>@<sha256>, ...` line of every `## MEn` entry. Later entries win per path.
     *
     * @return array<string, array{entry: string, sum: string}> path => newest entry naming it
     */
    public static function parseEdits(string $markdown): array
    {
        $out = [];
        $entry = null;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^##\s+(ME\d+)\b/', $line, $m)) {
                $entry = $m[1];

                continue;
            }
            if ($entry === null || ! preg_match('/^files:\s*(.+)$/', trim($line), $m)) {
                continue;
            }
            foreach (array_filter(array_map('trim', explode(',', $m[1]))) as $item) {
                [$path, $sum] = array_pad(explode('@', $item, 2), 2, '');
                $out[$path] = ['entry' => $entry, 'sum' => $sum];
            }
        }

        return $out;
    }

    /**
     * Problems with released-migration edits: manifest vs baseline, explained by the MIGRATION-EDITS.md entries.
     *
     * @param  array<string, string>  $baseline
     * @param  array<string, string>  $manifest
     * @param  array<string, array{entry: string, sum: string}>  $edits
     * @return list<string>
     */
    public static function editProblems(array $baseline, array $manifest, array $edits): array
    {
        $problems = [];
        foreach ($baseline as $path => $sum) {
            $now = $manifest[$path] ?? null;
            if ($now === $sum) {
                continue;
            }
            $edit = $edits[$path] ?? null;
            if ($edit === null) {
                $problems[] = $now === null
                    ? "{$path}: removed from the manifest without an ME entry"
                    : "{$path}: fingerprint changed without an ME entry in MIGRATION-EDITS.md";
            } elseif ($edit['sum'] !== $now) {
                $problems[] = "{$path}: changed again after {$edit['entry']} (ME fingerprint ".($edit['sum'] ?: 'missing').', manifest '.($now ?? 'removed').') - add a new ME entry';
            }
        }
        foreach ($edits as $path => $edit) {
            if (! array_key_exists($path, $baseline)) {
                $problems[] = "{$path}: listed in {$edit['entry']} but not a baseline (released) migration";
            } elseif (($manifest[$path] ?? null) === $baseline[$path]) {
                $problems[] = "{$path}: listed in {$edit['entry']} but its fingerprint did not change";
            }
        }

        return $problems;
    }

    /** @return array<string, string> */
    private static function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Writes the manifest: keeps every listed entry as it was, adds files not yet listed. Returns the added paths.
     *
     * @return list<string>
     */
    public static function write(string $coreDir, bool $rebuild = false): array
    {
        $current = self::compute($coreDir);
        $old = $rebuild ? [] : self::manifest($coreDir);
        $added = array_values(array_diff(array_keys($current), array_keys($old)));
        $merged = $old + $current;
        ksort($merged);
        file_put_contents($coreDir.'/'.self::MANIFEST, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        // The baseline only grows: new migrations are frozen at their first fingerprint; existing values never move.
        $baseline = self::baseline($coreDir);
        $grown = $baseline + array_diff_key($current, $baseline);
        ksort($grown);
        if ($grown !== $baseline) {
            file_put_contents($coreDir.'/'.self::BASELINE, json_encode($grown, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        return $added;
    }
}
