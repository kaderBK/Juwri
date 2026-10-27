<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers\DoorDash;

use Illuminate\Support\Str;

/** Fake DoorDash Drive client — deterministic quote + simulator. No network. */
class FakeDoorDashClient implements DoorDashClientInterface
{
    public static array $deliveries = [];
    public static float $quoteFee = 5.49;
    public static int $quoteEta = 30;

    public function createQuote(array $order): array
    {
        return [
            'fee' => self::$quoteFee, 'eta_minutes' => self::$quoteEta,
            'quote_id' => 'dd_q_' . Str::random(8), 'currency' => 'USD',
        ];
    }

    public function createDelivery(string $externalDeliveryId, array $order): array
    {
        $rec = [
            'external_delivery_id' => $externalDeliveryId,
            'status' => 'created',
            'tracking_url' => 'https://track.doordash.example/' . $externalDeliveryId,
            'fee' => self::$quoteFee,
        ];
        self::$deliveries[$externalDeliveryId] = $rec;
        return $rec;
    }

    public function cancelDelivery(string $externalDeliveryId): void
    {
        if (isset(self::$deliveries[$externalDeliveryId])) {
            self::$deliveries[$externalDeliveryId]['status'] = 'canceled';
        }
    }

    public function getDelivery(string $externalDeliveryId): array
    {
        return self::$deliveries[$externalDeliveryId] ?? ['status' => 'unknown'];
    }

    public static function reset(): void
    {
        self::$deliveries = [];
        self::$quoteFee = 5.49;
        self::$quoteEta = 30;
    }
}
