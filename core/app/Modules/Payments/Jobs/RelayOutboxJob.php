<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Modules\Payments\Contracts\Outbox;

/** J25-7: perform due outbox messages (platform job — each message carries its own ids). */
class RelayOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    /** @param  list<int>|null  $ids */
    public function __construct(public ?array $ids = null) {}

    public function handle(Outbox $outbox): void
    {
        $outbox->relay($this->ids);
    }
}
