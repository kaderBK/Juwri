<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P4-04 race helper (StockReserveRaceTest): one separate PHP process that boots the app on the same database as the
 * test, waits until the shared start time, then takes stock the way a storefront order does (Menu::reserveStock).
 * Prints "ok" (the units were taken) or "short" (refused, nothing taken).
 * Usage: php stock_race_child.php <menu id> <units> <go-at microtime> [variant id]
 * P6-103: with a variant id it takes item + variant cell in one transaction, as a storefront order line does.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$menuId, $units, $go] = [$argv[1], $argv[2], $argv[3]];
$variantId = (int) ($argv[4] ?? 0);
while (microtime(true) < (float) $go) {
    usleep(200);
}
if ($variantId === 0) {
    echo Menu::reserveStock((int) $menuId, (int) $units) ? 'ok' : 'short';
} else {
    try {
        DB::transaction(function () use ($menuId, $units, $variantId) {
            if (! Menu::reserveStock((int) $menuId, (int) $units) || ! MenuVariant::reserveStock($variantId, (int) $units)) {
                throw new UnderflowException('short');
            }
        });
        echo 'ok';
    } catch (UnderflowException) {
        echo 'short';
    }
}
