<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Order;
use App\Support\OutboundText;
use Illuminate\Support\Facades\Cache;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Ordering\Services\CustomerPushService;

/**
 * P6-292: single choke point for the customer-facing "your order is now …"
 * SMS / WhatsApp notification. Three callers used to duplicate this logic
 * (the owner dashboard order edit at ManagesOrders::updateOrderStatus,
 * the staff PWA at StaffAppController::updateStatus, the kitchen display
 * at KdsController::bump / KdsService::syncOrderStatus) — and the kitchen
 * call was missing entirely, so a kitchen-only flow never told the customer
 * the order was ready even when `customer_status_sms` was on.
 *
 * One service, one idempotency key (order + status, TTL guard): two KDS
 * screens bumping the same order to the same status in the same window
 * send exactly one SMS.
 */
class OrderStatusNotifier
{
    /** Order statuses that trigger a customer notification. */
    public const NOTIFIABLE = ['preparing', 'ready', 'completed', 'delivered', 'cancelled'];

    /** Idempotency window — two callers with the same (order, status) inside this window = one send. */
    private const DEDUPE_TTL_SECONDS = 600;

    /**
     * Notify the customer of $order reaching $status over the owner-opted-in
     * channels (SMS + WhatsApp). Idempotent per (order, status) within the
     * short dedupe window, so two KDS screens bumping the same order to the
     * same status do not double-send.
     *
     * The locale is taken from the order, falling back to the store's default
     * language, exactly as the legacy ManagesOrders / StaffAppController
     * paths did. P6-213: the status label and the SMS template are BOTH
     * translated INSIDE the locale wrapper.
     */
    public static function notify(Order $order, string $status): void
    {
        if (! in_array($status, self::NOTIFIABLE, true)) {
            return;
        }

        $storeId = (int) $order->store_id;
        if ($storeId <= 0) {
            return;
        }

        // Per (order, status) idempotency: two KDS screens bumping the same
        // order to the same status in the same window must not double-send.
        $key = self::dedupeKey((int) $order->id, $status);
        if (Cache::has($key)) {
            return;
        }

        $customerPhone = trim((string) ($order->phone_number ?? ''));
        $hasSms = (bool) store_options($storeId, 'customer_status_sms');
        $hasWhatsApp = (bool) store_options($storeId, 'customer_status_whatsapp');
        if ($customerPhone === '' || (! $hasSms && ! $hasWhatsApp)) {
            // Still stamp the dedupe key — a later config flip (customer_status_sms=1)
            // should not retro-fire a stale status; the customer notification is
            // event-bound to the moment the status was reached.
            Cache::put($key, 1, self::DEDUPE_TTL_SECONDS);

            return;
        }

        // Stamp BEFORE dispatching so a slow transport cannot cause a retrying
        // caller to fire a second message. The dispatch is best-effort — if the
        // queue contract throws we still consider the status notified.
        Cache::put($key, 1, self::DEDUPE_TTL_SECONDS);

        /** @var MessageQueue $mq */
        $mq = app(MessageQueue::class);
        $restaurantName = OutboundText::name((string) optional($order->store)->title);

        if ($hasSms) {
            $smsText = CustomerPushService::inOrderOrStoreLocale($order, function () use ($restaurantName, $order, $status) {
                // $status is one of NOTIFIABLE, all OrderStatus backing values.
                $statusLabel = ___($status);

                return ___('name_your_order_id_is_now_status', [
                    'name' => $restaurantName,
                    'id' => (string) $order->id,
                    'status' => $statusLabel,
                ]);
            });
            $mq->sms($customerPhone, $smsText, $storeId, 'status', false);
        }

        if ($hasWhatsApp) {
            [$waText, $waName, $waStatus] = CustomerPushService::inOrderOrStoreLocale($order, function () use ($restaurantName, $order, $status) {
                // $status is one of NOTIFIABLE, all OrderStatus backing values.
                $statusLabel = ___($status);

                return [
                    ___('name_your_order_id_is_now_status', [
                        'name' => $restaurantName,
                        'id' => (string) $order->id,
                        'status' => $statusLabel,
                    ]),
                    $restaurantName,
                    $statusLabel,
                ];
            });
            $template = store_options($storeId, 'whatsapp_status_template') ?: null;
            $mq->whatsApp(
                $customerPhone, $waText, $storeId, 'status', false,
                is_string($template) ? $template : null,
                [$waName, (string) $order->id, $waStatus]
            );
        }
    }

    /** Build the (order, status) dedupe key. Public so tests can poke it. */
    public static function dedupeKey(int $orderId, string $status): string
    {
        return 'order.status.notified.'.$orderId.'.'.$status;
    }
}
