<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Order;

/**
 * GOL A1 (rev/guest-order-linking): remember, in the browser SESSION, which
 * orders were placed while NOT logged in — so a later login/register/OTP in the
 * same session can claim them (P1 "same-browser" proof, HANDOFF §1).
 *
 * The session survives auth: login/register/OTP call session()->regenerate(),
 * which keeps data (only logout's invalidate() flushes) — so a stamp written at
 * guest checkout is still there right after the user authenticates.
 *
 * This class only STAMPS and READS; the claiming itself (ownership checks,
 * shadow-row rules, the 72h window) lives in OrderClaimService (A2), which is
 * the one consumer of entries().
 */
class GuestOrderSession
{
    /** Session key holding the stamped list. */
    public const KEY = 'guest_orders';

    /** Cap the list so an abusive session can't grow it unbounded (drop oldest). */
    public const MAX = 20;

    /** Claim window in hours — entries older than this are ignored by the claimer. */
    public const TTL_HOURS = 72;

    /**
     * Record a just-placed guest order in the current session.
     * Caller gates on "not logged in" — a logged-in order already carries the
     * right customer_id and must never be stamped.
     */
    public static function stamp(Order $order): void
    {
        $list = session()->get(self::KEY, []);
        if (! is_array($list)) {
            $list = [];
        }

        $list[] = [
            'order_id' => (int) $order->id,
            'store_id' => (int) $order->store_id,
            'placed_at' => now()->toIso8601String(),
        ];

        session()->put(self::KEY, array_slice($list, -self::MAX));
    }

    /**
     * The stamped entries, oldest first. Shape per entry:
     * ['order_id' => int, 'store_id' => int, 'placed_at' => ISO-8601 string].
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        $list = session()->get(self::KEY, []);

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Drop specific order ids from the stamp list (called after a claim).
     *
     * @param  array<int, int|string>  $orderIds
     */
    public static function forget(array $orderIds): void
    {
        $ids = array_map('intval', $orderIds);
        $kept = array_values(array_filter(
            self::entries(),
            fn ($e) => ! in_array((int) ($e['order_id'] ?? 0), $ids, true)
        ));

        if ($kept === []) {
            session()->forget(self::KEY);
        } else {
            session()->put(self::KEY, $kept);
        }
    }
}
