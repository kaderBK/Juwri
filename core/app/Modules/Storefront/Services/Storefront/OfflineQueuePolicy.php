<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\Hooks;

/**
 * P3-02 (read side; P3-03 builds the queue and the switches' screens): may this store's storefront queue an order
 * while offline? THEME-CONTRACT §5.5 / D4: effective = platform AND reseller AND store, any level off means off.
 * Owner answer Q5 (2026-10-05): ON by default at every level, the owner can turn it off per store.
 *
 *  - platform: the superadmin option `offline_queue_enabled` (absent = on);
 *  - reseller: the `storefront.offline-queue` hook (ee answers false when the store's reseller switched it off;
 *    Community has no reseller level = on);
 *  - store: the typed setting `offline_queue_enabled` (absent = on).
 *
 * Only dine-in at a table and pay-on-collect pickup may queue (§5.5); delivery and online-paid orders never do.
 */
final class OfflineQueuePolicy
{
    public const RESELLER_HOOK = 'storefront.offline-queue';

    /** Core fulfilment modes that may queue offline (paid on collection / at the table). */
    public const QUEUEABLE = ['on-table', 'takeaway', 'in_store'];

    public const DEFAULT_EXPIRY_MIN = 30;

    public function enabled(Store $store): bool
    {
        return $this->platformEnabled()
            && $this->resellerEnabled($store)
            && StoreSettings::bool((int) $store->id, 'offline_queue_enabled', true);
    }

    /** Platform level: the superadmin switch (Admin > Settings > Feature controls); absent or blank = on. */
    public function platformEnabled(): bool
    {
        $value = config('settings.offline_queue_enabled');
        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Reseller level (ee hook; an ee answer of `false` = the store's reseller switched it off). Community: no reseller = on. */
    public function resellerEnabled(Store $store): bool
    {
        return Hooks::first(self::RESELLER_HOOK, $store) !== false;
    }

    public function expiryMinutes(Store $store): int
    {
        $minutes = StoreSettings::int((int) $store->id, 'offline_queue_expiry_min', self::DEFAULT_EXPIRY_MIN);

        return $minutes > 0 ? $minutes : self::DEFAULT_EXPIRY_MIN;
    }

    /**
     * P3-03 — REVIEW (Opus: server side of the kill switch): a checkout that the storefront JS marks as sent from its
     * offline queue (header `X-Juwri-Queued: 1` or body `queued_at`) is refused when
     *  - any level switched the queue off (`offline_queue_disabled`, 403) — an order queued before the switch never
     *    lands afterwards, whatever the client still holds;
     *  - the order type may not queue (`offline_queue_type`) or it is paid online (`offline_queue_payment`): only dine-in
     *    at a table and pay-on-collect pickup ever queue (THEME-CONTRACT §5.5);
     *  - it waited longer than the store-set limit (`offline_queue_expired`; 2 min of clock slack, a queue time in the
     *    future counts as now).
     * An ordinary online checkout is never touched. A resend of an order that already exists is answered by the
     * checkout's own idempotent replay BEFORE this runs, so a switch turned off after the order landed does not
     * turn its retry into an error.
     */
    public function refusal(Store $store, Request $request, string $orderingType): ?JsonResponse
    {
        if (! $this->isQueued($request)) {
            return null;
        }
        $deny = fn (string $code, string $message, int $status): JsonResponse => response()->json(['success' => false, 'code' => $code, 'message' => $message], $status);

        if (! $this->enabled($store)) {
            return $deny('offline_queue_disabled', ___('Offline ordering is switched off. Please place the order again.'), 403);
        }
        if (! in_array($orderingType, self::QUEUEABLE, true)) {
            return $deny('offline_queue_type', ___('This order type cannot be placed from the offline queue.'), 422);
        }
        if ((string) $request->input('pay_via') === 'pay_online') {
            return $deny('offline_queue_payment', ___('Online payments cannot be placed from the offline queue.'), 422);
        }
        $queuedAt = $request->input('queued_at');
        if ($queuedAt !== null && $queuedAt !== '') {
            try {
                $at = \Carbon\CarbonImmutable::parse((string) $queuedAt);
            } catch (\Throwable) {
                return $deny('offline_queue_expired', ___('This order waited too long. Please place it again.'), 422);
            }
            if ($at->addMinutes($this->expiryMinutes($store))->addMinutes(2)->isPast()) {
                return $deny('offline_queue_expired', ___('This order waited too long. Please place it again.'), 422);
            }
        }

        return null;
    }

    public function isQueued(Request $request): bool
    {
        return $request->header('X-Juwri-Queued') === '1' || $request->filled('queued_at');
    }

    /**
     * The store's offered order types (core keys) that may queue.
     *
     * @param  list<string>  $offered
     * @return list<string>
     */
    public function orderTypes(array $offered): array
    {
        return array_values(array_intersect($offered, self::QUEUEABLE));
    }
}
