<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Modules\Messaging\Contracts\StaffPush;

/**
 * Sends the new-order push to a restaurant's staff off the request cycle. Scoped in
 * StaffPushService to that restaurant only.
 */
class SendStaffOrderPushJob implements ShouldQueue
{
    use Queueable;

    /** P6-64: bounded retries; timeout stays below queue retry_after. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    public function __construct(public int $storeId, public array $payload) {}

    public function handle(StaffPush $service): void
    {
        $service->sendNewOrder($this->storeId, $this->payload);
    }
}
