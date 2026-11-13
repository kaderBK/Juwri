<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Payments\Contracts\Outbox;

/** J25-7: perform due outbox messages (scheduled every minute; safe to run by hand). */
class RelayOutbox extends Command
{
    protected $signature = 'payments:outbox-relay {--limit=500 : messages per run}';

    protected $description = 'Perform pending outbox messages (payment side effects) and retry failed ones.';

    public function handle(Outbox $outbox): int
    {
        $done = $outbox->relay(null, max(1, (int) $this->option('limit')));
        $this->info("{$done} outbox message(s) performed.");

        return self::SUCCESS;
    }
}
