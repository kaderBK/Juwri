<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P3-10 race helper (BookingDoubleBookingRaceTest): one separate PHP process that boots the app on the same database
 * as the test, waits until the shared start time, then asks for a hold. Prints "ok <staff id>" or the refusal code.
 * Usage: php hold_race_child.php <store id> <service id> <staff id|any> <start epoch> <go-at microtime>
 */

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Services\HoldService;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $storeId, $serviceId, $staff, $start, $go] = $argv;
$store = Store::query()->findOrFail((int) $storeId);
while (microtime(true) < (float) $go) {
    usleep(200);
}
try {
    $made = app(HoldService::class)->hold($store, [(int) $serviceId], $staff === 'any' ? null : (int) $staff, CarbonImmutable::createFromTimestampUTC((int) $start), 'venue', null);
    echo 'ok '.$made['hold']->staff_id;
} catch (BookingException $e) {
    echo $e->errorCode;
}
