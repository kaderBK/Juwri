<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * P6-101: one safe way to turn user search text into a LIKE filter.
 *
 * - the term is trimmed, whitespace-collapsed, capped at MAX characters and ignored below MIN;
 * - `%`, `_` and the escape character are literal (explicit `ESCAPE '!'`, identical on sqlite, MySQL and PostgreSQL;
 *   Laravel's whereLike() has no ESCAPE clause and sqlite has no default one);
 * - PostgreSQL uses ILIKE, the others rely on their case-insensitive LIKE;
 * - phone-looking terms match digit prefixes only (an unanchored scan of a phone column is pointless and slow).
 *
 * Column names are trusted code, never user input.
 */
final class SearchTerm
{
    public const MIN = 2;

    public const MAX = 64;

    public const ESCAPE = '!';

    /** Trimmed, collapsed, length-capped term; '' when it is shorter than MIN (treat as "no search"). */
    public static function clean(?string $raw, int $min = self::MIN): string
    {
        $term = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', (string) $raw));
        $term = trim(mb_substr($term, 0, self::MAX));

        return mb_strlen($term) < $min ? '' : $term;
    }

    /**
     * P6-295: fold Arabic spelling variants and non-ASCII digits so a typed term and a stored name compare equal:
     *
     *  - alef forms (أ إ آ ٱ) → ا; alef-maqsura (ى) → ي; ta-marbuta (ة) → ه;
     *  - tashkeel (harakat, superscript alef, Quranic annotation marks) and tatweel (ـ) are stripped;
     *  - Arabic-Indic and Persian digits (٣ → 3) are rewritten as ASCII (whatsapp_latin_digits()).
     *
     * English and other non-Arabic text is left untouched; whitespace is NOT collapsed (that is clean()'s job).
     */
    public static function normalize(string $term): string
    {
        $term = whatsapp_latin_digits($term);
        $term = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $term);
        $term = str_replace('ى', 'ي', $term);
        $term = str_replace('ة', 'ه', $term);

        return preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $term) ?? $term;
    }

    /** The term with LIKE wildcards made literal (pair with `ESCAPE '!'`). */
    public static function escape(string $term): string
    {
        $e = self::ESCAPE;

        return str_replace([$e, '%', '_'], [$e.$e, $e.'%', $e.'_'], $term);
    }

    /** Pattern for `... LIKE ? ESCAPE '!'`: contains by default, prefix when $prefix. */
    public static function like(string $raw, bool $prefix = false): string
    {
        $escaped = self::escape(self::clean($raw, 1));

        return ($prefix ? '' : '%').$escaped.'%';
    }

    /** True when the query runs on PostgreSQL (LIKE on json/int columns needs a ::text cast there). */
    public static function isPgsql(mixed $query): bool
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $connection = $base->getConnection();

        return $connection instanceof Connection && $connection->getDriverName() === 'pgsql';
    }

    /** Digits of a phone-looking term (digits, + space - ( ) . only, at least 3 digits); null otherwise. */
    public static function phoneDigits(string $term): ?string
    {
        if (! preg_match('/^[+\d\s().\-]+$/', $term)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return strlen($digits) >= 3 ? $digits : null;
    }

    /**
     * Add one AND-ed group `col1 LIKE .. OR col2 LIKE ..` to the query. A term below MIN adds nothing (free-text search; use applyOrNone() for filters).
     * A phone-looking term matches $phoneColumns by digit prefix (with and without a leading +) and
     * skips the plain $columns, so "555" never full-scans names.
     *
     * @template TBuilder of EloquentBuilder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $columns  contains-match columns
     * @param  list<string>  $phoneColumns  prefix-match phone columns
     * @param  list<string>  $prefixColumns  prefix-match text columns (index friendly, e.g. slug)
     * @return TBuilder
     */
    public static function apply($query, array $columns, ?string $raw, array $phoneColumns = [], array $prefixColumns = [])
    {
        return self::build($query, $columns, $raw, $phoneColumns, $prefixColumns, self::MIN);
    }

    /**
     * FILTER mode (audience tags, tag pages, city filters): the value narrows a result set that must never widen.
     * Min length is 1, and an empty value matches NOTHING (apply() would add no filter and return everything).
     * Free-text list search uses apply(). Note: a numeric / phone-looking term skips the plain $columns only when
     * $phoneColumns are given (so "2024" never full-scans names); filters pass no phone columns.
     *
     * @template TBuilder of EloquentBuilder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $columns
     * @return TBuilder
     */
    public static function applyOrNone($query, array $columns, ?string $raw)
    {
        if (self::clean($raw, 1) === '') {
            return $query->whereRaw('1 = 0');
        }

        return self::build($query, $columns, $raw, [], [], 1);
    }

    /**
     * @template TBuilder of EloquentBuilder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $columns
     * @param  list<string>  $phoneColumns
     * @param  list<string>  $prefixColumns
     * @return TBuilder
     */
    private static function build($query, array $columns, ?string $raw, array $phoneColumns, array $prefixColumns, int $min)
    {
        $term = self::clean($raw, $min);
        if ($term === '' || ($columns === [] && $phoneColumns === [] && $prefixColumns === [])) {
            return $query;
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $grammar = $base->getGrammar();
        $connection = $base->getConnection();
        $pgsql = $connection instanceof Connection && $connection->getDriverName() === 'pgsql';
        $op = $pgsql ? 'ilike' : 'like';
        $e = self::ESCAPE;
        $digits = $phoneColumns === [] ? null : self::phoneDigits($term);

        $rules = [];
        if ($digits !== null) {
            foreach ($phoneColumns as $col) {
                $rules[] = [$col, $digits.'%'];
                $rules[] = [$col, '+'.$digits.'%'];
                if ($digits !== $term) {
                    // formatted as typed ("555-2222"): stored numbers keep their punctuation, so match it literally too
                    $rules[] = [$col, self::like($term)];
                }
            }
        } else {
            foreach ($columns as $col) {
                $rules[] = [$col, self::like($term)];
            }
            foreach ($prefixColumns as $col) {
                $rules[] = [$col, self::like($term, true)];
            }
        }

        $mysql = $connection instanceof Connection && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);

        return $query->where(function ($w) use ($rules, $grammar, $op, $e, $pgsql, $mysql) {
            foreach ($rules as [$col, $pattern]) {
                $sql = $grammar->wrap($col);
                if ($pgsql) {
                    // ILIKE needs text; integer / JSON columns are cast (like Laravel's whereLike)
                    $sql .= '::text';
                } elseif ($mysql && str_starts_with($pattern, '%')) {
                    // MySQL compares JSON with a binary collation (case-sensitive): lower both sides. Contains patterns
                    // only — they cannot use a btree anyway; prefix patterns stay index-friendly.
                    $sql = 'lower(cast('.$sql.' as char))';
                    $pattern = mb_strtolower($pattern);
                }
                $w->orWhereRaw($sql." {$op} ? escape '{$e}'", [$pattern]);
            }
        });
    }
}
