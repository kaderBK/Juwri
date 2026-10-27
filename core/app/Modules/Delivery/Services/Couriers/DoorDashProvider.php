<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers;

use App\Models\Order;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Juwri\Modules\Delivery\Services\Couriers\DoorDash\DoorDashClientFactory;

/**
 * T4-P3 DoorDash Drive courier. Sandbox-buildable; PRODUCTION ACCESS CLOSED to new
 * integrators (2025–26) → the real client stays sandbox-only until DoorDash reopens
 * certification. Uses an app-generated external_delivery_id (DoorDash keys deliveries
 * by the caller's id). Status flows in through the shared CourierWebhookController.
 */
class DoorDashProvider implements CourierProvider
{
    public function key(): string
    {
        return 'doordash';
    }

    public function label(): string
    {
        return 'DoorDash Drive';
    }

    public function availableFor(int $storeId): bool
    {
        $creds = Credential::for('doordash', $storeId);

        return ! empty($creds['developer_id']) || config('services.doordash.driver') === 'fake';
    }

    public function quote(Order $order): ?CourierQuote
    {
        $q = DoorDashClientFactory::make((int) $order->store_id)->createQuote(['order_id' => $order->id]);
        if (empty($q['quote_id'])) {
            return null;
        }

        return new CourierQuote('doordash', (float) $q['fee'], (int) ($q['eta_minutes'] ?? 0), $q['quote_id'], true, $q['currency'] ?? 'USD');
    }

    public function dispatch(Order $order, ?CourierQuote $quote = null): ?CourierDelivery
    {
        $client = DoorDashClientFactory::make((int) $order->store_id);
        $quote = $quote && $quote->quoteId ? $quote : $this->quote($order);
        if (! $quote) {
            return null;
        }
        // DoorDash keys deliveries by a caller-supplied external id.
        $externalId = 'mb_'.$order->id.'_'.Str::lower(Str::random(6));
        $res = $client->createDelivery($externalId, ['order_id' => $order->id]);

        return CourierDelivery::updateOrCreate(
            ['provider' => 'doordash', 'external_delivery_id' => $res['external_delivery_id']],
            [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'quote_id' => $quote->quoteId,
                'status' => $res['status'] ?? 'created',
                'fee' => $res['fee'] ?? $quote->fee,
                'eta_minutes' => $quote->etaMinutes,
                'tracking_url' => $res['tracking_url'] ?? null,
            ]
        );
    }

    public function cancel(Order $order): bool
    {
        $delivery = CourierDelivery::where('order_id', $order->id)->where('provider', 'doordash')->latest('id')->first();
        if (! $delivery || ! $delivery->external_delivery_id) {
            return false;
        }
        DoorDashClientFactory::make((int) $order->store_id)->cancelDelivery($delivery->external_delivery_id);
        $delivery->update(['status' => 'canceled']);

        return true;
    }
}
