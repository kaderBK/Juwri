<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Console\Commands;

use App\Services\Team\ChecklistService;
use Illuminate\Console\Command;

/**
 * SYSTEMS-RUN T-P2: generate today's checklist runs from active templates.
 * Idempotent (firstOrCreate per template+date+branch).
 */
class ChecklistsGenerate extends Command
{
    protected $signature = 'checklists:generate {--date=}';

    protected $description = 'Generate dated checklist runs from active templates.';

    public function handle(ChecklistService $service): int
    {
        $n = $service->generateForDate($this->option('date'));
        $this->info("Checklist runs ensured: {$n}.");
        return self::SUCCESS;
    }
}
