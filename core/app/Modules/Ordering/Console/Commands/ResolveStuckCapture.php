<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Ordering\Services\Payments\CardHoldOrders;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * grocery-nits2 (REVIEW, Opus: money): the admin path for a card-hold capture stuck in `capturing` (logged as
 * `payment.capture_unknown`: the provider's answer was lost on a gateway whose captures cannot be retried safely).
 * After checking the payment in the provider's dashboard, the platform admin records what happened:
 *
 *   php artisan orders:resolve-capture {transaction} --captured --reference=<provider capture id> --by=<who checked>
 *   php artisan orders:resolve-capture {transaction} --not-captured --by=<who checked>
 *
 * Captured: the order is captured for the stored request's amount and settled like a capture that answered. Not
 * captured: the hold is authorised again and the next finish sends a new capture request. Audited
 * (`payment.capture_resolved`); refused unless the payment is still `capturing`, so it is resolved once.
 */
class ResolveStuckCapture extends Command
{
    protected $signature = 'orders:resolve-capture
        {transaction : The id of the transaction stuck in capturing}
        {--captured : The provider shows the capture was taken}
        {--not-captured : The provider shows nothing was captured}
        {--reference= : The provider\'s capture id (with --captured)}
        {--by= : Who checked the payment with the provider}';

    protected $description = 'Resolve a card-hold capture stuck after an unknown outcome, once checked with the provider';

    public function handle(CardHoldOrders $holds): int
    {
        $captured = (bool) $this->option('captured');
        if ($captured === (bool) $this->option('not-captured')) {
            $this->error('Say what the provider shows: --captured or --not-captured.');

            return self::FAILURE;
        }
        $transaction = Transaction::query()->find((int) $this->argument('transaction'));
        if ($transaction === null) {
            $this->error('No such transaction.');

            return self::FAILURE;
        }
        $reference = trim((string) $this->option('reference'));
        $by = trim((string) $this->option('by'));
        // money-tail 1: a mark-captured always names the provider's capture id and who checked it
        if ($captured && ($reference === '' || $by === '')) {
            $this->error('--captured needs --reference=<provider capture id> and --by=<who checked>.');

            return self::FAILURE;
        }
        $result = $holds->resolveStuckCapture($transaction, $captured, $reference !== '' ? $reference : null, $by !== '' ? $by : 'console');
        if (! $result['ok']) {
            $this->error((string) $result['message']);

            return self::FAILURE;
        }
        $this->info($captured ? 'Recorded as captured and settled.' : 'The card hold is open again; the next capture is a new request.');

        return self::SUCCESS;
    }
}
