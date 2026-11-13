<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MediaReferences – is a library file still pointed at by any row?
 *
 * Everything under storage/menu/ is a shared library: duplicating a menu item
 * (bulk "duplicate", whole-store clone, template import) copies the FILENAME,
 * never the file, so one file routinely backs several rows across several
 * tables. Deleting or re-photographing any one of them used to unlink the file
 * and leave every other row pointing at a 404 — the storefront then falls back
 * to default.png forever.
 *
 * Callers ask here before removing anything physical. The check runs after the
 * owning row is gone (models clean up on `deleted`, not `deleting`), so a row
 * never counts itself as a reference.
 */
class MediaReferences
{
    /**
     * Tables/columns that store a bare storage/menu filename.
     *
     * @var array<int, array{table: string, column: string, json: bool}>
     */
    private const SOURCES = [
        ['table' => 'items',            'column' => 'image',          'json' => false],
        ['table' => 'items',            'column' => 'gallery_images', 'json' => true],
        ['table' => 'image_menus',     'column' => 'image',          'json' => false],
        ['table' => 'media_assets',    'column' => 'filename',       'json' => false],
        ['table' => 'categories', 'column' => 'image',          'json' => false],
        ['table' => 'modifiers',       'column' => 'image',          'json' => false], // P1-06: add-ons + option images
        ['table' => 'menu_banners',    'column' => 'image',          'json' => false],
        ['table' => 'stories',         'column' => 'media_url',      'json' => false],
        // PARITY-RUN T10: a QR design's logo overlay is a storage/menu library
        // file like any other, so deleting the menu item it came from must not
        // silently 404 every printed QR code.
        ['table' => 'qr_designs',      'column' => 'logo_file',      'json' => false],
    ];

    /**
     * Only the shared library directory needs this protection — per-restaurant
     * paths (logo, cover, fonts …) hold one file per row.
     */
    public static function isSharedPath(string $path): bool
    {
        return trim(preg_replace('#^/?storage/#', '', $path), '/') === 'menu';
    }

    /**
     * Is $filename still referenced by rows other than the caller's own?
     *
     * $ownReferences is how many references belong to the caller itself and so
     * do not count as "someone else still needs this". Deleting a row cleans up
     * on `deleted`, when its reference is already gone, and passes 0; replacing
     * an image happens while the owning row still points at the old file, and
     * passes 1.
     */
    public static function isReferenced(string $filename, int $ownReferences = 0): bool
    {
        if ($filename === '' || $filename === 'default.png') {
            return true; // never remove the shared fallback
        }

        return self::countReferences($filename, $ownReferences + 1) > $ownReferences;
    }

    /**
     * Reference counts for many filenames at once.
     *
     * Auditing thousands of files one countReferences() at a time is one query
     * per file per table; this is a handful of grouped queries per chunk.
     * JSON gallery columns are skipped — they cannot be grouped on, and a
     * gallery-only reference is rare enough to under-count knowingly.
     *
     * @param  array<int, string>  $filenames
     * @return array<string, int>  filename => count (absent means zero)
     */
    public static function countMany(array $filenames): array
    {
        $counts = [];

        foreach (array_chunk(array_values(array_unique($filenames)), 500) as $chunk) {
            foreach (self::SOURCES as $source) {
                if ($source['json']
                    || ! Schema::hasTable($source['table'])
                    || ! Schema::hasColumn($source['table'], $source['column'])) {
                    continue;
                }

                $rows = DB::table($source['table'])
                    ->select($source['column'].' as filename', DB::raw('count(*) as total'))
                    ->whereIn($source['column'], $chunk)
                    ->groupBy($source['column'])
                    ->get();

                foreach ($rows as $row) {
                    $counts[$row->filename] = ($counts[$row->filename] ?? 0) + (int) $row->total;
                }
            }
        }

        return $counts;
    }

    /**
     * Count rows pointing at $filename, stopping once $limit is reached.
     */
    public static function countReferences(string $filename, int $limit = PHP_INT_MAX): int
    {
        $count = 0;

        foreach (self::SOURCES as $source) {
            if (! Schema::hasTable($source['table'])
                || ! Schema::hasColumn($source['table'], $source['column'])) {
                continue;
            }

            $query = DB::table($source['table']);

            if ($source['json']) {
                // gallery_images is a JSON array of filenames; a LIKE on the
                // quoted name is exact enough because names are unique ids.
                $query->whereRaw($query->getGrammar()->wrap($source['column']).(\App\Support\SearchTerm::isPgsql($query) ? '::text' : '')." like ? escape '!'", ['%"'.\App\Support\SearchTerm::escape($filename).'"%']);
            } else {
                $query->where($source['column'], $filename);
            }

            $count += $query->count();

            if ($count >= $limit) {
                return $count;
            }
        }

        return $count;
    }
}
