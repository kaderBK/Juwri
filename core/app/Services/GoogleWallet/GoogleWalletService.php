<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\GoogleWallet;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Loyalty\Services\GoogleWallet\GiftCardPassMapper;
use Juwri\Modules\Loyalty\Services\GoogleWallet\LoyaltyPassMapper;
use Juwri\Modules\Loyalty\Services\GoogleWallet\PassJwt;

/**
 * Google Wallet passes (loyalty cards + gift cards) — the ONE gate + entry point.
 *
 * NAMING: "wallet" is already taken (CustomerWalletService = store credit,
 * WalletController = marketplace seller wallet). Everything Google-Wallet is
 * namespaced `GoogleWallet*` / `google_wallet` to avoid collision.
 *
 * DARK by default: enabled() is false unless BOTH the platform issuer id and the
 * service-account key path are configured (config/services.php google_wallet).
 * When disabled, callers (hooks, endpoints, storefront/dashboard UI) all no-op —
 * nothing renders, no job dispatches, no endpoint returns a save URL.
 *
 * Per store, the owner additionally flips the `google_wallet_enabled` post_option
 * (STRING '1'/'0') — enabledForStore() ANDs the platform gate with that toggle.
 */
class GoogleWalletService
{
    /**
     * Platform-level gate: are Google Wallet credentials present at all? Cheap and
     * side-effect-free (does NOT read the key file) so it is safe to call on every
     * request / hook. A false here makes the whole feature inert.
     */
    public static function enabled(): bool
    {
        return self::issuerId() !== '' && self::serviceAccountPath() !== '';
    }

    /**
     * Per-store gate: platform creds present AND the owner turned the store toggle on.
     * store_options returns a STRING ('1'/'0'/null) — compare as string, never `=== true`.
     *
     * @param  Store|int  $store  a Store model or a raw restaurant id
     */
    public static function enabledForStore($store): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $storeId = is_object($store) ? (int) ($store->id ?? 0) : (int) $store;
        if ($storeId <= 0) {
            return false;
        }

        return (string) store_options($storeId, 'google_wallet_enabled', '0') === '1';
    }

    /** The platform issuer account id (numeric string), or '' when unset. */
    public static function issuerId(): string
    {
        return trim((string) config('services.google_wallet.issuer_id', ''));
    }

    /** Absolute path to the service-account key JSON, or '' when unset. */
    public static function serviceAccountPath(): string
    {
        return trim((string) config('services.google_wallet.service_account_json', ''));
    }

    /**
     * Extra static save-link origins from config (the store origin is added at build time).
     *
     * @return string[]
     */
    public static function configuredOrigins(): array
    {
        $origins = config('services.google_wallet.origins', []);

        return is_array($origins) ? array_values(array_filter($origins)) : [];
    }

    /**
     * The store's effective public origin (scheme + host), for the save-link `origins`
     * allow-list. Reseller/custom-domain aware via the P0 helper; falls back to the
     * platform host. Deduped with any configured extra origins.
     *
     * @return string[]
     */
    public static function originsForStore(Store $store): array
    {
        $origins = self::configuredOrigins();

        $url = effective_public_url($store);
        $parts = parse_url($url);
        if (! empty($parts['scheme']) && ! empty($parts['host'])) {
            $origin = $parts['scheme'].'://'.$parts['host'];
            if (! empty($parts['port'])) {
                $origin .= ':'.$parts['port'];
            }
            array_unshift($origins, $origin);
        }

        return array_values(array_unique(array_filter($origins)));
    }

    /**
     * The "Save to Google Wallet" URL for a customer's loyalty card, or null when the
     * store isn't enabled or the JWT can't be signed. Fail-soft throughout.
     */
    public static function loyaltySaveUrl(Store $store, LoyaltyProgram $program, LoyaltyAccount $account, ?Customer $customer = null): ?string
    {
        if (! self::enabledForStore($store)) {
            return null;
        }

        $payload = LoyaltyPassMapper::payload($store, $program, $account, $customer);

        return PassJwt::saveUrl($payload, self::originsForStore($store));
    }

    /**
     * The "Save to Google Wallet" URL for a gift card, or null when the store isn't
     * enabled or the JWT can't be signed. Fail-soft throughout.
     */
    public static function giftCardSaveUrl(Store $store, GiftCard $card): ?string
    {
        if (! self::enabledForStore($store)) {
            return null;
        }

        $payload = GiftCardPassMapper::payload($store, $card);

        return PassJwt::saveUrl($payload, self::originsForStore($store));
    }

    /**
     * PATCH a wallet object's current state via the Wallet Objects REST API. Used by
     * the queued SyncGoogleWalletPass job to keep a saved pass's balance fresh.
     * Returns true on a 2xx. Fail-soft: a missing token or non-2xx (incl. 404 when
     * the pass was never saved) returns false without throwing.
     *
     * @param  string  $resource  'loyaltyObject' | 'giftCardObject'
     * @param  array<string, mixed>  $body
     */
    public static function patchObject(string $resource, string $objectId, array $body): bool
    {
        $token = PassJwt::accessToken();
        if ($token === null || $token === '') {
            return false;
        }

        $resp = Http::withToken($token)
            ->patch(
                'https://walletobjects.googleapis.com/walletobjects/v1/'.$resource.'/'.rawurlencode($objectId),
                $body
            );

        return $resp->successful();
    }
}
