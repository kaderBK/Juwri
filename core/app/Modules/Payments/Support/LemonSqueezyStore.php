<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The currency a Lemon Squeezy store sells in. A checkout's `custom_price` carries no currency: Lemon Squeezy reads it
 * in the STORE's currency ("The currency you choose for your store … will be used throughout the Lemon Squeezy
 * dashboard, checkout …", docs.lemonsqueezy.com/help/payments/currencies). So a payment in any other currency must be
 * refused before a checkout is created — the platform checkout (LemonSqueezyController) and reseller billing
 * (LemonSqueezyBilling) both ask here. Store object: docs.lemonsqueezy.com/api/stores/the-store-object.
 */
final class LemonSqueezyStore
{
    /** The store's currency (upper case), or null when it cannot be read (no key/store, HTTP error, no currency). */
    public static function currency(?string $apiKey, string|int|null $storeId): ?string
    {
        $storeId = trim((string) $storeId);
        if (trim((string) $apiKey) === '' || $storeId === '') {
            return null;
        }
        try {
            $response = Http::withToken((string) $apiKey)
                ->withHeaders(['Accept' => 'application/vnd.api+json'])
                ->timeout(20)
                ->get('https://api.lemonsqueezy.com/v1/stores/'.rawurlencode($storeId));
        } catch (\Throwable $e) {
            Log::info('[lemon_squeezy] store lookup failed: '.$e->getMessage());

            return null;
        }
        $currency = $response->successful() ? $response->json('data.attributes.currency') : null;

        return is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;
    }

    /**
     * Whether a payment in $currency can be charged on this store: the store sells in exactly that currency, and it is
     * not a 3-decimal currency (none is a Lemon Squeezy selling currency; GatewayMinorUnits::decimals()).
     */
    public static function canCharge(?string $apiKey, string|int|null $storeId, string $currency): bool
    {
        $currency = strtoupper(trim($currency));

        return GatewayMinorUnits::decimals($currency) <= 2 && self::currency($apiKey, $storeId) === $currency;
    }
}
