<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Juwri\Modules\Customers\Services\CustomerMergeService;
use Juwri\Modules\Messaging\Contracts\Sms;

/**
 * GOL A2 (rev/guest-order-linking): claim guest orders onto the customer who
 * just authenticated, using the P1 same-browser proof — the session entries
 * GuestOrderSession stamped at checkout. Called by login/register/OTP success
 * (B1/B2/A3); returns how many orders were claimed so the UI can toast it.
 *
 * Security model (HANDOFF §1, locked):
 *  - Only orders stamped into THIS session are considered — never a raw
 *    email/phone match.
 *  - An order attached to a Customer WITH a password belongs to a real
 *    account and is never touched.
 *  - An order attached to a passwordless shadow row is claimed by folding the
 *    whole shadow into the authenticated customer (CustomerMergeService),
 *    so the shadow's other orders/addresses/loyalty follow consistently.
 *  - Entries older than GuestOrderSession::TTL_HOURS expire unclaimed.
 *  - Everything is scoped to the customer's restaurant; entries for OTHER
 *    restaurants stay in the session (a later login there may claim them).
 */
class OrderClaimService
{
    /** Claim session-stamped guest orders for $customer. Returns claimed count. */
    public static function claimSessionOrders(Customer $customer): int
    {
        $claimed = 0;
        $drop = [];   // order ids to remove from the session stamp list

        foreach (GuestOrderSession::entries() as $entry) {
            $orderId = (int) ($entry['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            // Expired stamps leave the session without being claimed.
            $placedAt = null;
            try {
                $placedAt = Carbon::parse((string) ($entry['placed_at'] ?? ''));
            } catch (\Throwable $e) {
            }
            if (! $placedAt || $placedAt->lt(now()->subHours(GuestOrderSession::TTL_HOURS))) {
                $drop[] = $orderId;

                continue;
            }

            // Another restaurant's stamp: keep it for a login over there.
            if ((int) ($entry['store_id'] ?? 0) !== (int) $customer->store_id) {
                continue;
            }

            $order = Order::find($orderId);
            if (! $order || (int) $order->store_id !== (int) $customer->store_id) {
                $drop[] = $orderId;

                continue;
            }

            if ($order->customer_id === null) {
                $order->customer_id = $customer->id;
                $order->save();
                $claimed++;
                $drop[] = $orderId;

                continue;
            }

            if ((int) $order->customer_id === (int) $customer->id) {
                $drop[] = $orderId;   // already owned — nothing to do

                continue;
            }

            $owner = Customer::find($order->customer_id);
            if ($owner && empty($owner->password) && (int) $owner->store_id === (int) $customer->store_id) {
                // Passwordless shadow — fold it (and everything it owns) in.
                try {
                    CustomerMergeService::merge($owner, $customer);
                    $claimed++;
                } catch (\InvalidArgumentException $e) {
                    // Guard refused (e.g. raced with another merge) — leave it.
                }
                $drop[] = $orderId;

                continue;
            }

            // Owned by a real (passworded) account, or owner missing: never
            // reassign — drop the stamp so we stop retrying.
            $drop[] = $orderId;
        }

        if ($drop !== []) {
            GuestOrderSession::forget($drop);
        }

        return $claimed;
    }

    /**
     * GOL B2 (P2 proof): after an SMS-OTP login proves the customer owns
     * $rawPhone, fold any passwordless shadow rows in the same restaurant that
     * carry the same phone into $customer, so their guest orders surface.
     * Returns the number of orders moved (for the UI toast).
     *
     * Matching is on an indexed set of phone spellings — the value the diner
     * typed now, its normalized form, and the customer's stored phone — so the
     * capture block's raw phone and the OTP flow's normalized phone both hit.
     * Passworded rows are never candidates (query filter) and CustomerMergeService
     * double-refuses them; the scope is restaurant-fenced.
     */
    public static function claimByVerifiedPhone(Customer $customer, string $rawPhone): int
    {
        $sms = app(Sms::class);
        $variants = array_values(array_unique(array_filter([
            trim($rawPhone),
            $sms->normalizePhone($rawPhone),
            (string) $customer->phone,
            $customer->phone ? $sms->normalizePhone((string) $customer->phone) : null,
        ], fn ($v) => (string) $v !== '')));

        if ($variants === []) {
            return 0;
        }

        $shadows = Customer::where('store_id', $customer->store_id)
            ->whereNull('password')
            ->where('id', '!=', $customer->id)
            ->whereIn('phone', $variants)
            ->get();

        $moved = 0;
        foreach ($shadows as $shadow) {
            try {
                $result = CustomerMergeService::merge($shadow, $customer);
                $moved += (int) ($result['orders'] ?? 0);
            } catch (\InvalidArgumentException $e) {
                // Guard refused (raced/edge) — skip.
            }
        }

        return $moved;
    }
}
