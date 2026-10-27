<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Services\Tenancy\StoreDataScope;

/** P1-21 part 2: `--account=` xor `--store=` of the store-data commands. */
final class StoreDataCommandScope
{
    public static function from(Command $command): ?StoreDataScope
    {
        $account = $command->option('account');
        $store = $command->option('store');
        if (($account === null) === ($store === null) || ! ctype_digit((string) ($account ?? $store))) {
            $command->error('Pass exactly one of --account=<id> or --store=<id>.');

            return null;
        }

        return $account !== null ? StoreDataScope::account((int) $account) : StoreDataScope::store((int) $store);
    }
}
