<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge;

use App\Models\Order;
use Juwri\Modules\Channels\Models\PosBridgeConnection;

/**
 * GAPS-RUN-2 B1 POS bridge. The contract every POS integration (Square, Clover, …)
 * implements. Opposite direction to the aggregator hub: we PUSH our orders into the
 * restaurant's existing POS. The shared PushPosOrderJob drives it — resolves the
 * tenant connection, maps line items through pos_item_map, and records the push
 * idempotently. Providers never touch the idempotency ledger themselves.
 */
interface PosBridgeProvider
{
    /** Provider key, e.g. 'square' | 'clover'. */
    public function key(): string;

    /** Human label for the owner UI. */
    public function label(): string;

    /**
     * Verify the stored credentials + location/merchant id reach the POS. Returns
     * true when the connection is usable. Must not throw on a normal auth failure.
     */
    public function testConnection(PosBridgeConnection $connection): bool;

    /**
     * Push one order to the POS. Line items reference pos_item_map external ids when
     * mapped, else ad-hoc name/price. Implementations MUST send a stable idempotency
     * key derived from the order so a provider-side retry is safe.
     */
    public function pushOrder(Order $order, PosBridgeConnection $connection): PosPushResult;

    /**
     * List the POS's catalog/inventory items for the mapping screen:
     * [ ['external_id' => '...', 'name' => '...'], ... ].
     */
    public function listItems(PosBridgeConnection $connection): array;
}
