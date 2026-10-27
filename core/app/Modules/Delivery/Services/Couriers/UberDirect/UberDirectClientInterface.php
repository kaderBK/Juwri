<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers\UberDirect;

/**
 * T4-P2 Uber Direct client. Flow: createQuote (fee+ETA, short-lived id) → createDelivery
 * (refs the quote) → status via webhook / getDelivery. Real client uses OAuth2 client
 * credentials (deliveries scope); the Fake advances a robocourier for CI.
 */
interface UberDirectClientInterface
{
    /** ['fee'=>float,'eta_minutes'=>int,'quote_id'=>string,'currency'=>string]. */
    public function createQuote(array $pickup, array $dropoff): array;

    /** ['external_delivery_id'=>string,'status'=>string,'tracking_url'=>string,'fee'=>float]. */
    public function createDelivery(string $quoteId, array $order): array;

    public function cancelDelivery(string $externalDeliveryId): void;

    /** Current delivery state (poll reconciliation fallback). */
    public function getDelivery(string $externalDeliveryId): array;
}
