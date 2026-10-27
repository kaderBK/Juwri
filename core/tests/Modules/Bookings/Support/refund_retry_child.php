<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Lane C race helper (BookingDepositRefundRetryRaceTest): one separate PHP process that boots the app on the same
 * database as the test, waits until the shared start time, then retries the booking's pending deposit refund on the
 * armed Stub gateway. Prints the outcome (refunded | pending | nothing) or "error: …".
 * Usage: php refund_retry_child.php <booking id> <go-at microtime>
 */

use Illuminate\Contracts\Console\Kernel;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Services\DepositService;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.e2e_stubs' => true]);

[, $bookingId, $go] = $argv;
$booking = Booking::query()->findOrFail((int) $bookingId);
while (microtime(true) < (float) $go) {
    usleep(200);
}
try {
    echo app(DepositService::class)->retryRefund($booking)['outcome'];
} catch (Throwable $e) {
    echo 'error: '.get_class($e).' '.substr($e->getMessage(), 0, 300);
}
