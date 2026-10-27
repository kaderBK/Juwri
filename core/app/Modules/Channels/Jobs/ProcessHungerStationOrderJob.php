<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationOrderService;

/**
 * Processes a HungerStation order webhook off the request cycle. Idempotent via
 * HungerStationOrderService (delivery_orders unique), so a re-delivered webhook is safe.
 */
class ProcessHungerStationOrderJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    /** P6-64: bounded retries; timeout stays below queue retry_after. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    public function __construct(public array $payload) {}

    public function handle(HungerStationOrderService $service): void
    {
        $service->ingest($this->payload);
    }
}
