<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers\DoorDash;

/**
 * T4-P3 DoorDash Drive client. Auth is a self-signed JWT from the developer access key.
 * Flow: quote → accept, or create directly. Real client is sandbox-buildable but
 * PRODUCTION ACCESS IS CLOSED to new integrators (2025–26) → blocked for go-live.
 */
interface DoorDashClientInterface
{
    /** ['fee'=>float,'eta_minutes'=>int,'quote_id'=>string,'currency'=>string]. */
    public function createQuote(array $order): array;

    /** ['external_delivery_id'=>string,'status'=>string,'tracking_url'=>string,'fee'=>float]. */
    public function createDelivery(string $externalDeliveryId, array $order): array;

    public function cancelDelivery(string $externalDeliveryId): void;

    public function getDelivery(string $externalDeliveryId): array;
}
