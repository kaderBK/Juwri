<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Juwri\Core\Models\BreachNotice;
use Juwri\Core\Services\Security\BreachNotices;

/**
 * P1-23: list the customers a personal-data breach affects, with each one's notice deadline (48 h from
 * confirmation; 24 h for a customer established in Kuwait), and — with --send — send them the breach notice and
 * record it. Without --send it only lists (a dry run). Re-running with --send skips customers already notified for
 * the same incident reference. Runbook: deploy/SECURITY-INCIDENT-RUNBOOK.md.
 */
class BreachNoticeCommand extends Command
{
    protected $signature = 'security:breach-notice
        {incident : incident reference, e.g. INC-2026-001}
        {--store=* : affected store id (repeatable)}
        {--account=* : affected account id (repeatable)}
        {--all : every account that is not closed}
        {--confirmed-at= : when the breach was confirmed (ISO 8601; default now)}
        {--summary= : what happened}
        {--data= : the categories of personal data affected}
        {--measures= : what we have done and what the customer should do}
        {--contact= : contact for questions (default BREACH_NOTICE_BCC)}
        {--send : send the notices (without it the command only lists)}';

    protected $description = 'List the customers affected by a personal-data breach with their notice deadlines, and send the breach notice';

    public function handle(BreachNotices $notices): int
    {
        $incident = trim((string) $this->argument('incident'));
        if (! preg_match('/^[A-Za-z0-9._-]{3,40}$/', $incident)) {
            $this->error('The incident reference must be 3–40 letters, digits, dots, dashes or underscores.');

            return self::INVALID;
        }

        $stores = array_values(array_filter(array_map('intval', (array) $this->option('store'))));
        $accounts = array_values(array_filter(array_map('intval', (array) $this->option('account'))));
        $all = (bool) $this->option('all');
        if (! $all && $stores === [] && $accounts === []) {
            $this->error('Name the affected scope: --store=ID, --account=ID (repeatable) or --all.');

            return self::INVALID;
        }

        try {
            $confirmedAt = $this->option('confirmed-at') ? CarbonImmutable::parse((string) $this->option('confirmed-at')) : CarbonImmutable::now();
        } catch (\Throwable) {
            $this->error('--confirmed-at is not a valid date and time.');

            return self::INVALID;
        }

        $recipients = $notices->recipients($stores, $accounts, $all);
        $sent = BreachNotice::where('incident_ref', $incident)->whereNotNull('sent_at')->pluck('sent_at', 'recipient_user_id');

        $this->table(
            ['Account', 'Owner', 'Email', 'Country', 'Notice by (UTC)', 'Reseller', 'Status'],
            $recipients->map(fn ($r) => [
                $r['account_id'],
                $r['name'],
                $r['email'] !== '' ? $r['email'] : '(none)',
                ($r['country'] ?? '?').($r['kuwait'] ? ' (24 h)' : ''),
                $confirmedAt->addHours($r['hours'])->utc()->format('Y-m-d H:i'),
                $r['reseller_id'] ?? '',
                isset($sent[$r['user_id']]) ? 'notified '.$sent[$r['user_id']] : 'not notified',
            ])->all()
        );
        $this->line($recipients->count().' customer(s); confirmed '.$confirmedAt->utc()->format('Y-m-d H:i').' UTC.');

        $late = $recipients->filter(fn ($r) => ! isset($sent[$r['user_id']]) && $confirmedAt->addHours($r['hours'])->isPast());
        if ($late->isNotEmpty()) {
            $this->warn($late->count().' customer(s) are past their notice deadline: send now and record why it was late.');
        }
        if ($recipients->contains(fn ($r) => $r['reseller_id'] !== null)) {
            $this->warn('Some accounts belong to a reseller: also notify each reseller (Reseller Agreement, data protection schedule).');
        }

        if (! $this->option('send')) {
            $this->info('Dry run: nothing sent. Add --send with --summary, --data and --measures to send the notices.');

            return self::SUCCESS;
        }

        $details = [
            'summary' => trim((string) $this->option('summary')),
            'data' => trim((string) $this->option('data')),
            'measures' => trim((string) $this->option('measures')),
            'contact' => trim((string) ($this->option('contact') ?: config('platform.breach_notice.bcc'))),
        ];
        foreach ($details as $key => $value) {
            if ($value === '') {
                $this->error("--{$key} is required with --send (DPA 6.2: the notice must say what happened, what data, what was done and whom to contact).");

                return self::INVALID;
            }
        }

        $result = $notices->send($incident, $confirmedAt, $details, $recipients);
        $this->info("Sent {$result['sent']}, already notified {$result['skipped']}, failed ".count($result['failed']).'.');
        foreach ($result['failed'] as $who => $why) {
            $this->error("  {$who}: {$why}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
