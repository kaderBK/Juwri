<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use App\Services\FeatureAccess;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\PlanGate;

/**
 * P3-06b (kit/api README §2.6, API-5, API-13): the plan features of the headless storefront API and the one refusal
 * each answers. Every token call needs `storefront_api`; booking operations also need `booking_api` (phase D); every
 * call with a kiosk token needs `kiosk_api` and `kiosk` plus the store's `kiosk_enabled` (lane C). A missing feature
 * answers 403 `<feature>_not_enabled` with the key in `details.feature`; a missing `kiosk` answers `kiosk_not_enabled`.
 */
final class StorefrontApiFeatures
{
    public const STOREFRONT_API = 'storefront_api';

    public const BOOKING_API = 'booking_api';

    public const KIOSK_API = 'kiosk_api';

    public const KIOSK = 'kiosk';

    public static function code(string $feature): string
    {
        return $feature === self::KIOSK ? 'kiosk_not_enabled' : $feature.'_not_enabled';
    }

    /**
     * API-13: the refusal of a kiosk-token call, or null when it may go ahead. `kiosk_api` is a plan key (a plan that
     * holds no value keeps it, PlanGate); `kiosk` is the existing platform feature the web kiosk uses (FeatureAccess);
     * the store's `kiosk_enabled` is the owner's switch (403 `kiosk_not_enabled` without `details.feature`).
     */
    public static function kioskRefusal(Store $store, User $owner): ?JsonResponse
    {
        if (! PlanGate::allows($owner, self::KIOSK_API)) {
            return self::refusal(self::KIOSK_API);
        }
        if (! FeatureAccess::storeAllows((int) $store->id, self::KIOSK)) {
            return self::refusal(self::KIOSK);
        }
        if (! StoreSettings::bool((int) $store->id, 'kiosk_enabled')) {
            return ApiError::response(403, self::code(self::KIOSK), ___('Kiosk mode is switched off for this store.'), null, null, ['Cache-Control' => 'no-store']);
        }

        return null;
    }

    public static function refusal(string $feature): JsonResponse
    {
        $message = match ($feature) {
            self::BOOKING_API => ___('The booking API is not included in this store\'s plan.'),
            self::KIOSK_API => ___('The kiosk API is not included in this store\'s plan.'),
            self::KIOSK => ___('Kiosk ordering is not included in this store\'s plan.'),
            default => ___('The storefront API is not included in this store\'s plan.'),
        };

        return ApiError::response(403, self::code($feature), $message, null, ['feature' => $feature], ['Cache-Control' => 'no-store']);
    }
}
