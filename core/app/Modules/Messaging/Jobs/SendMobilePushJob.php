<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;

/**
 * PARITY-RUN T9: delivers one mobile-app event to a store's NATIVE devices, off
 * the request cycle. Scoped inside MobilePushService to that store only.
 *
 * `tries = 1`: a notification is not worth retrying — by the time a retry lands
 * the order has usually moved on, and a retry would double-notify.
 */
class SendMobilePushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public function __construct(public int $storeId, public string $event, public array $payload) {}

    public function handle(): void
    {
        MobilePushService::deliver($this->storeId, $this->event, $this->payload);
    }
}
