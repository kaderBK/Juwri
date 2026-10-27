<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\MobilePush;

use App\Models\Order;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Contracts\Billing\FeatureGate;
use Juwri\Core\Support\OwnerPwa;
use Juwri\Modules\Messaging\Jobs\SendMobilePushJob;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;

/**
 * PARITY-RUN T9 — the native mobile-app push layer.
 *
 * Scope boundary with {@see StaffPushService}: that service owns the
 * **new-order** fan-out for orders created by OrderApiService (web + native)
 * and predates this track. Everything else this class sends — order status,
 * reservations, waitlist, and new orders from the storefront / scheduled
 * release ({@see newOrder}) — goes to the store's OWNER lane only: native app
 * devices + the owner's dashboard PWA ({@see ownerLane}). Staff browsers never
 * get these; each event is deduped, so nothing is delivered twice.
 *
 * The gate. `mobile_app_push` is checked with {@see FeatureAccess::storeAllows}
 * (subject = the STORE OWNER), never with `feature:` middleware, because device
 * registration is reached by the app itself and a middleware there would 403
 * every install. The owner's settings screen — a dashboard-only route — is the
 * one place the middleware is legal.
 */
class MobilePushService
{
    public const FEATURE = 'mobile_app_push';

    public const EVENT_NEW_ORDER = 'new_order';

    public const EVENT_ORDER_STATUS = 'order_status';

    public const EVENT_RESERVATION = 'reservation';

    public const EVENT_WAITLIST = 'waitlist_ready';

    /** Per-event owner toggles, stored as post-options `mobile_push_<event>`. */
    public const EVENTS = [
        self::EVENT_NEW_ORDER,
        self::EVENT_ORDER_STATUS,
        self::EVENT_RESERVATION,
        self::EVENT_WAITLIST,
    ];

    /** Native platforms — the device tokens this service addresses. */
    public const NATIVE_PLATFORMS = ['fcm', 'apns'];

    /**
     * The OWNER lane of a store: native app devices + the owner's installed
     * dashboard PWA (web subscriptions carrying `user_id`). Staff web
     * subscriptions stay out — they only get new-order pushes (StaffPushService).
     *
     * @return Builder<PushSubscription>
     */
    public static function ownerLane(int $storeId)
    {
        return PushSubscription::where('store_id', $storeId)
            ->whereNull('failed_at')
            ->where(function ($q) {
                $q->whereIn('platform', self::NATIVE_PLATFORMS)
                    ->orWhere(fn ($w) => $w->where('platform', 'web')->whereNotNull('user_id'));
            });
    }

    // ── gates ────────────────────────────────────────────────────────────────

    /** Superadmin kill-switch (+ entitlement, if the key ever becomes plan-gated). */
    public static function enabled(?int $storeId): bool
    {
        return app(FeatureGate::class)->storeAllows($storeId, self::FEATURE);
    }

    /** The feature AND this store's per-event toggle (default ON). */
    public static function eventEnabled(?int $storeId, string $event): bool
    {
        if (! $storeId || ! self::enabled($storeId) || ! in_array($event, self::EVENTS, true)) {
            return false;
        }

        return self::toggleOn($storeId, $event);
    }

    /** This store's per-event toggle alone (default ON). */
    public static function toggleOn(int $storeId, string $event): bool
    {
        return (string) store_options($storeId, 'mobile_push_'.$event, '1') !== '0';
    }

    /**
     * May this owner-lane row receive this event?
     *
     *  - native app devices: the feature AND the per-event toggle ({@see eventEnabled});
     *  - the owner's dashboard PWA: the same, except a NEW ORDER needs only the
     *    toggle. Browser new-order alerts predate `mobile_app_push` (they reached
     *    every web subscription of the store), so gating them on the add-on would
     *    take away something owners already have; the toggle still silences them.
     *
     * $bypassToggle (the Settings "send test" button) keeps the feature gate
     * for native devices but ignores the per-event toggles.
     */
    public static function laneAllows(PushSubscription $sub, int $storeId, string $event, bool $bypassToggle = false): bool
    {
        $native = in_array((string) ($sub->platform ?? 'web'), self::NATIVE_PLATFORMS, true);
        if ($bypassToggle) {
            return ! $native || self::enabled($storeId);
        }
        if (! $native && $event === self::EVENT_NEW_ORDER) {
            return self::toggleOn($storeId, $event);
        }

        return self::eventEnabled($storeId, $event);
    }

    // ── devices ──────────────────────────────────────────────────────────────

    /**
     * Register (or refresh) a device token. Throws when the feature is off, so
     * a stale install cannot keep seeding rows into a killed feature.
     *
     * `$platform` records the device OS. The TOKEN is always an FCM registration
     * token — the wrapper uses @capacitor-firebase/messaging precisely so iOS
     * yields one instead of the raw APNs token FCM rejects.
     */
    public static function registerDevice(
        int $storeId,
        string $token,
        string $platform,
        array $meta = [],
        ?int $staffId = null,
        ?int $userId = null
    ): PushSubscription {
        if (! self::enabled($storeId)) {
            throw ValidationException::withMessages([
                'token' => ___('The mobile app is not available for this store.'),
            ]);
        }

        return PushSubscription::storeNative($token, $platform, $storeId, $staffId, $userId, $meta);
    }

    /** Registered app devices for a store, newest first. */
    public static function devices(int $storeId)
    {
        return PushSubscription::where('store_id', $storeId)
            ->where(function ($q) {
                $q->whereIn('platform', self::NATIVE_PLATFORMS)
                    ->orWhere(fn ($w) => $w->where('platform', 'web')->whereNotNull('user_id'));
            })
            ->orderByDesc('id')
            ->get();
    }

    /** Revoke one device. Tenant-scoped; returns whether a row was removed. */
    public static function revokeDevice(int $storeId, int $subscriptionId): bool
    {
        return (bool) PushSubscription::where('store_id', $storeId)
            ->where('id', $subscriptionId)
            ->where(function ($q) {
                $q->whereIn('platform', self::NATIVE_PLATFORMS)
                    ->orWhere(fn ($w) => $w->where('platform', 'web')->whereNotNull('user_id'));
            })
            ->delete();
    }

    // ── delivery ─────────────────────────────────────────────────────────────

    /**
     * Queue an event to this store's native devices. Returns the number of
     * devices the job will attempt — 0 when the feature/toggle is off or nobody
     * has installed the app.
     *
     * `$dedupeKey` collapses replays (a status written twice in one flow) to a
     * single notification.
     */
    public static function notify(int $storeId, string $event, array $payload, ?string $dedupeKey = null): int
    {
        if (! $storeId || ! in_array($event, self::EVENTS, true) || ! self::toggleOn($storeId, $event)) {
            return 0;
        }
        // Without the add-on only the owner PWA's new-order alerts remain (laneAllows):
        // skip the device query for every other event — this runs on each order update.
        if ($event !== self::EVENT_NEW_ORDER && ! self::enabled($storeId)) {
            return 0;
        }

        $count = self::ownerLane($storeId)->get()
            ->filter(fn (PushSubscription $sub) => self::laneAllows($sub, $storeId, $event))
            ->count();

        if ($count === 0) {
            return 0;
        }

        if ($dedupeKey !== null && ! self::claim($dedupeKey)) {
            return 0;
        }

        SendMobilePushJob::dispatch($storeId, $event, $payload);

        return $count;
    }

    /**
     * The job's body: fan out to this store's native devices through the bound
     * sender, pruning dead tokens exactly like the staff fan-out does.
     */
    public static function deliver(int $storeId, string $event, array $payload, bool $bypassToggle = false): int
    {
        if (! in_array($event, self::EVENTS, true)) {
            return 0;
        }

        $service = app(StaffPushService::class);

        $subs = self::ownerLane($storeId)->get()
            ->filter(fn (PushSubscription $sub) => self::laneAllows($sub, $storeId, $event, $bypassToggle));

        $sent = 0;
        foreach ($subs as $sub) {
            if ($service->sendTo($sub, $payload + ['event' => $event])) {
                $sent++;
            } else {
                $service->prune($sub);
            }
        }

        return $sent;
    }

    /**
     * Owner "send me a test push" button. Reaches every owner-lane device of the
     * store whatever the per-event toggles say — a test that stays silent because
     * "order status" is switched off reads as "push is broken".
     */
    public static function sendTest(int $storeId): int
    {
        if (! self::enabled($storeId)) {
            return 0;
        }

        return self::deliver($storeId, self::EVENT_ORDER_STATUS, [
            'title' => ___('Test notification'),
            'body' => ___('If you can read this, push is working on this device.'),
            'url' => '/dashboard',
            'owner_url' => '/dashboard',
            'tag' => 'test',
            'test' => '1',
        ], bypassToggle: true);
    }

    // ── event hooks (additive, guarded by their callers) ─────────────────────

    /**
     * A new order reached the kitchen through a path that alerts staff with
     * {@see SendPushNotificationJob} (storefront checkout, scheduled
     * release). That job only addresses staff browsers, so the owner lane — the
     * app and the owner's dashboard PWA — is told here, with a link to the order.
     * Orders created by OrderApiService with their own staff push reach the owner
     * lane through StaffPushService::sendNewOrder() instead (the storefront turns
     * that push off), so each order is announced once; the dedupe key collapses replays.
     */
    public static function newOrder(Order $order, ?string $body = null): void
    {
        $storeId = (int) ($order->store_id ?? 0);
        if (! $storeId || ! $order->id) {
            return;
        }

        $url = OwnerPwa::orderUrl($storeId, (int) $order->id);

        self::notify($storeId, self::EVENT_NEW_ORDER, [
            'title' => trim(___('New order')).' #'.$order->id,
            'body' => (string) $body,
            'order_id' => (string) $order->id,
            'url' => $url,
            'owner_url' => $url,
            'tag' => 'order-'.$order->id,
        ], 'order:'.$order->id.':new');
    }

    /** Order::booted() `updated` — a status/delivery-status transition. */
    public static function orderStatusChanged(Order $order): void
    {
        $storeId = (int) ($order->store_id ?? 0);
        if (! $storeId) {
            return;
        }

        $status = $order->wasChanged('status')
            ? (string) $order->status
            : (string) $order->delivery_status;
        if ($status === '') {
            return;
        }

        $url = OwnerPwa::orderUrl($storeId, (int) $order->id);

        self::notify($storeId, self::EVENT_ORDER_STATUS, [
            'title' => ___('Order updated'),
            'body' => '#'.$order->id.' · '.$status,
            'order_id' => (string) $order->id,
            'status' => $status,
            'url' => $url,
            'owner_url' => $url,
            'tag' => 'order-'.$order->id,
        ], 'order:'.$order->id.':status:'.$status);
    }

    /** Reservation::booted() `created`. */
    public static function reservationCreated(Reservation $reservation): void
    {
        $storeId = (int) ($reservation->store_id ?? 0);
        if (! $storeId) {
            return;
        }

        self::notify($storeId, self::EVENT_RESERVATION, [
            'title' => ___('New reservation'),
            'body' => trim((string) $reservation->name).' · '.(int) $reservation->party_size,
            'reservation_id' => (string) $reservation->id,
            'url' => self::dashboardPath('reservations.index', ['store_id' => $storeId], '/reservations'),
            'owner_url' => self::dashboardPath('reservations.index', ['store_id' => $storeId], '/reservations'),
            'tag' => 'reservation-'.$reservation->id,
        ], 'reservation:'.$reservation->id.':created');
    }

    /** WaitlistEntry::booted() `updated` — the guest's table came free. */
    public static function waitlistNotified(WaitlistEntry $entry): void
    {
        $storeId = (int) ($entry->store_id ?? 0);
        if (! $storeId) {
            return;
        }

        self::notify($storeId, self::EVENT_WAITLIST, [
            'title' => ___('Table ready'),
            'body' => trim((string) $entry->name),
            'waitlist_id' => (string) $entry->id,
            // There is no bare /waitlist dashboard page: the list lives under the store.
            'url' => self::dashboardPath('waitlist.index', ['storeId' => $storeId], '/dashboard'),
            'owner_url' => self::dashboardPath('waitlist.index', ['storeId' => $storeId], '/dashboard'),
            'tag' => 'waitlist-'.$entry->id,
        ], 'waitlist:'.$entry->id.':notified');
    }

    /** @param array<string, mixed> $params */
    private static function dashboardPath(string $route, array $params, string $fallback): string
    {
        try {
            return Route::has($route) ? route($route, $params, false) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    // ── settings ─────────────────────────────────────────────────────────────

    /** @return array<string, bool> event => enabled */
    public static function toggles(int $storeId): array
    {
        $out = [];
        foreach (self::EVENTS as $event) {
            $out[$event] = (string) store_options($storeId, 'mobile_push_'.$event, '1') !== '0';
        }

        return $out;
    }

    public static function saveToggles(int $storeId, array $enabled): void
    {
        foreach (self::EVENTS as $event) {
            StoreOption::updateStoreOption($storeId, 'mobile_push_'.$event, in_array($event, $enabled, true) ? '1' : '0');
        }
    }

    /**
     * Claim a dedupe key for an hour. Cache::add is the atomic "first caller
     * wins" primitive; a broken cache store must never suppress a notification,
     * so a throw means "not deduped".
     */
    private static function claim(string $key): bool
    {
        try {
            return (bool) Cache::add('mobile_push:sent:'.sha1($key), 1, 3600);
        } catch (\Throwable $e) {
            return true;
        }
    }
}
