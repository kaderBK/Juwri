<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers\UberDirect;

use Illuminate\Support\Str;

/**
 * Fake Uber Direct client: deterministic quotes + an in-memory "robocourier". Records
 * created deliveries so tests can advance/inspect them. Never hits the network.
 */
class FakeUberDirectClient implements UberDirectClientInterface
{
    /** @var array<string, array> deliveries by external id */
    public static array $deliveries = [];
    public static float $quoteFee = 6.99;
    public static int $quoteEta = 25;

    public function createQuote(array $pickup, array $dropoff): array
    {
        return [
            'fee'         => self::$quoteFee,
            'eta_minutes' => self::$quoteEta,
            'quote_id'    => 'q_' . Str::random(10),
            'currency'    => 'USD',
        ];
    }

    public function createDelivery(string $quoteId, array $order): array
    {
        $id = 'del_' . Str::random(10);
        $rec = [
            'external_delivery_id' => $id,
            'status'               => 'created',
            'tracking_url'         => 'https://track.example/' . $id,
            'fee'                  => self::$quoteFee,
        ];
        self::$deliveries[$id] = $rec;
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
        self::$quoteFee = 6.99;
        self::$quoteEta = 25;
    }
}
