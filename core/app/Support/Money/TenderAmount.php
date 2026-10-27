<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support\Money;

use App\Models\Order;
use Juwri\Modules\Payments\Services\LedgerService;
use Brick\Math\RoundingMode;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;

/**
 * Bug batch 3 · C — how much a stored-value tender (store credit, gift card) may apply to an order, in integer
 * minor units of the order currency. Replaces `floor($x * 100) / 100` on floats, which lost a cent on binary
 * fractions (0.29 * 100 = 28.999… → 0.28) and was wrong for 3- and 0-decimal currencies.
 *
 * The tender is the largest amount that is ≤ every limit (the balance, the amount requested) and ≤ what is still
 * due, in whole steps of the ledger's posting precision ({@see LedgerService::postingDecimals()}) so the amount the
 * ledger posts is exactly the amount the order records. Bug batch 4 · 1: the ledger posts at the currency's own
 * exponent, so the step is one minor unit (KWD fils: a 3.375 due takes 3.375 of credit, not 3.37).
 */
final class TenderAmount
{
    /** What is still due on the order (total − every tender already applied), in the order currency's minor units. */
    public static function dueMinor(Order $order, string $currency): int
    {
        $due = Money::decimal((float) $order->total)
            ->minus(Money::decimal((float) ($order->wallet_applied ?? 0)))
            ->minus(Money::decimal((float) ($order->gift_card_applied ?? 0)))
            ->minus(Money::decimal((float) ($order->mk_wallet_applied ?? 0)));

        // HALF_UP like Order::getAmountDueAttribute — the due a gateway would be charged.
        return max(0, Money::minorFromMajor((string) $due, $currency));
    }

    /**
     * @param  int  $dueMinor  {@see dueMinor()}
     * @param  float|int|string  ...$limits  major amounts the tender may not exceed (balance, requested) — read DOWN
     */
    public static function apply(string $currency, int $dueMinor, float|int|string ...$limits): float
    {
        $minor = $dueMinor;
        foreach ($limits as $limit) {
            $minor = min($minor, Money::minorFromMajor($limit, $currency, RoundingMode::Down));
        }
        $minor = max(0, $minor);

        $step = 10 ** max(0, Currencies::exponent($currency) - LedgerService::postingDecimals($currency));
        $minor -= $minor % $step;

        return Money::majorFromMinor($minor, $currency);
    }

    /**
     * A revenue share rounded DOWN (the platform absorbs the dust, the payee is never overpaid): the exact product
     * $base × $share, in whole steps of the ledger's posting precision of $currency (one minor unit). Replaces
     * `floor($base * $share * 100) / 100`, which lost a cent on binary fractions (29 × 0.01 → 0.28) and kept
     * fractional yen.
     */
    public static function floorShare(float|int|string $base, float|int|string $share, string $currency): float
    {
        $product = Money::decimal($base)->multipliedBy(Money::decimal($share));

        return self::apply($currency, max(0, Money::minorFromMajor((string) $product, $currency, RoundingMode::Down)));
    }
}
