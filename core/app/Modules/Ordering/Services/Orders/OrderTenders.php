<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use Juwri\Core\Money\Money;

/**
 * money-last2 (REVIEW, Opus: money): the stored-value tenders of an order (store credit, gift card, platform wallet)
 * as every refund cap must see them. OrderCancellation::reverseTenders takes them off the order (the tender columns go
 * to 0) and records what it did: `tenders_returned_minor` went back to the tender, `tenders_kept_minor` was already
 * covered by a refund to store credit. A cap built from the order total must subtract what was returned, or a later
 * refund to store credit, to the original payment, at the till or through a manual provider pays that part again.
 *
 * Callers read these on the row they locked for the refund (lockForUpdate), the same lock reverseTenders writes under.
 */
final class OrderTenders
{
    /** @var list<string> */
    public const COLUMNS = ['wallet_applied', 'gift_card_applied', 'mk_wallet_applied'];

    /** The tenders still on the order, minor units of the order currency. */
    public static function currentMinor(Order $order): int
    {
        $currency = $order->minorMoneyCurrency();
        $sum = 0;
        foreach (self::COLUMNS as $tender) {
            $sum += max(0, Money::minorFromMajor((float) ($order->getAttribute($tender) ?? 0), $currency));
        }

        return $sum;
    }

    /** What reverseTenders gave back to the tenders it came from, minor units. */
    public static function returnedMinor(Order $order): int
    {
        return max(0, (int) ($order->getAttribute('tenders_returned_minor') ?? 0));
    }

    /** What reverseTenders took off the order without giving it back (a refund to store credit had covered it). */
    public static function keptMinor(Order $order): int
    {
        return max(0, (int) ($order->getAttribute('tenders_kept_minor') ?? 0));
    }

    /** Stored value that still counts as paid to the store: on the order, or kept against a refund to store credit. */
    public static function stillPaid(Order $order): float
    {
        return Money::majorFromMinor(self::currentMinor($order) + self::keptMinor($order), $order->minorMoneyCurrency());
    }

    /** A gross total (every tender) less the tenders already returned: what refunds of any kind may still give back. */
    public static function lessReturned(Order $order, float $total): float
    {
        $currency = $order->minorMoneyCurrency();

        return Money::majorFromMinor(max(0, Money::minorFromMajor($total, $currency) - self::returnedMinor($order)), $currency);
    }

    /**
     * The base of a cap that subtracts EVERY refund row afterwards (the till, returns): a gross total less the
     * stored-value tenders still on the order or returned. Unlike `amount_due` it does not grow when a cancel or a full
     * till refund gives the tenders back. A kept tender stays in it (ML-1120 review NIT 2): it was covered by a refund to
     * store credit, whose row the caller already subtracts, so subtracting the kept part as well would leave the card or
     * cash part that is still owed unrefundable at the till.
     */
    public static function refundBaseLessEveryRefund(Order $order, float $total): float
    {
        $currency = $order->minorMoneyCurrency();
        $tenders = self::currentMinor($order) + self::returnedMinor($order);

        return Money::majorFromMinor(max(0, Money::minorFromMajor($total, $currency) - $tenders), $currency);
    }
}
