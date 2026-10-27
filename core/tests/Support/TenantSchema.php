<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/** The live tenant schema (tables with store_id / account_id and their flags), as TenantTables lists it. */
final class TenantSchema
{
    /** @return array<string, array{store: bool, account: bool, required: bool, account_required: bool}> */
    public static function live(): array
    {
        $live = [];
        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            if (in_array($name, ['users', 'accounts'], true)) {
                continue;
            }
            $cols = collect(Schema::getColumns($name))->keyBy('name');
            if (! $cols->has('store_id') && ! $cols->has('account_id')) {
                continue;
            }
            $live[$name] = [
                'store' => $cols->has('store_id'),
                'account' => $cols->has('account_id'),
                'required' => $cols->has('store_id') && ! $cols['store_id']['nullable'],
                'account_required' => $cols->has('account_id') && ! $cols['account_id']['nullable'],
            ];
        }
        ksort($live);

        return $live;
    }
}
