<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Settings;

/**
 * P3-14 (D7) — the per-flow refund switches of a store, one typed store setting each, every one OFF by default:
 *
 *  - `weighed_difference` (`refunds_weighed_difference`): a weighed basket may be charged its cap up front and refunded
 *    the difference after picking. Off: only gateways that authorise + capture (and cash) are offered for it.
 *  - `deposits` (`booking_deposit_refunds`): a booking deposit is refunded when the booking is cancelled before the
 *    deadline (D8). Off: deposits are not refunded (the policy says so before payment); reschedule stays.
 *  - `returns` (`refunds_returns`): a return may be refunded. Off: exchange or store credit only.
 *
 * Two refunds never ask these switches (they are always made): a late payment the owner refunds (API-28: the order or
 * booking never went ahead) and a grocery line removed after the card was captured (API-31: the shopper never got it).
 * Callers name those with {@see EXEMPT}; every other refund of a flow must pass {@see allows()}.
 */
final class RefundSwitches
{
    /** Flow → its typed store setting (StoreSettingKeys). */
    public const FLOWS = [
        'weighed_difference' => 'refunds_weighed_difference',
        'deposits' => 'booking_deposit_refunds',
        'returns' => 'refunds_returns',
    ];

    /** Refunds that bypass the switches: late payments (API-28) and lines removed after capture (API-31). */
    public const EXEMPT = ['late_payment', 'removed_line'];

    /** Is this flow's refund switched on for the store? An unknown flow is a programming error. */
    public static function allows(int $storeId, string $flow): bool
    {
        if (! isset(self::FLOWS[$flow])) {
            throw new \InvalidArgumentException('Unknown refund flow: '.$flow);
        }

        return StoreSettings::bool($storeId, self::FLOWS[$flow], false);
    }

    /** May a refund of this kind be made: an exempt kind always, a flow only when its switch is on. */
    public static function permits(int $storeId, string $kind): bool
    {
        return in_array($kind, self::EXEMPT, true) || self::allows($storeId, $kind);
    }

    /**
     * Every switch of the store (the View Model's `context.refunds`; themes only change copy with it).
     *
     * @return array{weighed_difference: bool, deposits: bool, returns: bool}
     */
    public static function forStore(int $storeId): array
    {
        return [
            'weighed_difference' => self::allows($storeId, 'weighed_difference'),
            'deposits' => self::allows($storeId, 'deposits'),
            'returns' => self::allows($storeId, 'returns'),
        ];
    }
}
