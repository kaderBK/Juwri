<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Refunds;

use App\Models\Order;

/**
 * A per-gateway refunder that can push a refund back to the diner's original
 * payment instrument (card, wallet, bank) through that provider's API.
 *
 * Implementations live under App\Services\Refunds\Drivers and are registered in
 * {@see GatewayRefundService::drivers()}. A provider without a driver falls back
 * to a tracked manual refund: the platform records the refund and marks the
 * request approved, and the owner completes the money movement in the provider's
 * own dashboard. This interface is the seam that upgrades a provider from
 * manual-tracked to fully automatic without touching the controller or UI.
 */
interface GatewayRefunder
{
    /** The PaymentGateway `payment_folder` this driver refunds (e.g. 'stripe'). */
    public function gateway(): string;

    /**
     * Push a refund to the original payment for $order.
     *
     * @param  float  $amount  Positive amount in the order's currency.
     * @return array{ok:bool,reference:?string,message:?string,refused?:true} ok=true when the
     *                                                                        provider accepted the refund (money is moving); ok=false with a
     *                                                                        message when it could not be issued automatically (the caller then
     *                                                                        records a tracked manual refund) — unless `refused` (bug batch 6:
     *                                                                        refused before any provider call; the caller records nothing).
     */
    public function refund(Order $order, float $amount): array;
}
