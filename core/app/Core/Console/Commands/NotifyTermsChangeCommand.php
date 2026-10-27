<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Legal\TermsChangeNotice;
use Juwri\Core\Models\User;

/**
 * P1-23: "We're updating the Terms on <date>" (Terms 14.2: at least 30 days' notice of material changes) —
 * {@see TermsChangeNotice}. Without an argument (the daily schedule) it announces the newest Terms version
 * configured in config/legal.php once it is published with a future effective date. With a date it announces that
 * date by hand. Every active account owner (not a reseller's customer) gets the notice once per effective date;
 * a re-run only reaches new owners. JUWRI LTD's own install only: refused unless LEGAL_PAGES is on.
 */
class NotifyTermsChangeCommand extends Command
{
    protected $signature = 'legal:notify-terms-change
        {effective? : The date the new Terms take effect (YYYY-MM-DD); default: the newest configured Terms version}
        {--url= : Link to the new Terms (default: the Terms page)}
        {--non-material : With a date: the change is not material, so less than 30 days\' notice is allowed}
        {--dry-run : List the recipients without sending}';

    protected $description = 'Email account owners that the Terms change on a date (30 days notice)';

    public const STAMP = TermsChangeNotice::STAMP;

    public function handle(): int
    {
        if (! LegalDocuments::enabled()) {
            $this->warn('LEGAL_PAGES is off: this install does not publish JUWRI LTD\'s Terms, nothing to announce.');

            return $this->argument('effective') === null ? self::SUCCESS : self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $list = $dryRun ? fn (User $owner) => $this->line((string) $owner->email) : null;

        if ($this->argument('effective') === null) {
            $result = TermsChangeNotice::run($dryRun, $list);
            if ($result['status'] === 'none') {
                $this->info('No announced Terms version (the newest one is in force).');

                return self::SUCCESS;
            }
            if ($result['status'] === 'refused') {
                $this->error('Terms '.$result['version'].' ('.$result['effective'].') not announced: '.$result['reason'].'.');

                return self::FAILURE;
            }
            $this->info(($dryRun ? 'Would send ' : 'Sent ').$result['sent'].' notice(s) for '.$result['effective'].' (Terms '.$result['version'].').');

            return self::SUCCESS;
        }

        try {
            $effective = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->argument('effective'), 'UTC');
        } catch (\Throwable) {
            $effective = false;
        }
        if (! $effective || $effective->format('Y-m-d') !== $this->argument('effective')) {
            $this->error('The effective date must be YYYY-MM-DD.');

            return self::INVALID;
        }
        if (($reason = TermsChangeNotice::problem($effective, ! $this->option('non-material'))) !== null) {
            $this->error('Not announced: '.$reason.'.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: LegalDocuments::url(TermsChangeNotice::DOCUMENT));
        $sent = TermsChangeNotice::send($effective, $url, $dryRun, $list);
        $this->info(($dryRun ? 'Would send ' : 'Sent ').$sent.' notice(s) for '.$effective->format('Y-m-d').'.');

        return self::SUCCESS;
    }
}
