<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use App\Models\Order;
use Juwri\Core\Money\Money;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Payments\Data\OrderPayLink;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;

/**
 * P3-06b (kit/api openapi.json OrderRef, PaymentState, OrderCreated, OrderStatusResponse): a placed order as the
 * headless API shows it — public ids only, the public status enum (API-16), the payment state (API-2, API-10, API-19,
 * API-21, API-27, API-28, API-32) and the View Model Order/Quote built by the same presenter the track page uses.
 */
final class StorefrontOrderView
{
    public function __construct(
        private readonly ViewModelBuilder $builder,
        private readonly HostedPayments $hosted,
    ) {}

    /**
     * OrderRef.status from the DB status, delivery status, courier and shipment (README §4, API-16: `call_bill` →
     * `ready`, unknown legacy strings → `placed`).
     */
    public static function publicStatus(Order $order): string
    {
        $status = (string) $order->status;
        $terminal = ['delivered' => 'delivered', 'completed' => 'completed', 'cancelled' => 'cancelled', 'refunded' => 'refunded'];
        if (isset($terminal[$status])) {
            return $terminal[$status];
        }
        if ($status === 'unpaid') {
            return 'pending_payment';
        }
        if ($status === Order::STATUS_PENDING_APPROVAL) {
            return 'pending_approval';
        }
        if ($status === 'on_delivery' || in_array((string) $order->delivery_status, ['picked_up', 'out_for_delivery'], true)
            || CourierDelivery::query()->where('order_id', $order->id)->where('status', 'picked_up')->exists()) {
            return 'on_the_way';
        }
        if ($order->shipped_at !== null && ($order->delivered_at ?? null) === null) {
            return 'shipped';
        }

        if ($order->getAttribute('picking_status') !== null && in_array($status, ['pending', 'new', 'accepted'], true)) {
            return 'accepted'; // P3-09: the store started picking the basket
        }

        return match ($status) {
            'accepted' => 'accepted',
            'preparing' => 'preparing',
            'ready', 'call_bill' => 'ready',
            default => 'placed',
        };
    }

    /** @return array<string, mixed> OrderRef */
    public function ref(StorefrontApiContext $ctx, Order $order, string $number): array
    {
        return [
            'public_id' => (string) $order->public_id,
            'number' => $number,
            'status' => self::publicStatus($order),
            'track_token' => (string) $order->track_token,
            'track_url' => route('store.orderTrack', ['token' => $order->track_token]),
        ];
    }

    /**
     * PaymentState of the order.
     *
     * @return array<string, mixed>
     */
    public function payment(StorefrontApiContext $ctx, Order $order): array
    {
        $money = new MoneyFormat($ctx->store);
        $currency = strtoupper((string) ($order->currency_code ?: $money->currency));
        $mode = $order->payment_mode === 'hosted' ? 'hosted' : 'pay_later';
        $out = ['mode' => $mode];

        if ($mode === 'pay_later') {
            return $out + ['status' => (int) $order->is_paid === 1 ? 'paid' : 'pay_later', 'next_action' => null];
        }

        $transaction = $this->hosted->transactionFor($order);
        $charged = $transaction !== null ? Money::minorFromMajor((float) $transaction->amount, $currency) : null;
        $refunded = $this->refundedMinor($order, $currency);

        switch ((string) $order->late_payment_status) {
            case 'received_late':
                return $out + ['status' => 'received_late', 'next_action' => null, 'amount_now' => $this->money($money, $currency, (int) $order->late_payment_minor)];
            case 'refunded':
                $amount = (int) $order->late_payment_minor;

                return $out + ['status' => 'refunded', 'next_action' => null, 'amount_now' => $this->money($money, $currency, $amount), 'refunded_amount' => $this->money($money, $currency, max($refunded, $amount))];
            case 'voided':
                return $out + ['status' => 'voided', 'next_action' => null, 'amount_now' => $this->money($money, $currency, (int) $order->late_payment_minor)]
                    + ($order->authorisation_expires_at !== null ? ['authorisation_expires_at' => $order->authorisation_expires_at->copy()->setTimezone($this->timezone($ctx))->toIso8601String()] : []);
        }

        // P3-09 (basket §9): a weighed basket's card hold — authorised now, the final amount captured after picking
        $holdMinor = $order->hold_minor !== null ? (int) $order->hold_minor
            : (data_get($transaction?->details, 'capture') === 'manual' && $charged !== null ? $charged : null);
        $holdBlock = $holdMinor !== null ? ['hold_amount' => $this->money($money, $currency, $holdMinor)] : [];
        $expiry = $order->authorisation_expires_at !== null
            ? ['authorisation_expires_at' => $order->authorisation_expires_at->copy()->setTimezone($this->timezone($ctx))->toIso8601String()] : [];
        switch ((string) $order->hold_status) {
            case 'authorised':
                return $out + ['status' => 'authorised', 'next_action' => null, 'amount_now' => $this->money($money, $currency, (int) $holdMinor)] + $holdBlock + $expiry;
            case 'voided':
                return $out + ['status' => 'voided', 'next_action' => null, 'amount_now' => $this->money($money, $currency, (int) $holdMinor)] + $holdBlock + $expiry;
            case 'captured':
                $captured = (int) $order->captured_minor;
                $state = $out + [
                    'status' => $refunded <= 0 ? 'paid' : ($refunded >= $captured ? 'refunded' : 'partially_refunded'),
                    'next_action' => null,
                    'amount_now' => $this->money($money, $currency, $captured),
                ] + $holdBlock;
                if ($refunded > 0) {
                    $state['refunded_amount'] = $this->money($money, $currency, $refunded);
                }

                return $state;
        }

        if ((int) $order->is_paid === 1) {
            $amount = (int) ($order->late_payment_status === 'accepted' ? $order->late_payment_minor : ($charged ?? 0));
            $status = $refunded <= 0 ? 'paid' : ($refunded >= $amount ? 'refunded' : 'partially_refunded');
            // A card hold the gateway authorised (it carries the time the authorisation lapses, API-32) is not captured.
            $hold = $order->authorisation_expires_at !== null && $refunded <= 0;
            $state = $out + ['status' => $hold ? 'authorised' : $status, 'next_action' => null, 'amount_now' => $this->money($money, $currency, $amount)];
            if ($hold) {
                $state['authorisation_expires_at'] = $order->authorisation_expires_at->copy()->setTimezone($this->timezone($ctx))->toIso8601String();
            }
            if ($refunded > 0) {
                $state['refunded_amount'] = $this->money($money, $currency, $refunded);
            }

            return $state;
        }

        if ($this->hosted->isOpen($order) && $transaction !== null) {
            return $out + [
                'status' => 'pending',
                'next_action' => [
                    'type' => 'redirect',
                    'url' => OrderPayLink::url($transaction),
                    'expires_at' => $order->payment_expires_at->copy()->setTimezone($this->timezone($ctx))->toIso8601String(),
                ],
                'amount_now' => $this->money($money, $currency, (int) $charged),
            ] + $holdBlock;
        }

        // The window ended unpaid (cancelled by the sweep, or about to be): the payment failed.
        return $out + ['status' => 'failed', 'next_action' => null];
    }

    /**
     * OrderStatusResponse (GET /orders/{track_token}).
     *
     * @return array<string, mixed>
     */
    public function status(StorefrontApiContext $ctx, Order $order): array
    {
        $built = $this->builder->apiOrder($ctx->viewModel('track', ['order' => $order]), $order);
        if ($built === null) {
            throw StorefrontApiException::notFound();
        }
        $ref = $this->ref($ctx, $order, $built['number']);
        $view = $built['view'];
        $view['status'] = $ref['status'];

        return [
            'order' => $ref,
            'view' => $view,
            'updated_at' => ($order->updated_at ?? $order->created_at)?->copy()->setTimezone($this->timezone($ctx))->toIso8601String(),
            'payment' => $this->payment($ctx, $order),
        ];
    }

    /**
     * OrderCreated (POST /orders 201).
     *
     * @return array<string, mixed>
     */
    public function created(StorefrontApiContext $ctx, Order $order, bool $replayed): array
    {
        $built = $this->builder->apiOrder($ctx->viewModel('track', ['order' => $order]), $order);
        if ($built === null) {
            throw StorefrontApiException::notFound();
        }

        return [
            'order' => $this->ref($ctx, $order, $built['number']),
            'quote' => $built['quote'],
            'payment' => $this->payment($ctx, $order),
            'replayed' => $replayed,
        ];
    }

    private function refundedMinor(Order $order, string $currency): int
    {
        $sum = 0;
        foreach (OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->get() as $refund) {
            $sum += Money::minorFromMajor((float) $refund->amount, $currency);
        }

        return $sum;
    }

    /** @return array{minor: int, currency: string, formatted: string} */
    private function money(MoneyFormat $format, string $currency, int $minor): array
    {
        return $currency === $format->currency ? $format->money($minor) : ['minor' => $minor, 'currency' => $currency, 'formatted' => $currency.' '.number_format(Money::majorFromMinor($minor, $currency), currency_decimals($currency), '.', ',')];
    }

    private function timezone(StorefrontApiContext $ctx): string
    {
        return (string) ($ctx->branch?->timezone ?: store_tz((int) $ctx->store->id));
    }
}
