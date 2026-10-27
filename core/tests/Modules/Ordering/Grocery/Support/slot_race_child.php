<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P3-09 race helper (DeliverySlotCapacityRaceTest): one separate PHP process that boots the app on the same database
 * as the test, waits until the shared start time, then asks for a delivery-slot hold. Prints "ok" or the refusal code.
 * Usage: php slot_race_child.php <store id> <slot id> <holder> <go-at microtime>
 */

use Illuminate\Contracts\Console\Kernel;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Data\SlotException;
use Juwri\Modules\Ordering\Services\Slots\SlotHolds;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $storeId, $slotId, $holder, $go] = $argv;
$store = Store::query()->findOrFail((int) $storeId);
while (microtime(true) < (float) $go) {
    usleep(200);
}
try {
    app(SlotHolds::class)->hold($store, null, $slotId, $holder, null);
    echo 'ok';
} catch (SlotException $e) {
    echo $e->errorCode;
}
