<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Search\DatabaseSearchEngine;
use Laravel\Scout\EngineManager;

/**
 * P1-17 (J27-B): Scout's `database` driver resolves to juwri's DatabaseSearchEngine (literal LIKE + full-text where
 * the database has it; see that class).
 */
class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(EngineManager::class, function (EngineManager $engines): void {
            $engines->extend('database', fn (): DatabaseSearchEngine => new DatabaseSearchEngine);
        });
    }
}
