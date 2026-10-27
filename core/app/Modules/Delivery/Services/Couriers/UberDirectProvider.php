<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers;

use App\Models\Order;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Juwri\Modules\Delivery\Services\Couriers\UberDirect\UberDirectClientFactory;

/**
 * T4-P2 Uber Direct third-party courier. Quotes at dispatch time (quote id expires fast,
 * so we re-quote on dispatch), creates a delivery, and tracks it via a CourierDelivery
 * row updated by the status webhook (with a poll fallback since Uber only retries 3×).
 * Available when the restaurant has BYOK Uber Direct credentials.
 */
class UberDirectProvider implements CourierProvider
{
    public function key(): string
    {
        return 'uber_direct';
    }

    public function label(): string
    {
        return 'Uber Direct';
    }

    public function availableFor(int $storeId): bool
    {
        $creds = Credential::for('uber_direct', $storeId);

        return ! empty($creds['client_id']) || config('services.uber_direct.driver') === 'fake';
    }

    public function quote(Order $order): ?CourierQuote
    {
        $client = UberDirectClientFactory::make((int) $order->store_id);
        $q = $client->createQuote($this->pickup($order), $this->dropoff($order));
        if (empty($q['quote_id'])) {
            return null;
        }

        return new CourierQuote('uber_direct', (float) $q['fee'], (int) ($q['eta_minutes'] ?? 0), $q['quote_id'], true, $q['currency'] ?? 'USD');
    }

    public function dispatch(Order $order, ?CourierQuote $quote = null): ?CourierDelivery
    {
        $client = UberDirectClientFactory::make((int) $order->store_id);
        // Re-quote if none passed or a stale one (quote ids expire quickly).
        $quote = $quote && $quote->quoteId ? $quote : $this->quote($order);
        if (! $quote) {
            return null;
        }

        $res = $client->createDelivery($quote->quoteId, ['order_id' => $order->id]);

        return CourierDelivery::updateOrCreate(
            ['provider' => 'uber_direct', 'external_delivery_id' => $res['external_delivery_id']],
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
        $delivery = CourierDelivery::where('order_id', $order->id)->where('provider', 'uber_direct')->latest('id')->first();
        if (! $delivery || ! $delivery->external_delivery_id) {
            return false;
        }
        UberDirectClientFactory::make((int) $order->store_id)->cancelDelivery($delivery->external_delivery_id);
        $delivery->update(['status' => 'canceled']);

        return true;
    }

    private function pickup(Order $order): array
    {
        return ['store_id' => $order->store_id];
    }

    private function dropoff(Order $order): array
    {
        return ['address' => $order->address, 'phone' => $order->phone_number];
    }
}
