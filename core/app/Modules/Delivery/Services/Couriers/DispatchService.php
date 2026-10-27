<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers;

use App\Models\Order;
use Juwri\Modules\Delivery\Models\CourierDelivery;

/**
 * T4: single entry point for delivery dispatch. Lists the couriers available to a
 * restaurant (own fleet + connected 3rd-party), quotes them for cost/time comparison,
 * and dispatches an order to the chosen one. Own-fleet is always the default; a
 * restaurant with no 3rd-party connection sees exactly today's behavior.
 */
class DispatchService
{
    /** @return CourierProvider[] providers usable for this restaurant. */
    public static function availableProviders(int $storeId): array
    {
        return array_values(array_filter(
            CourierRegistry::all(),
            fn (CourierProvider $p) => $p->availableFor($storeId)
        ));
    }

    /** Quote every available courier for an order (for the dispatch comparison screen). */
    public static function quoteAll(Order $order): array
    {
        $out = [];
        foreach (self::availableProviders((int) $order->store_id) as $provider) {
            $quote = $provider->quote($order);
            if ($quote) {
                $out[] = $quote->toArray();
            }
        }

        return $out;
    }

    /** Dispatch an order to a specific courier (defaults to own fleet). */
    public static function dispatch(Order $order, string $providerKey = 'own'): ?CourierDelivery
    {
        $provider = CourierRegistry::get($providerKey);
        if (! $provider || ! $provider->availableFor((int) $order->store_id)) {
            $provider = CourierRegistry::get('own');
        }
        $quote = $provider->quote($order);

        return $provider->dispatch($order, $quote);
    }

    /**
     * Auto-dispatch by the restaurant's configured mode:
     *  - manual   (default): no automatic dispatch — the owner assigns.
     *  - own      : always the own fleet.
     *  - cheapest : the lowest-fee feasible courier (own or 3rd-party).
     *  - overflow : own fleet now; escalateToThirdParty() covers a no-driver timeout.
     * Returns the CourierDelivery for a 3rd-party dispatch, else null.
     */
    public static function autoDispatch(Order $order): ?CourierDelivery
    {
        $mode = (string) (store_options((int) $order->store_id, 'dispatch_mode') ?: 'manual');

        return match ($mode) {
            'own' => self::dispatch($order, 'own'),
            'cheapest' => self::dispatch($order, self::cheapestProviderKey($order)),
            'overflow' => self::dispatch($order, 'own'),
            default => null, // manual
        };
    }

    /**
     * Escalate an un-accepted own-fleet order to the cheapest available 3rd-party
     * courier (the overflow path when no own driver accepts in time). Null if none.
     */
    public static function escalateToThirdParty(Order $order): ?CourierDelivery
    {
        $key = self::cheapestProviderKey($order, excludeOwn: true);

        return $key ? self::dispatch($order, $key) : null;
    }

    /** Lowest-fee feasible provider key for an order (optionally excluding own fleet). */
    public static function cheapestProviderKey(Order $order, bool $excludeOwn = false): ?string
    {
        $best = null;
        $bestFee = INF;
        foreach (self::availableProviders((int) $order->store_id) as $provider) {
            if ($excludeOwn && $provider->key() === 'own') {
                continue;
            }
            $quote = $provider->quote($order);
            if ($quote && $quote->feasible && $quote->fee < $bestFee) {
                $bestFee = $quote->fee;
                $best = $provider->key();
            }
        }

        return $best;
    }
}
