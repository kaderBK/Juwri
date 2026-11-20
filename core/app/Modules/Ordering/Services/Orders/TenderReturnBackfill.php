<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Money\Money;

/**
 * ML-1120 review SHOULD 1 (REVIEW, Opus: money): orders whose stored-value tenders were given back BEFORE
 * `orders.tenders_returned_minor` existed (a cancel, void or full till refund) have their tender columns at 0 and the
 * counter at 0, so every refund cap (OrderTenders) would still count the returned part as refundable. This fills the
 * counter from what the reversals left behind, all fixed per order:
 *
 *  - gift card given back to the card: ledger entry `gc:refund:order:{id}:card:{card}:to` (GiftCardService::reverseRedemption);
 *  - gift card that could no longer be spent, paid out instead: activity `giftcard.refund_started` without
 *    `reason = tender_excess` (the grocery tender-excess part lowers the columns itself and is not a return). That row
 *    is the source because it is the only record written in the same transaction that zeroes `gift_card_applied`
 *    (it rolls back with it), and it carries exactly what left the order. The payout's ledger leg
 *    `gc:refund:order:{id}:card:{card}:out:from` is written later, after commit, only when the payout ran, and may be
 *    capped lower (what the card purchase still had unrefunded) or absent (`already_refunded`, `refund_due`). So it is
 *    only a fallback (ML-1120 delta NIT 4): for an order whose claim row is gone (an account or privacy deletion) the
 *    leg is used, never both;
 *  - store credit: ledger entry `cw:tender-reversal:order:{id}:cust` (CustomerWalletService::reverseForOrder);
 *  - platform (Fusion) wallet, when that table exists: the full give-back `sw:{shopper}:refund:order:{id}` (not the
 *    `…:tender-excess:…` parts).
 *
 * Idempotent: only orders whose two counters are both still 0 are written, by a conditional update. Portable: plain
 * query-builder reads, the refs parsed in PHP, no database-specific SQL (MySQL, PostgreSQL, SQLite). A part in a
 * currency other than the order's is skipped (logged): never converted.
 *
 * ML-1120 delta NIT 2: it runs inside a migration, so it reads `orders` through the query builder only (never the Order
 * model, its scopes or its currency helpers): the order currency is `orders.currency_code`, else the store's
 * `stores.settings` → core.currency_code, else the platform currency from config — the same order the app used when
 * this was written, frozen here.
 */
final class TenderReturnBackfill
{
    /** @return int how many orders were updated */
    public static function run(): int
    {
        /** @var array<int, list<array{minor: ?int, major: ?float, currency: ?string}>> $parts */
        $parts = [];
        /** @var array<int, list<array{minor: ?int, major: ?float, currency: ?string}>> $deadCardLegs payout legs, used only without a claim row */
        $deadCardLegs = [];
        /** @var array<int, true> $deadCardClaimed */
        $deadCardClaimed = [];

        if (Schema::hasTable('ledger_entries')) {
            $rows = DB::table('ledger_entries')->select(['ref', 'amount_minor', 'currency_code'])
                ->where(fn ($q) => $q->where('ref', 'like', 'gc:refund:order:%')->orWhere('ref', 'like', 'cw:tender-reversal:order:%'))
                ->orderBy('id')->lazy();
            foreach ($rows as $row) {
                $ref = (string) $row->ref;
                $part = ['minor' => abs((int) $row->amount_minor), 'major' => null, 'currency' => $row->currency_code !== null ? (string) $row->currency_code : null];
                if (preg_match('/^gc:refund:order:(\d+):card:\d+:to$/', $ref, $m) || preg_match('/^cw:tender-reversal:order:(\d+):cust$/', $ref, $m)) {
                    $parts[(int) $m[1]][] = $part;
                } elseif (preg_match('/^gc:refund:order:(\d+):card:\d+:out:from$/', $ref, $m)) {
                    $deadCardLegs[(int) $m[1]][] = $part;
                }
            }
        }

        if (Schema::hasTable('activity_logs')) {
            $rows = DB::table('activity_logs')->select(['meta'])->where('action', 'giftcard.refund_started')->orderBy('id')->lazy();
            foreach ($rows as $row) {
                $meta = is_string($row->meta) ? json_decode($row->meta, true) : (array) $row->meta;
                if (! is_array($meta) || ($meta['reason'] ?? null) === 'tender_excess' || ! isset($meta['order_id'], $meta['amount']) || ! is_numeric($meta['amount'])) {
                    continue;
                }
                $parts[(int) $meta['order_id']][] = ['minor' => null, 'major' => abs((float) $meta['amount']), 'currency' => null];
                $deadCardClaimed[(int) $meta['order_id']] = true;
            }
        }

        foreach ($deadCardLegs as $orderId => $legs) {
            if (! isset($deadCardClaimed[$orderId])) {
                $parts[$orderId] = array_merge($parts[$orderId] ?? [], $legs);
            }
        }

        if (Schema::hasTable('shopper_wallet_ledger_entries')) {
            $rows = DB::table('shopper_wallet_ledger_entries')->select(['idempotency_key', 'amount_minor', 'currency'])
                ->where('type', 'refund')->where('idempotency_key', 'like', 'sw:%:refund:order:%')->orderBy('id')->lazy();
            foreach ($rows as $row) {
                if (preg_match('/^sw:\d+:refund:order:(\d+)$/', (string) $row->idempotency_key, $m)) {
                    $parts[(int) $m[1]][] = ['minor' => abs((int) $row->amount_minor), 'major' => null, 'currency' => $row->currency !== null ? (string) $row->currency : null];
                }
            }
        }

        $updated = 0;
        /** @var array<int, string> $storeCurrencies */
        $storeCurrencies = [];
        foreach (array_chunk(array_keys($parts), 500) as $ids) {
            $orders = DB::table('orders')->select(['id', 'store_id', 'currency_code'])->whereIn('id', $ids)
                ->where('tenders_returned_minor', 0)->where('tenders_kept_minor', 0)->orderBy('id')->get();
            foreach ($orders as $order) {
                $orderId = (int) $order->id;
                $currency = self::orderCurrency($order->currency_code, $order->store_id !== null ? (int) $order->store_id : null, $storeCurrencies);
                $returned = 0;
                foreach ($parts[$orderId] as $part) {
                    if ($part['currency'] !== null && strtoupper($part['currency']) !== $currency) {
                        Log::warning("tender return backfill: order {$orderId} has a returned tender in {$part['currency']}, not {$currency}; skipped");

                        continue;
                    }
                    $returned += $part['minor'] ?? Money::minorFromMajor((float) $part['major'], $currency);
                }
                if ($returned > 0) {
                    $updated += DB::table('orders')->where('id', $orderId)->where('tenders_returned_minor', 0)->where('tenders_kept_minor', 0)
                        ->update(['tenders_returned_minor' => $returned]);
                }
            }
        }

        return $updated;
    }

    /**
     * The order's currency, frozen: its own `currency_code`, else its store's `settings` core.currency_code, else the
     * platform currency (config `settings.platform_currency_code`, then `settings.currency_code`, then USD).
     *
     * @param  array<int, string>  $storeCurrencies  per-run memo
     */
    private static function orderCurrency(mixed $code, ?int $storeId, array &$storeCurrencies): string
    {
        $code = is_string($code) ? trim($code) : '';
        if ($code !== '') {
            return strtoupper($code);
        }
        if ($storeId !== null && $storeId > 0) {
            if (! array_key_exists($storeId, $storeCurrencies)) {
                $raw = Schema::hasColumn('stores', 'settings') ? DB::table('stores')->where('id', $storeId)->value('settings') : null;
                $settings = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                $storeCode = is_array($settings) ? ($settings['core']['currency_code'] ?? null) : null;
                $storeCurrencies[$storeId] = is_string($storeCode) ? strtoupper(trim($storeCode)) : '';
            }
            if ($storeCurrencies[$storeId] !== '') {
                return $storeCurrencies[$storeId];
            }
        }

        return strtoupper((string) (config('settings.platform_currency_code') ?: config('settings.currency_code') ?: 'USD'));
    }
}
