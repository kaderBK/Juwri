<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * BL-047 (J77): enforce global uniqueness on stores.slug at the database layer.
 *
 * Existing duplicate slugs (including any soft-deleted rows — DB::table reads all rows)
 * are deduplicated, using the database's own collation to decide what a duplicate is, before
 * creating stores_slug_unique so the index succeeds on SQLite, MySQL/MariaDB (_ci collations)
 * and PostgreSQL. Renames are logged (old → new, store id) for QR reprints.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'slug')) {
            return;
        }

        $this->deduplicateSlugs();

        Schema::table('stores', function (Blueprint $table): void {
            if (Schema::hasIndex('stores', 'stores_slug_idx')) {
                $table->dropIndex('stores_slug_idx');
            }
            if (! Schema::hasIndex('stores', 'stores_slug_unique')) {
                $table->unique(['slug'], 'stores_slug_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table): void {
            if (Schema::hasIndex('stores', 'stores_slug_unique')) {
                $table->dropUnique('stores_slug_unique');
            }
            if (! Schema::hasIndex('stores', 'stores_slug_idx')) {
                $table->index(['slug'], 'stores_slug_idx');
            }
        });
    }

    /**
     * Rename only the 2nd+ members of a real duplicate group (lowest id keeps its slug, so printed QR codes of
     * stores whose slug was already unique never change).
     *
     * "Duplicate" is decided by the database itself: GROUP BY compares with the column's own collation, so
     * MySQL utf8mb4_unicode_ci treats `café`/`cafe`/`CAFE` as one group (exactly what the unique index will
     * enforce), while PostgreSQL and SQLite stay case/byte-sensitive. Every rename is committed on its own and the
     * keeper is recomputed on each run, so re-running after a partial failure simply continues where it stopped.
     */
    private function deduplicateSlugs(): void
    {
        $keepers = array_flip(
            DB::table('stores')
                ->whereNotNull('slug')
                ->groupBy('slug')
                ->selectRaw('MIN(id) as keep_id')
                ->pluck('keep_id')
                ->map(fn ($id): int => (int) $id)
                ->all()
        );

        $rows = DB::table('stores')
            ->whereNotNull('slug')
            ->orderBy('id')
            ->get(['id', 'slug']);

        foreach ($rows as $row) {
            if (isset($keepers[(int) $row->id])) {
                continue;
            }

            $old = (string) $row->slug;
            $base = trim($old) !== '' ? trim($old) : 'store';
            $new = $this->freeSlug($base, (int) $row->id);

            DB::table('stores')->where('id', $row->id)->update(['slug' => $new]);

            Log::warning('BL-047: duplicate store slug renamed; reprint its QR codes', [
                'store_id' => (int) $row->id,
                'old_slug' => $old,
                'new_slug' => $new,
            ]);
        }
    }

    /** A slug no existing store row has (checked through the DB, so under the column collation). */
    private function freeSlug(string $base, int $id): string
    {
        $suffix = 1;
        do {
            $tail = '-'.$id.($suffix > 1 ? '-'.$suffix : '');
            $candidate = mb_substr($base, 0, 255 - mb_strlen($tail)).$tail;
            $suffix++;
        } while (DB::table('stores')->where('slug', $candidate)->exists());

        return $candidate;
    }
};
