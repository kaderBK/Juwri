<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Services\MerchantCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Send a bulk email campaign to the platform's merchants.
 *
 * The same engine the admin action uses, so a campaign can be run from the
 * command line (or from cron) and picked up exactly as it was previewed in
 * the admin.
 *
 *   php artisan merchants:campaign self-hosted-offer --dry-run
 *   php artisan merchants:campaign self-hosted-offer --plan=free --country="Saudi Arabia"
 *   php artisan merchants:campaign self-hosted-offer --user-ids=12,34,56 --per-minute=30
 */
class MerchantCampaignCommand extends Command
{
    protected $signature = 'merchants:campaign
        {campaign : the campaign key (used for idempotency and the report)}
        {--plan=any : any | free | trial | <plan id>}
        {--country=any : any | the country the merchant registered with}
        {--trial=any : any | ending | ended}
        {--trial-within=7 : days ahead for --trial=ending}
        {--has-subscription= : 1 to target merchants with a subscription}
        {--user-ids= : a comma separated list of merchant ids}
        {--limit= : cap the number of recipients}
        {--subject= : subject line (defaults to the admin template)}
        {--body= : message body (defaults to the admin template)}
        {--per-minute=60 : how many emails to queue per minute}
        {--dry-run : report what would be sent, send nothing}';

    protected $description = 'Send a bulk email campaign to merchants (idempotent per campaign)';

    public function handle(MerchantCampaign $campaign): int
    {
        $dry = (bool) $this->option('dry-run');
        $key = (string) $this->argument('campaign');

        $criteria = [
            'campaign' => $key,
            'filters' => [
                'plan' => (string) $this->option('plan'),
                'country' => (string) $this->option('country'),
                'trial' => (string) $this->option('trial'),
                'trial_within' => (int) $this->option('trial-within'),
                'has_subscription' => (string) $this->option('has-subscription'),
            ],
            'limit' => (int) $this->option('limit'),
        ];

        $ids = trim((string) $this->option('user-ids'));
        if ($ids !== '') {
            $criteria['user_ids'] = array_filter(array_map('intval', explode(',', $ids)));
        }

        $preview = $campaign->preview($criteria);

        $this->info("=== campaign: {$key} ===");
        $this->line('   matched:    '.$preview['total']);
        $this->line('   to send:    '.$preview['to_send']);
        $this->line('   already:    '.$preview['already']);
        $this->line('   opted out:  '.$preview['opted_out']);
        $this->line('   no email:   '.$preview['no_email']);

        if ($dry) {
            $this->line('   (dry run — nothing sent)');

            return self::SUCCESS;
        }

        $subject = $this->option('subject');
        $body = $this->option('body');

        $stats = $campaign->send(
            $criteria,
            $key,
            is_string($subject) && $subject !== '' ? $subject : null,
            is_string($body) && $body !== '' ? $body : null,
            false,
            (int) $this->option('per-minute')
        );

        $this->info((string) json_encode($stats));

        if (! $this->queueIsBeingWorked()) {
            $this->warn('   Note: the mails are queued, but no queue worker looked to be running.');
            $this->warn('   Start one with: php artisan queue:work --queue=default');
        }

        return self::SUCCESS;
    }

    /**
     * Is something draining the queue? The oldest queued job older than a few
     * minutes means nothing is.
     */
    protected function queueIsBeingWorked(): bool
    {
        try {
            $jobs = DB::table('jobs')->oldest('created_at')->first();
            if (! $jobs) {
                return true;   // nothing queued: nothing to drain
            }

            return now()->getTimestamp() - (int) $jobs->created_at < 300;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
