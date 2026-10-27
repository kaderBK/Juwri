<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Console\Commands;

use Juwri\Modules\Integrations\Jobs\PullGbpReviewsJob;
use Juwri\Core\Models\Credential;
use Illuminate\Console\Command;

/**
 * GOOGLE-PARTNER Lane B / B2: dispatch PullGbpReviewsJob for every store with a
 * connected GBP location. DARK: a platform with no GBP-connected store dispatches
 * zero jobs (the job itself is also self-gating). Scheduled daily from Kernel.
 */
class GbpPullReviews extends Command
{
    protected $signature = 'gbp:pull-reviews';

    protected $description = 'Refresh cached Google Business Profile reviews for connected stores (Lane B).';

    public function handle(): int
    {
        $dispatched = 0;

        Credential::query()
            ->where('feature', 'gbp')
            ->where('active', true)
            ->whereNotNull('store_id')
            ->cursor()
            ->each(function (Credential $cred) use (&$dispatched) {
                $secrets = $cred->secrets ?? [];
                if (empty($secrets['location_id'])) {
                    return; // connected-but-no-location, or platform default — skip
                }
                PullGbpReviewsJob::dispatch((int) $cred->store_id);
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} GBP review pull job(s).");

        return self::SUCCESS;
    }
}
