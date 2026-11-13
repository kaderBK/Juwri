<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Console;

use Illuminate\Console\Command;
use Juwri\Modules\Agents\Services\Telephony\VoiceCostAnomaly;

/**
 * P6-09 V-20: run the phone-agent voice cost-anomaly scan and print how many store anomalies were found
 * (the alert rows themselves are written by {@see VoiceCostAnomaly}).
 */
class VoiceCostAnomalyCheck extends Command
{
    protected $signature = 'voice:cost-anomaly-check';

    protected $description = 'Alert on stores whose phone-agent voice minutes spiked vs their 7-day average.';

    public function handle(VoiceCostAnomaly $anomaly): int
    {
        $found = $anomaly->check();

        $this->info('Phone agent voice cost anomalies: '.count($found).'.');

        return self::SUCCESS;
    }
}
