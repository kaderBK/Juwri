<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Console;

use App\Models\Campaign;
use Illuminate\Console\Command;
use Juwri\Modules\Marketing\Jobs\SendCampaignJob;

/**
 * P6-319: a SendCampaignJob that died mid-build leaves a campaign stuck in
 * 'sending' and its already-created recipients stuck in 'pending'. Reset any
 * 'sending' campaign that has not moved in 30 minutes back to 'scheduled' and
 * re-dispatch its (resumable) build, which also re-dispatches the pending rows.
 */
class SweepStuckCampaigns extends Command
{
    protected $signature = 'campaigns:sweep-stuck';

    protected $description = 'Reset stuck sending campaigns and re-queue their build';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(30);
        $count = 0;

        Campaign::where('status', 'sending')
            ->where('updated_at', '<', $cutoff)
            ->chunkById(100, function ($campaigns) use (&$count) {
                foreach ($campaigns as $campaign) {
                    $campaign->update(['status' => 'scheduled']);
                    SendCampaignJob::dispatch($campaign->id);
                    $count++;
                }
            });

        $this->info("{$count} stuck campaign(s) re-queued.");

        return self::SUCCESS;
    }
}
