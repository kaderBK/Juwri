<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Search;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine;

/**
 * P1-17 (J27-B): Scout's `database` engine as juwri runs it (Community edition; Meilisearch optional later).
 *
 * Search results must stay the same as the LIKE search they replace, or get strictly better, on every database:
 *
 *  - every column in toSearchableArray() matches as a case-insensitive substring, like before (LIKE; ILIKE on
 *    PostgreSQL). `%` and `_` in the term are literal (escaped with `!`, which every driver honours in an ESCAPE
 *    clause) — before, they were wildcards, and the POS backslash escaping did nothing on sqlite;
 *  - a column the model marks with #[SearchUsingFullText] is matched by LIKE *or* by the full-text index, so a
 *    multi-word query finds the words in any order ("burger chicken" finds "Chicken Burger"). MySQL/MariaDB use
 *    boolean mode with every word required (words of 3+ characters, innodb_ft_min_token_size); PostgreSQL uses
 *    plainto_tsquery with the `simple` text-search config (no stemming, no stopwords: Arabic and English names
 *    alike — the index is built on the same expression). sqlite has no full-text grammar: LIKE only;
 *  - when the full-text branch can add rows, the LIKE matches sort first, so a limit never pushes out a row the
 *    old LIKE search returned.
 *
 * Store scoping is the caller's `where`/`whereIn` (see StoreSearchable::searchInStores()), ANDed outside the OR group;
 * the model's global scopes (the tenant AccountScope, SoftDeletes) apply because the query starts from newQuery().
 */
class DatabaseSearchEngine extends DatabaseEngine
{
    /** LIKE escape character; not a backslash, which sqlite does not treat as an escape. */
    public const LIKE_ESCAPE = '!';

    /** Drivers with a full-text grammar in Laravel. */
    private const FULL_TEXT_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /** innodb_ft_min_token_size default: shorter words are not in a MySQL full-text index. */
    private const MYSQL_MIN_WORD = 3;

    /**
     * Apply the model's text search to an existing query (list screens keep their own filters, ordering, pagination
     * and export path).
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>  $query
     * @param  Builder<TModel>  $builder
     * @return EloquentBuilder<TModel>
     */
    public function constrain(EloquentBuilder $query, Builder $builder): EloquentBuilder
    {
        $model = $builder->model;

        return $this->addTextSearchConstraints(
            $query,
            $builder,
            method_exists($model, 'toSearchableArray') ? array_map('strval', array_keys($model->toSearchableArray())) : [],
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder),
        );
    }

    /** The term with LIKE wildcards made literal (pair with `ESCAPE '!'`). */
    public static function escapeLike(string $term): string
    {
        $e = self::LIKE_ESCAPE;

        return str_replace([$e, '%', '_'], [$e.$e, $e.'%', $e.'_'], $term);
    }

    /**
     * The full-text query for the driver, or null when the term has no word the index can match.
     * MySQL: "+word +word" (boolean mode, every word required). PostgreSQL: the words for plainto_tsquery (AND).
     */
    public static function fullTextTerm(string $term, string $driver): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($driver === 'pgsql') {
            return $words === [] ? null : implode(' ', $words);
        }

        $words = array_values(array_filter($words, fn (string $w): bool => mb_strlen($w) >= self::MYSQL_MIN_WORD));

        return $words === [] ? null : implode(' ', array_map(fn (string $w): string => '+'.$w, $words));
    }

    /**
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>  $query
     * @param  Builder<TModel>  $builder
     * @param  list<string>  $columns
     * @param  list<string>  $prefixColumns
     * @param  list<string>  $fullTextColumns
     * @return EloquentBuilder<TModel>
     */
    protected function addTextSearchConstraints($query, Builder $builder, array $columns, array $prefixColumns = [], array $fullTextColumns = [])
    {
        $term = \App\Support\SearchTerm::clean((string) $builder->query);
        if ($term === '' || $columns === []) {
            return $query;
        }

        $driver = $builder->modelConnectionType();
        $grammar = $query->getQuery()->getGrammar();
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';
        $escaped = self::escapeLike($term);
        $normalized = \App\Support\SearchTerm::normalize($term);
        $escapedNormalized = self::escapeLike($normalized);

        $likes = [];
        $bindings = [];
        foreach ($columns as $column) {
            $likes[] = $grammar->wrap($builder->model->qualifyColumn($column)).' '.$operator." ? escape '".self::LIKE_ESCAPE."'";
            $bindings[] = in_array($column, $prefixColumns, true) ? $escaped.'%' : '%'.$escaped.'%';

            // P6-295: a stored normalised search column matches Arabic spelling variants the exact LIKE misses.
            $normalizedColumn = $this->normalizedColumn($builder->model, $column);
            if ($normalizedColumn !== null) {
                $likes[] = $grammar->wrap($builder->model->qualifyColumn($normalizedColumn)).' '.$operator." ? escape '".self::LIKE_ESCAPE."'";
                $bindings[] = in_array($column, $prefixColumns, true) ? $escapedNormalized.'%' : '%'.$escapedNormalized.'%';
            }
        }

        $fullTextTerm = $fullTextColumns === [] ? null : self::fullTextTerm($term, $driver);

        $query->where(function (EloquentBuilder $where) use ($likes, $bindings, $fullTextTerm, $fullTextColumns, $builder): void {
            foreach ($likes as $i => $like) {
                $where->orWhereRaw($like, [$bindings[$i]]);
            }
            if ($fullTextTerm !== null) {
                $where->orWhereFullText(
                    array_map(fn (string $column): string => $builder->model->qualifyColumn($column), $fullTextColumns),
                    $fullTextTerm,
                    $this->getFullTextOptions($builder),
                );
            }
        });

        if ($fullTextTerm !== null) {
            // The rows the old LIKE search returned come first, so a limit keeps all of them.
            $query->orderByRaw('case when '.implode(' or ', $likes).' then 0 else 1 end', $bindings);
        }

        return $query;
    }

    /**
     * The stored normalised search column for a searched column (e.g. items.name → items.name_search), or null when
     * the model has none. The model declares the map in normalizedSearchColumns().
     *
     * @param  Model  $model
     */
    protected function normalizedColumn(Model $model, string $column): ?string
    {
        if (! method_exists($model, 'normalizedSearchColumns')) {
            return null;
        }

        $map = $model->normalizedSearchColumns();

        return is_array($map) ? ($map[$column] ?? null) : null;
    }

    /**
     * Full-text columns only where the driver has a full-text grammar (sqlite: none, LIKE only).
     *
     * @param  Builder<covariant Model>  $builder
     * @return list<string>
     */
    protected function getFullTextColumns(Builder $builder)
    {
        return in_array($builder->modelConnectionType(), self::FULL_TEXT_DRIVERS, true)
            ? array_values(parent::getFullTextColumns($builder))
            : [];
    }

    /**
     * PostgreSQL text-search config defaults to `simple` (the index expression uses it); MySQL runs in boolean mode.
     *
     * @param  Builder<covariant Model>  $builder
     * @return array<string, mixed>
     */
    protected function getFullTextOptions(Builder $builder)
    {
        $options = parent::getFullTextOptions($builder);
        $options['language'] ??= 'simple';

        if ($builder->modelConnectionType() !== 'pgsql') {
            $options['mode'] = 'boolean';
        }

        return $options;
    }
}
