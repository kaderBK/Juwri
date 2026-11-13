<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Providers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Team module's store- and account-owned tables for the store-data registry — staff stay with the store (their owner_id follows the new owner); never copied.
 */
final class TeamStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('staff_members')->repoint(['owner_id'])->secret([
                'password' => fn (): string => Hash::make(Str::random(40)), // NOT NULL: an unusable password until reset
                'pin', 'pin_hash', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
            ]),
            StoreTable::data('staff_messages'),
            StoreTable::data('staff_passkeys'),
            StoreTable::data('staff_synced_ops'),
            StoreTable::data('staff_tokens')->notExported(), // sign-in sessions
            StoreTable::data('team_channel_members'),
            StoreTable::data('team_channels'),
            StoreTable::data('team_message_reads'),
            StoreTable::data('team_messages'),
        ];
    }
}
