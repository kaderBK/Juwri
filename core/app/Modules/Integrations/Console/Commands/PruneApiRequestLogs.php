<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Console\Commands;

use Juwri\Modules\Integrations\Models\ApiRequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * HPMS E11: keep only the last 7 days of API request logs. Scheduled daily.
 */
class PruneApiRequestLogs extends Command
{
    protected $signature = 'api:prune-request-logs';

    protected $description = 'Prune api_request_logs older than 7 days (E11)';

    public function handle(): int
    {
        if (! Schema::hasTable('api_request_logs')) {
            return self::SUCCESS;
        }
        $deleted = ApiRequestLog::where('created_at', '<', now()->subDays(7))->delete();
        $this->info("Pruned {$deleted} API request log(s).");

        return self::SUCCESS;
    }
}
