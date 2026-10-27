<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-17 (J27-B): Laravel Scout, database driver (Community edition). Juwri's searchable models (menu items,
 * customers) always use the database engine (Juwri\Core\Search\StoreSearchable); Meilisearch is optional later and
 * needs index sync + store filters before a model can use it. Keys not listed here come from Scout's own config.
 */
return [
    'driver' => 'database',

    // The database engine reads the tables directly: nothing to queue or sync.
    'queue' => false,

    'after_commit' => false,

    // Soft-deleted rows stay out of search results (the model's SoftDeletes scope).
    'soft_delete' => false,

    'identify' => false,
];
