<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Console\Commands;

use Juwri\Modules\Accounting\Services\Accounting\ExpenseService;
use Illuminate\Console\Command;

/**
 * SYSTEMS-RUN A-P2: generate due recurring expenses (daily). Idempotent — a
 * template only spawns children up to today, advancing its recur_next past it.
 */
class ExpensesGenerateRecurring extends Command
{
    protected $signature = 'expenses:generate-recurring {--date= : run as-of date (YYYY-MM-DD)}';

    protected $description = 'Generate due recurring expenses and post their journals.';

    public function handle(ExpenseService $service): int
    {
        $created = $service->generateRecurring($this->option('date'));
        $this->info("Recurring expenses generated: {$created}.");
        return self::SUCCESS;
    }
}
