<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P3-11 race helper (LaundryHoldRaceTest): one separate PHP process that boots the app on the same database as the
 * test, waits until the shared start time, then asks for a laundry hold. Prints "ok" or the refusal code.
 * Usage: php laundry_hold_race_child.php <store id> <mode> <pickup slot id> <return slot id|-> <go-at microtime>
 */

use Illuminate\Contracts\Console\Kernel;
use Juwri\Core\Models\Store;
use Juwri\Modules\Laundry\Exceptions\LaundryHoldException;
use Juwri\Modules\Laundry\Services\Laundry\LaundryHolds;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $storeId, $mode, $pickup, $return, $go] = $argv;
$store = Store::query()->findOrFail((int) $storeId);
while (microtime(true) < (float) $go) {
    usleep(200);
}
try {
    app(LaundryHolds::class)->hold($store, $mode, $pickup, $return === '-' ? null : $return, null);
    echo 'ok';
} catch (LaundryHoldException $e) {
    echo $e->errorCode;
}
