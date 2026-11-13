<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Providers;

use Illuminate\Database\ConnectionInterface;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;

/**
 * P1-21 (J29-8): the Agents module's store-owned tables for the store-data registry — the previous owner's phone
 * lines (their telephony account) are switched off on transfer; conversations and call logs follow the store.
 * Never copied.
 */
final class AgentsStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('agent_conversations'),
            // V-18: recorded audio follows the store on transfer / is deleted with it. files() supports one FIXED disk
            // per column, but a call log's disk lives per row in call_logs.audio_disk — recordings are private media,
            // so the configured recordings disk (voice.recordings_disk, default 'local') is declared here. Recording
            // writes must use that disk for the transfer to find them.
            StoreTable::data('call_logs')->files(['audio_path' => (string) config('voice.recordings_disk', 'local')]),
            StoreTable::data('voice_caller_blocks'),
            StoreTable::connection('phone_lines', fn (ConnectionInterface $db, StoreTransferContext $ctx): int => $db->table('phone_lines')->where('store_id', $ctx->storeId)->update(['active' => false])),
        ];
    }
}
