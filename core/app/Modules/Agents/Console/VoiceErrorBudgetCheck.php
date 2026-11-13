<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Console;

use Illuminate\Console\Command;
use Juwri\Modules\Agents\Services\Telephony\VoiceErrorBudget;

/** P6-33: hourly phone-agent error-budget check (alerts are written by {@see VoiceErrorBudget}). */
class VoiceErrorBudgetCheck extends Command
{
    protected $signature = 'voice:error-budget-check';

    protected $description = 'Alert on stores whose phone agent exceeded its error budget in the previous hour.';

    public function handle(VoiceErrorBudget $budget): int
    {
        $found = $budget->check();

        $this->info('Phone agent error-budget breaches: '.count($found).'.');

        return self::SUCCESS;
    }
}
