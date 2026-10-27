<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Ai module's store- and account-owned tables for the store-data registry — every table is business data of the store: follows it on transfer, never copied.
 */
final class AiStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('ai_menu_actions'),
            StoreTable::owned('ai_usage'),
            StoreTable::owned('studio_credit_ledger_entries'),
            StoreTable::owned('studio_sessions'),
            StoreTable::data('translation_memory_entries'),
        ];
    }
}
