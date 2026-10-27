<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\Ledger;

/**
 * Money that is HELD at cut-over and that this install will later release through its ledger (J59g). The source ledger
 * itself is back-office data and is not imported (J58c), so without these legs a later refund or check-out would post
 * against an empty account (refused as an overdraft after the gateway already refunded, or a guest checking out with
 * their earlier room charges forgotten).
 *
 *  - Held deposits (reservations / catering, paid or owed back): the payment already moved to the store
 *    ({@see ScopeMaps::reservations()}); the two holding legs are booked exactly as this install books a deposit payment
 *    (same accounts, same idempotency refs), so a refund, keep or manual refund later balances.
 *  - Open stays: the source folio's balance (sum of its source ledger rows, minor units) becomes ONE opening charge on
 *    the stay's folio here, pinned on the stay; a folio in credit, or one whose source ledger cannot be read, is not
 *    opened and is reported for the owner to settle.
 *
 * Runs inside the store's transaction: a store that rolls back takes its legs with it.
 */
final class LegacyLedgerOpenings
{
    /** Source ledger tables (read only here; never imported, J58c). */
    public const SOURCE_ENTRIES = 'ledger_entries';

    public const SOURCE_ACCOUNTS = 'ledger_accounts';

    public function __construct(private readonly ImportContext $ctx) {}

    /**
     * Book the holding legs of the held deposits among the rows just written.
     *
     * @param  list<int>  $ids
     */
    public function deposits(string $table, array $ids): void
    {
        $prefix = $table === 'reservations' ? 'resdep' : 'catdep';
        $rows = $this->ctx->target->table($table)->whereIn('id', $ids)->whereIn('deposit_status', ScopeMaps::HELD_DEPOSITS)
            ->whereNotNull('deposit_transaction_id')->orderBy('id')->get(['id', 'deposit_amount_minor', 'currency', 'deposit_transaction_id']);
        if ($rows->isEmpty()) {
            return;
        }
        $ledger = app(Ledger::class);
        $owner = (int) $this->ctx->target->table('stores')->where('id', (int) $this->ctx->storeId)->value('user_id');
        foreach ($rows as $row) {
            $minor = (int) $row->deposit_amount_minor;
            $payment = (int) $row->deposit_transaction_id;
            $currency = (string) ($row->currency ?: $this->ctx->currency);
            if ($minor <= 0) {
                continue;
            }
            $amount = Money::majorFromMinor($minor, $currency);
            $merchant = $ledger->accountFor('merchant', $owner, 'merchant', $currency);
            $clearing = $ledger->accountFor('platform', 0, 'platform', $currency);
            $ledger->post([
                ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => $prefix.':pay:'.$payment.':clr'],
                ['account' => $merchant, 'amount' => $amount, 'ref' => $prefix.':pay:'.$payment.':mer'],
            ], null, $table === 'reservations' ? 'reservation_deposit.pay' : 'catering_deposit.pay');
            $this->ctx->mark($table, (int) $row->id, ScopeMaps::DEPOSIT_HELD, ['amount_minor' => $minor, 'currency' => $currency, 'payment_id' => $payment]);
            $this->ctx->report->count($table.'_deposits_held');
        }
    }

    /**
     * Open the folio of each stay just written with its source balance.
     *
     * @param  list<int>  $ids
     */
    public function folios(array $ids): void
    {
        $source = $this->ctx->source;
        $readable = $source->has(self::SOURCE_ENTRIES) && $source->has(self::SOURCE_ACCOUNTS);
        $ledger = app(Ledger::class);
        foreach ($ids as $id) {
            $row = $source->find('hotel_stays', $id);
            $folio = (int) ($row['folio_account_id'] ?? 0);
            if ($folio <= 0) {
                continue;
            }
            if (! $readable) {
                $this->ctx->report->conflict('hotel_stays: source folio ledger not readable, open folio not carried over (settle by hand)', 'hotel_stays#'.$id);

                continue;
            }
            $account = $source->find(self::SOURCE_ACCOUNTS, $folio);
            $currency = strtoupper(trim((string) ($account['currency'] ?? '')));
            $exponent = $this->ctx->exponentOf($currency);
            if ($account === null || $exponent === null || ($account['kind'] ?? null) !== 'hotel_folio') {
                $this->ctx->report->conflict('hotel_stays: source folio account missing or currency unknown, open folio not carried over (settle by hand)', 'hotel_stays#'.$id);

                continue;
            }
            $balance = 0;
            foreach ($source->where(self::SOURCE_ENTRIES, 'account_id', $folio) as $entry) {
                $minor = LegacyMoney::toMinor($entry['amount'] ?? null, $exponent);
                if ($minor === null) {
                    $this->ctx->report->conflict('hotel_stays: source folio row not numeric, open folio not carried over (settle by hand)', 'hotel_stays#'.$id);

                    continue 2;
                }
                $balance += $minor;
            }
            if ($balance < 0) {
                $this->ctx->report->conflict('hotel_stays: source folio in credit, not carried over (settle by hand)', 'hotel_stays#'.$id);

                continue;
            }
            $target = $ledger->accountFor('hotel_stay', $id, 'hotel_folio', $currency);
            $this->ctx->target->table('hotel_stays')->where('id', $id)->update(['folio_account_id' => $target->id]);
            if ($balance === 0) {
                continue;
            }
            $amount = Money::majorFromMinor($balance, $currency);
            $clearing = $ledger->accountFor('restaurant', (int) $this->ctx->storeId, 'hotel_folio_clearing', $currency);
            $ref = 'legacy_folio:stay:'.$id;
            $ledger->post([
                ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => $ref.':clr', 'reason' => 'Opening balance (moved from the old platform)'],
                ['account' => $target, 'amount' => $amount, 'ref' => $ref.':folio', 'reason' => 'Opening balance (moved from the old platform)', 'meta' => ['stay_id' => $id]],
            ], null, 'hotel_folio.legacy_opening');
            $this->ctx->mark('hotel_stays', $id, ScopeMaps::FOLIO_OPENED, ['amount_minor' => $balance, 'currency' => $currency]);
            $this->ctx->report->count('hotel_stays_folio_opened');
        }
    }
}
