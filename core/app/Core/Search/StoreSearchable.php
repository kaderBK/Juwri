<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Search;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Searchable;
use LogicException;

/**
 * P1-17 (J27-B, J29-4): Scout search for a store-owned model (a `store_id` column).
 *
 * The model lists the searched columns in toSearchableArray() (only the fields searched before Scout) and may mark
 * some with #[SearchUsingFullText([...], ['language' => 'simple'])] when a full-text index exists for them.
 *
 * Tenancy: callers search through searchInStores() — the store filter is part of the call, an empty list finds
 * nothing, and the tenant AccountScope still applies. The engine is always juwri's database engine
 * ({@see DatabaseSearchEngine}): an external engine (Meilisearch) needs index sync and store filters first.
 */
trait StoreSearchable
{
    use Searchable;

    /** @return DatabaseSearchEngine */
    public function searchableUsing()
    {
        $engine = app(EngineManager::class)->engine('database');
        if (! $engine instanceof DatabaseSearchEngine) {
            throw new LogicException('Scout "database" driver must resolve to '.DatabaseSearchEngine::class.' (SearchServiceProvider).');
        }

        return $engine;
    }

    /**
     * Search the given store(s) only.
     *
     * @param  int|list<int>  $storeIds
     * @return Builder<static>
     */
    public static function searchInStores(string $term, int|array $storeIds): Builder
    {
        $ids = array_map('intval', is_array($storeIds) ? $storeIds : [$storeIds]);
        $search = static::search(trim($term));

        return $search->whereIn($search->model->qualifyColumn('store_id'), $ids);
    }

    /**
     * The same search applied to an existing query (list screens keep their filters, ordering and pagination; the
     * caller's query carries the store scope).
     *
     * @param  EloquentBuilder<static>  $query
     */
    public function scopeWhereSearch(EloquentBuilder $query, string $term): void
    {
        $this->searchableUsing()->constrain($query, static::search(trim($term)));
    }
}
