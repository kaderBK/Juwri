<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use App\Services\FeatureAccess;
use Illuminate\Contracts\Session\Session;

/**
 * Server-side "this browser is an in-store kiosk" marker.
 *
 * Set ONLY by the kiosk route ({slug}/kiosk, which already 404s unless the kiosk
 * feature + the owner's kiosk_enabled are on) and cleared by the staff exit PIN. The
 * order path reads it instead of trusting a `kiosk_mode` request field, so a forged
 * field on the normal storefront cannot tag an order as a kiosk order. Keyed by store
 * id: a kiosk session for store A never tags an order placed at store B.
 */
final class KioskContext
{
    private const KEY = 'kiosk.stores';

    public static function mark(int $storeId, ?Session $session = null): void
    {
        $session ??= session()->driver();
        $stores = (array) $session->get(self::KEY, []);
        $stores[$storeId] = true;
        $session->put(self::KEY, $stores);
    }

    public static function clear(int $storeId, ?Session $session = null): void
    {
        $session ??= session()->driver();
        $stores = (array) $session->get(self::KEY, []);
        unset($stores[$storeId]);
        $session->put(self::KEY, $stores);
    }

    /** @return list<int> the stores this browser session has been marked a kiosk for */
    public static function stores(?Session $session = null): array
    {
        $session ??= session()->driver();

        return array_map('intval', array_keys(array_filter((array) $session->get(self::KEY, []))));
    }

    /** The kiosk is really on for this store now: this session was marked AND the feature + the owner's switch are still on. */
    public static function on(int $storeId, ?Session $session = null): bool
    {
        return self::active($storeId, $session)
            && FeatureAccess::storeAllowsCached($storeId, 'kiosk')
            && ! empty(store_options($storeId, 'kiosk_enabled'));
    }

    public static function active(int $storeId, ?Session $session = null): bool
    {
        $session ??= session()->driver();

        return ! empty(((array) $session->get(self::KEY, []))[$storeId]);
    }
}
