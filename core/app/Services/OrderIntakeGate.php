<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;

/**
 * P6-348: the ONE customer-order intake gate — is the store (and the branch) taking
 * this order now, and is the basket over the minimum? The web checkout
 * (StoreController::checkout) and the customer app (CustomerAppController::placeOrder)
 * both ask it, so a closed shop, a paused branch or a too-small basket is refused the
 * same way on every customer channel. Staff/POS/partner channels call
 * OrderApiService::create without it on purpose: they must stay ungated.
 */
final class OrderIntakeGate
{
    /**
     * Null when the order may be taken; otherwise the message to show the customer.
     *
     * A scheduled (pre-)order is for a LATER time, so the store or branch being closed
     * right now does not block it (the booking itself is checked against the opening
     * hours by SchedulingService::validate); a branch switched off or paused still does.
     */
    public static function closedMessage(Store $store, ?Branch $branch, bool $scheduled): ?string
    {
        if ($branch !== null && (int) $branch->store_id === (int) $store->id) {
            $blocked = $scheduled
                ? ! ($branch->active && $branch->accepting_orders)
                : ! $branch->canAcceptOrders();
            if ($blocked) {
                return $branch->closed_message ?: ___('This branch is currently closed and not accepting orders.');
            }
        }

        // The owner's manual open/close switch OR the opening hours (P6-346: the hours part is skipped when
        // the owner turned "Prevent orders when closed" off; store_accepts_orders_now reads that setting). A single-branch
        // store posts no branch, so the branch check above does not cover it.
        if (! $scheduled && ! store_accepts_orders_now($store)) {
            // Nudge toward scheduling only when the store actually offers it.
            $msg = ! empty(store_options($store->id, 'allow_order_scheduling'))
                ? ___('This store is closed right now — schedule a time instead.')
                : ___('This store is currently closed and not accepting orders.');
            $hint = storefront_hours_today($store);
            if ($hint) {
                $msg .= ' · '.$hint;
            }

            return $msg;
        }

        return null;
    }

    /** The minimum basket (major units) for this store/branch, or null when there is none. */
    public static function minimum(Store $store, ?Branch $branch): ?float
    {
        return Branch::effectiveMinOrder((int) $store->id, $branch);
    }

    /** Null when $subtotal (pre-tax, pre-discount, major units) meets the minimum; else the message. */
    public static function belowMinimumMessage(Store $store, ?Branch $branch, float $subtotal): ?string
    {
        $min = self::minimum($store, $branch);
        if ($min === null || $subtotal >= $min) {
            return null;
        }

        return ___('Minimum order value is :min.', ['min' => store_money_format($min, (int) $store->id)]);
    }
}
