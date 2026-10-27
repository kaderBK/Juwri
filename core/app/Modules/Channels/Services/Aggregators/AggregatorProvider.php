<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * T3 aggregator hub. Contract every marketplace integration (Deliverect, Uber Eats,
 * …) implements. The shared AggregatorIngestor drives it: verify the webhook, resolve
 * the tenant from the stored connection (never the payload), map the order through the
 * ONE creation path, and record idempotently. Providers never build Orders directly.
 */
interface AggregatorProvider
{
    /** Provider key, e.g. 'deliverect' | 'uber_eats'. */
    public function key(): string;

    /**
     * Resolve the tenant connection for an inbound webhook. Real providers look it up
     * by the external store id; the trusted identity NEVER comes from an unverified
     * payload. Returns null when the store is unknown.
     */
    public function resolveConnection(Request $request, array $payload): ?AggregatorConnection;

    /** Verify the inbound webhook signature against the connection's stored secret. */
    public function verifyWebhook(Request $request, AggregatorConnection $connection): bool;

    /** Extract the external store id from the payload (used to resolve the connection). */
    public function externalStoreId(array $payload): ?string;

    /** Extract the external order id from the payload (idempotency key). */
    public function externalOrderId(array $payload): ?string;

    /**
     * Map the payload to an OrderApiService::create() data array — items (item_id/
     * quantity), type='delivery', customer fields, and 'channel_fee' for commission.
     * Unmapped items must be surfaced (in note/message), never silently dropped.
     */
    public function mapOrder(array $payload, AggregatorConnection $connection): array;

    /** Push a local order status change back to the aggregator (best-effort). */
    public function pushStatus(Order $order, AggregatorConnection $connection): void;
}
