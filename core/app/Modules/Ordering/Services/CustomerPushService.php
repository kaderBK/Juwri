<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Jobs\SendCustomerPushJob;
use Juwri\Modules\Ordering\Models\CustomerNotification;
use Juwri\Modules\Ordering\Models\CustomerPushSubscription;

/**
 * P1: order-status → customer Web Push (the closed-tab "order ready"). Called
 * from the single shared choke point (Order::booted updated hook), same
 * best-effort shape as its neighbours, so a notification can never fail a
 * status transition.
 *
 * Guest, order-scoped: a subscription is keyed by the order (its track token),
 * with no customer account. Only meaningful transitions notify.
 */
class CustomerPushService
{
    /** Status transitions a diner wants to hear about. */
    private const NOTIFY_STATUSES = ['ready', 'completed', 'delivered', 'cancelled'];

    /** Inbox is chattier than push (owner-locked 2026-08-13): "preparing" lands
     *  in the account inbox to match the guest bell, but never web-pushes. */
    private const INBOX_STATUSES = ['preparing', 'ready', 'completed', 'delivered', 'cancelled'];

    /** Delivery-leg transitions worth a push (kitchen legs already covered above). */
    private const NOTIFY_DELIVERY = ['out_for_delivery'];

    /** Terminal states — after the push, the order's subscriptions are cleaned up. */
    public const TERMINAL = ['completed', 'cancelled', 'delivered'];

    public static function orderStatusChanged(Order $order): void
    {
        if (! $order->id || ! $order->store_id) {
            return;
        }

        $statusChanged = $order->wasChanged('status');
        $status = (string) $order->status;
        $delivery = (string) ($order->delivery_status ?? '');

        $statusFire = $statusChanged && in_array($status, self::NOTIFY_STATUSES, true);
        $inboxFire = $statusChanged && in_array($status, self::INBOX_STATUSES, true);
        $deliveryFire = $order->wasChanged('delivery_status') && in_array($delivery, self::NOTIFY_DELIVERY, true);
        if (! $statusFire && ! $inboxFire && ! $deliveryFire) {
            return;
        }

        // Diner notification switch (account setting): a signed-in customer who
        // turned the header-bell off gets neither inbox rows nor push for their
        // own orders. Guests (no customer_id) are unaffected — their opt-in is
        // the order-scoped track push subscription. A null value (column absent
        // pre-migrate) never blocks — order updates are the safe default.
        if ($order->customer_id) {
            $notifPref = Customer::where('id', (int) $order->customer_id)->value('notifications_enabled');
            if ($notifPref !== null && ! (int) $notifPref) {
                return;
            }
        }

        // Logged-in diner → persistent inbox row (history), independent of any
        // push opt-in. Guests never write here — they use push / recent-orders.
        if ($order->customer_id && ($inboxFire || $deliveryFire)) {
            self::recordInbox($order, $inboxFire ? $status : $delivery);
        }

        // Push keeps the tighter list — "preparing" is inbox-only.
        if (! $statusFire && ! $deliveryFire) {
            return;
        }

        $eventKey = $statusFire ? $status : $delivery;

        // Closed-tab Web Push needs a track token to key the guest subscription
        // and at least one live subscription to send to.
        if (! $order->track_token) {
            return;
        }
        if (! CustomerPushSubscription::where('order_id', $order->id)->whereNull('failed_at')->exists()) {
            return;
        }

        $terminal = in_array($status, self::TERMINAL, true);
        SendCustomerPushJob::dispatch((int) $order->id, (int) $order->store_id, $eventKey, $terminal);
    }

    /** Persist an inbox notification for a logged-in diner. Best-effort — a
     *  notification must never fail a status transition (same contract as the
     *  push path it sits beside). */
    private static function recordInbox(Order $order, string $eventKey): void
    {
        try {
            $store = (string) (Store::where('id', $order->store_id)->value('title') ?? '');
            [$title, $body] = self::inOrderLocale($order, fn () => [
                $store !== '' ? $store : ___('Order update'),
                self::statusLine($eventKey),
            ]);
            // A7: a re-transition (ready → revert → ready) would write an
            // identical row back-to-back. Skip when the NEWEST row for this
            // (customer, order) already says the same thing — distinct
            // consecutive bodies remain legitimate history. Compare AFTER the
            // locale wrap so both renders are under the same locale.
            $newest = CustomerNotification::where('customer_id', (int) $order->customer_id)
                ->where('order_id', (int) $order->id)
                ->latest('id')
                ->first(['type', 'body']);
            if ($newest && $newest->type === 'order_status' && $newest->body === $body) {
                return;
            }

            CustomerNotification::create([
                'customer_id' => (int) $order->customer_id,
                'store_id' => (int) $order->store_id,
                'order_id' => (int) $order->id,
                'type' => 'order_status',
                'title' => $title,
                'body' => $body,
                'url' => $order->track_token ? route('store.orderTrack', $order->track_token, false) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[customer-inbox] record failed: '.$e->getMessage());
        }
    }

    /**
     * Run $fn under the ORDER's stored locale (orders.locale), restoring the
     * ambient locale after. The Order::updated hook fires from POS/dashboard/
     * staff sessions, so diner-facing strings must never inherit the staff
     * member's language. No stored/unsupported locale → current behavior.
     */
    public static function inOrderLocale(Order $order, \Closure $fn): mixed
    {
        $target = (string) ($order->locale ?? '');
        $supported = config('laravellocalization.supportedLocales', []);
        if ($target === '' || ! array_key_exists($target, $supported)) {
            return $fn();
        }
        $prev = app()->getLocale();
        app()->setLocale($target);
        try {
            return $fn();
        } finally {
            app()->setLocale($prev);
        }
    }

    /**
     * P6-213: like inOrderLocale(), but fall back to the STORE's default
     * language when the order has no stored locale (legacy orders, B2B imports,
     * guest kiosks). Pushed/SMS notifications always speak the order's own
     * locale first; the store default only kicks in when nothing better is
     * available, so an Arabic store still gets Arabic even on a fresh order.
     */
    public static function inOrderOrStoreLocale(Order $order, \Closure $fn): mixed
    {
        $target = (string) ($order->locale ?? '');
        if ($target === '') {
            $target = (string) (store_options($order->store_id)->default_language ?? '');
        }
        $supported = config('laravellocalization.supportedLocales', []);
        if ($target === '' || ! array_key_exists($target, $supported)) {
            return $fn();
        }
        $prev = app()->getLocale();
        app()->setLocale($target);
        try {
            return $fn();
        } finally {
            app()->setLocale($prev);
        }
    }

    /** PII-free, localized status line — mirrors SendCustomerPushJob::message(). */
    public static function statusLine(string $status): string
    {
        return match ($status) {
            'preparing' => ___('Your order is being prepared.'),
            'ready' => ___('Your order is ready.'),
            'out_for_delivery' => ___('Your order is on the way.'),
            'delivered' => ___('Your order has been delivered.'),
            'completed' => ___('Your order is complete.'),
            'cancelled' => ___('Your order was cancelled.'),
            default => ___('Your order status changed.'),
        };
    }
}
