<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Juwri\Core\Models\Credential;
use Juwri\Core\Support\RequestEpoch;

/**
 * Encrypted-at-rest storage for per-restaurant BYOK payment-gateway secrets.
 *
 * Gateway secret keys (API/secret keys, HMAC secrets, webhook/notification
 * tokens, security keys) used to sit in the plaintext store_options table. They
 * now live in the encrypted Credential vault — one row per restaurant,
 * feature "gateway_secrets", secrets = { option_name: value } (Credential casts
 * secrets as encrypted:array). Access is transparent: StoreOption::getStoreOption /
 * updateStoreOption and the store_options() helper route these keys here, so no
 * driver or save path needed rewriting.
 *
 * Non-secret gateway options (install flags, titles, sandbox mode, public keys,
 * merchant/store ids, region) stay in store_options — they are not credentials.
 *
 * P1-07: payment GATEWAY secrets (`restaurant_{gateway}_*`) now live encrypted in
 * store_payment_gateways.credentials; get()/put() delegate those names to
 * StorePaymentGateways. This class stays the one list of which names are secrets
 * (isSecret) and keeps the non-gateway store credentials (meta_capi_token,
 * print_bridge_token) in the `gateway_secrets` Credential row.
 */
class GatewaySecretVault
{
    private const FEATURE = 'gateway_secrets';

    /** Exact post_option names that are credentials and must be vaulted. */
    private const SECRET_KEYS = [
        'restaurant_paypal_api_secret',
        'restaurant_paypal_api_app_id',
        'restaurant_stripe_secret_key',
        'restaurant_razorpay_secret_key',
        'restaurant_razorpay_webhook_secret',
        'restaurant_mollie_api_key',
        'restaurant_paytm_merchant_key',
        'restaurant_paystack_secret_key',
        'restaurant_payumoney_signature_key',
        'restaurant_payumoney_oauth_client_secret',
        'restaurant_iyzico_api_key',
        'restaurant_iyzico_secret_key',
        'restaurant_midtrans_server_key',
        'restaurant_paytabs_secret_key',
        'restaurant_telr_authkey',
        'restaurant_2checkout_private_key',
        'restaurant_ccavenue_working_key',
        'restaurant_ccavenue_merchant_key',
        'restaurant_flutterwave_secret_key',
        'restaurant_tap_secret_key',
        'restaurant_moyasar_secret_key',     // Phase 4 item 18
        'restaurant_moyasar_webhook_secret',
        'restaurant_tdsp_token',
        // PAYMENTS-RUN gateways
        'restaurant_paylink_secret_key',
        'restaurant_paymob_secret_key',
        'restaurant_paymob_hmac_secret',
        'restaurant_geidea_api_password',
        'restaurant_tamara_notification_token',
        'restaurant_tamara_api_token',
        'restaurant_tabby_secret_key',
        'restaurant_fawry_security_key',
        'restaurant_fatora_api_key',
        'restaurant_noon_api_key',
        'restaurant_noon_webhook_secret',
        // Syria gateways (wallet_transfer has no secrets)
        'restaurant_ecash_api_key',
        'restaurant_qrpay_secret_id',
        // 2026-09-27 audit: secrets still found plaintext in store_options.
        'restaurant_stripe_webhook_secret',   // the store's own Stripe webhook signing secret (webhook/stripe/store/{store})
        'restaurant_payumoney_merchant_salt', // legacy PayUmoney hash salt, no current reader
        // Non-gateway store credentials share the same encrypted row.
        'meta_capi_token',                    // Meta Conversions API access token
        'print_bridge_token',                 // desktop print-bridge pairing token
    ];

    /**
     * WI-M10: per-request memo of the vault row, keyed store_id => Credential|null.
     *
     * `store_options($id)` is memoised in StoreOption::bulkOptions(), but the vault
     * overlay one line later (Helper.php) re-queried `credentials` on EVERY call —
     * ~10 times per anonymous storefront render and once per row on any list that
     * touches Order::getTotalAttribute (Order.php). The row also carries an
     * `encrypted:array` cast, so each miss paid an APP_KEY decrypt too.
     *
     * Safety: unlike Credential::for() — deliberately NOT memoised because
     * credentials are written through paths that bypass model events — the
     * `gateway_secrets` feature has exactly ONE in-request writer, self::put(),
     * which clears this memo. The one-off migration's up() also goes through put();
     * its down() runs in its own CLI process.
     *
     * @var array<int, Credential|null>
     */
    private static array $rowMemo = [];

    /** The application instance $rowMemo was filled for. */
    private static ?int $memoApp = null;

    /**
     * Bind the memo to the current application instance, exactly as
     * StoreOption::scopeMemo() and FeatureAccess::scopeMemo() do. The memo is keyed
     * by a database id, and an id says nothing about WHICH database it came from:
     * under the test runner every test boots a fresh app and RefreshDatabase hands
     * the next test the same low ids, so without this a secret vaulted in one test
     * class answers for the unrelated store that inherits its id in the next.
     * Pinned by tests/Modules/Platform/StaticMemoAppScopeTest.php.
     */
    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$rowMemo = [];
        }
    }

    /** Drop the per-request vault memo (called on every vault write). */
    public static function forgetMemo(): void
    {
        self::$rowMemo = [];
    }

    public static function isSecret(?string $optionName): bool
    {
        return $optionName !== null && in_array($optionName, self::SECRET_KEYS, true);
    }

    /** @return array<int,string> the exact post_option names that are vaulted */
    public static function secretKeys(): array
    {
        return self::SECRET_KEYS;
    }

    /** Vaulted secret value for a restaurant, or null. */
    public static function get(int|string $storeId, string $optionName): ?string
    {
        if (StorePaymentGateways::handles($optionName)) {
            $value = StorePaymentGateways::get((int) $storeId, $optionName);

            return $value === null ? null : (string) $value;
        }
        $cred = self::row((int) $storeId);

        return $cred?->secrets[$optionName] ?? null;
    }

    /**
     * Store (or, for a null/empty value, clear) one secret. Keeps a single
     * encrypted row per restaurant.
     */
    public static function put(int|string $storeId, string $optionName, $value): void
    {
        if (StorePaymentGateways::handles($optionName)) {
            StorePaymentGateways::put((int) $storeId, $optionName, $value === null || $value === '' ? null : (string) $value);
            self::forgetMemo();

            return;
        }
        $cred = Credential::firstOrNew([
            'feature' => self::FEATURE,
            'store_id' => (int) $storeId,
        ]);
        $secrets = $cred->secrets ?? [];

        if ($value === null || $value === '') {
            unset($secrets[$optionName]);
        } else {
            $secrets[$optionName] = (string) $value;
        }

        $cred->provider = 'gateway';
        $cred->active = true;
        $cred->secrets = $secrets;
        $cred->save();

        // WI-M10: the read memo must not survive a write in the same request.
        self::forgetMemo();
    }

    /**
     * Overlay a restaurant's vaulted secrets onto an options map (option_name =>
     * value), so bulk `store_options($id)->restaurant_{gw}_secret_key` resolves.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public static function overlay(int|string $storeId, array $options): array
    {
        $cred = self::row((int) $storeId);
        if ($cred) {
            foreach (($cred->secrets ?? []) as $name => $value) {
                $options[$name] = $value;
            }
        }

        return $options;
    }

    private static function row(int $storeId): ?Credential
    {
        self::scopeMemo();

        // array_key_exists, NOT ??= or isset: the common case is "this store has no
        // vaulted secrets", i.e. a memoised NULL, and a null-coalescing memo would
        // re-query on every one of those hits — the exact miss this item fixes.
        if (array_key_exists($storeId, self::$rowMemo)) {
            return self::$rowMemo[$storeId];
        }

        return self::$rowMemo[$storeId] = Credential::where('feature', self::FEATURE)
            ->where('store_id', $storeId)
            ->where('active', true)
            ->first();
    }
}
