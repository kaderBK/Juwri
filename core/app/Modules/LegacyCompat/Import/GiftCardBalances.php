<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Support\Str;

/**
 * Gift-card balances (plan §4, DDL diff §4.3). The source keeps no balance column: a card's remaining value is its
 * `initial_value` minus the `gift_card_applied` of every order that names it (`orders.gift_card_id`). This install
 * reads a card's spendable value from its ledger account only (kind `gift_card`), so each imported card gets ONE
 * opening-balance posting of its remaining value (platform clearing −r, card +r), marked `opening_balance`.
 *
 * Like loyalty, a mismatch is never "fixed": a card whose orders applied more than its initial value, or that was
 * applied to an order in another currency (the amounts cannot be summed), BLOCKS the store — checked on the source
 * before anything is written, and again on the written rows before commit (where the written redemptions must equal
 * the source's). A card named by another store's order blocks its store too. Only an `active` card gets an opening
 * balance.
 */
final class GiftCardBalances
{
    public const CARDS = 'gift_cards';

    public const REASON = 'gift card redemptions exceed the card value, are in another currency, or are on another store';

    public const OPENING = 'opening_balance';

    public const REF_PREFIX = 'legacy:gc:';

    public function __construct(private readonly ImportContext $ctx) {}

    /**
     * Source cards of $storeId that cannot be opened: redeemed for more than their value, redeemed in another currency,
     * or named by an order of ANOTHER store (that spend would be counted nowhere).
     *
     * @return list<int> card ids
     */
    public function sourceMismatches(int $storeId, string $storeCurrency): array
    {
        $ids = array_keys($this->source($storeId, $storeCurrency)[1]);
        sort($ids);

        return $ids;
    }

    /**
     * Per source card of $storeId: what this store's orders applied (minor units of the card currency), and the cards
     * that cannot be opened (see {@see sourceMismatches()}).
     *
     * @return array{0: array<int, int>, 1: array<int, true>}
     */
    private function source(int $storeId, string $storeCurrency): array
    {
        $source = $this->ctx->source;
        $cardStore = $source->pick(self::CARDS, LegacyTableMaps::STORE_KEYS);
        $orderStore = $source->pick('orders', LegacyTableMaps::STORE_KEYS);
        $orderCols = $source->columns('orders');
        if ($cardStore === null || $orderStore === null || ! in_array('gift_card_id', $orderCols, true) || ! in_array('gift_card_applied', $orderCols, true)) {
            return [[], []];
        }
        /** @var array<int, array{0: string, 1: int|null, 2: int|null}> $cards id => [currency, exponent, initial minor] */
        $cards = [];
        $source->chunk(self::CARDS, function (array $rows) use (&$cards): void {
            foreach ($rows as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                $exponent = $this->ctx->exponentOf($currency);
                $cards[(int) $row['id']] = [$currency, $exponent, $exponent === null ? null : $this->ctx->minor($row['initial_value'] ?? null, $exponent)];
            }
        }, $cardStore, [$storeId]);
        if ($cards === []) {
            return [[], []];
        }
        $hasCurrency = in_array('currency_code', $orderCols, true);
        $applied = [];
        $bad = [];
        $source->chunk('orders', function (array $rows) use (&$applied, &$bad, $cards, $storeId, $orderStore, $storeCurrency, $hasCurrency): void {
            foreach ($rows as $row) {
                $card = (int) ($row['gift_card_id'] ?? 0);
                if ($card === 0 || ! isset($cards[$card])) {
                    continue;
                }
                if ((int) ($row[$orderStore] ?? 0) !== $storeId) {
                    $bad[$card] = true; // another store's order names this store's card

                    continue;
                }
                $amount = $row['gift_card_applied'] ?? null;
                if ($amount === null || $amount === '' || (float) $amount === 0.0) {
                    continue;
                }
                [$currency, $exponent] = $cards[$card];
                $orderCurrency = $hasCurrency ? strtoupper(trim((string) ($row['currency_code'] ?? ''))) : '';
                $orderCurrency = $orderCurrency === '' ? $storeCurrency : $orderCurrency;
                $minor = $exponent === null ? null : $this->ctx->minor($amount, $exponent);
                if ($orderCurrency !== $currency || $minor === null) {
                    $bad[$card] = true;

                    continue;
                }
                $applied[$card] = ($applied[$card] ?? 0) + $minor;
            }
        }, 'gift_card_id', array_keys($cards));
        foreach ($cards as $id => [, , $initial]) {
            if ($initial === null || ($applied[$id] ?? 0) > $initial) {
                $bad[$id] = true;
            }
        }

        return [$applied, $bad];
    }

    /**
     * Re-check the written cards against the written orders, then post each card's remaining value as its opening
     * balance. Returns the sum of the opening balances (minor units; cards share the store currency in practice).
     *
     * @throws StoreBlocked
     */
    public function open(int $storeId): int
    {
        $cards = $this->ctx->storeIdsOf('gift_cards');
        if ($cards === []) {
            return 0;
        }
        $target = $this->ctx->target;
        $applied = [];
        $foreign = [];
        $rows = $target->table('orders')->where('store_id', $storeId)->whereNotNull('gift_card_id')
            ->get(['gift_card_id', 'gift_card_applied_minor', 'currency_code']);
        $currencies = $target->table('gift_cards')->where('store_id', $storeId)->pluck('currency', 'id')->all();
        foreach ($rows as $row) {
            $card = (int) $row->gift_card_id;
            if ((int) $row->gift_card_applied_minor === 0) {
                continue;
            }
            if ((string) $row->currency_code !== (string) ($currencies[$card] ?? $currencies[(string) $card] ?? '')) {
                $foreign[$card] = true;
            }
            $applied[$card] = ($applied[$card] ?? 0) + (int) $row->gift_card_applied_minor;
        }
        // Every source redemption must have landed: an order refused or skipped on the way would leave the card richer.
        [$sourceApplied] = $this->source($storeId, (string) $this->ctx->currency);
        $sum = 0;
        $now = now()->utc()->format('Y-m-d H:i:s');
        foreach ($target->table('gift_cards')->where('store_id', $storeId)->orderBy('id')->get(['id', 'currency', 'initial_value_minor', 'status']) as $card) {
            $id = (int) $card->id;
            $remaining = (int) $card->initial_value_minor - ($applied[$id] ?? 0);
            if (isset($foreign[$id]) || $remaining < 0 || ($applied[$id] ?? 0) !== ($sourceApplied[$id] ?? 0)) {
                throw new StoreBlocked(self::REASON.' after import (gift_card#'.$id.')');
            }
            // Only an active card holds spendable value here (pending = never funded; void / used / expired = none).
            if ($remaining === 0 || $card->status !== 'active') {
                continue;
            }
            $this->post($id, strtoupper((string) $card->currency), $remaining, (int) $card->initial_value_minor, $applied[$id] ?? 0, $now);
            $this->ctx->mark('gift_cards', $id, self::OPENING, ['remaining_minor' => $remaining, 'applied_minor' => $applied[$id] ?? 0]);
            $sum += $remaining;
        }
        $this->ctx->flushMarks();

        return $sum;
    }

    /** One balanced posting: platform clearing −$amount (may go negative), the card's account +$amount. */
    private function post(int $cardId, string $currency, int $amount, int $initial, int $applied, string $now): void
    {
        $card = $this->account('gift_card', $cardId, 'gift_card', $currency, $now);
        $clearing = $this->account('platform', 0, 'platform', $currency, $now);
        $entries = $this->ctx->target->table('ledger_entries');
        $clearingBalance = (int) (clone $entries)->where('ledger_account_id', $clearing)->sum('amount_minor');
        $cardBalance = (int) (clone $entries)->where('ledger_account_id', $card)->sum('amount_minor');
        $tx = (string) Str::uuid();
        $meta = (string) json_encode(['legacy_import' => $this->ctx->runId, 'initial_minor' => $initial, 'applied_minor' => $applied], JSON_UNESCAPED_SLASHES);
        $this->ctx->target->table('ledger_entries')->insert([
            [
                'ledger_account_id' => $clearing, 'amount_minor' => -$amount, 'balance_after_minor' => $clearingBalance - $amount,
                'currency_code' => $currency, 'transaction_id' => $tx, 'type' => 'debit', 'reason' => 'gift_card.legacy_opening',
                'ref' => self::REF_PREFIX.$cardId.':clr', 'meta' => $meta, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'ledger_account_id' => $card, 'amount_minor' => $amount, 'balance_after_minor' => $cardBalance + $amount,
                'currency_code' => $currency, 'transaction_id' => $tx, 'type' => 'credit', 'reason' => 'gift_card.legacy_opening',
                'ref' => self::REF_PREFIX.$cardId.':card', 'meta' => $meta, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    private function account(string $ownerType, int $ownerId, string $kind, string $currency, string $now): int
    {
        $accounts = $this->ctx->target->table('ledger_accounts');
        $key = ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'kind' => $kind, 'currency' => $currency];
        $id = (clone $accounts)->where($key)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) $accounts->insertGetId($key + ['status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    }
}
