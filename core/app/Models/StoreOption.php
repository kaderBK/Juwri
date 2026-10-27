<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Support\Str;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Core\Support\SecretMask;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Services\StorePaymentGateways;

/**
 * P1-07 compatibility shim: the historical `StoreOption::getStoreOption / updateStoreOption / bulkOptions /
 * incrementStoreOption` API (and the `store_options()` helper on top of it) over the typed stores.
 * The `store_options` table and this model's Eloquent side are gone (slice 5d): `stores.settings`
 * (StoreSettings) is the only store of the values, payment gateway names live in
 * `store_payment_gateways` and vaulted secrets in the Credential vault. New code uses StoreSettings
 * directly; this class only keeps the ~2,300 legacy call sites returning exactly what they always did.
 */
class StoreOption
{
    /**
     * Per-request memo of non-secret single-key reads (WI-G5). The menu editor
     * renders a partial per item + per category, each calling
     * getStoreOption($store->id,'default_language') with the same arguments — that
     * was one identical SELECT per row. The cache is flushed on any write below
     * so a value changed in the same request is never served stale.
     *
     * @var array<string,mixed> "storeId:key" => raw value (null = not found)
     */
    protected static array $optionMemo = [];

    protected static ?int $memoApp = null;

    protected static int $memoVersion = -1;

    /**
     * P1: per-request bulk memo of the keyless read — `store_options($id)` re-queried the
     * store's whole store_options table on every call (once per order-row total, storefront
     * partial, etc). Keyed by store_id => decoded [option_name => value].
     *
     * @var array<int,array<string,mixed>>
     */
    protected static array $bulkMemo = [];

    /**
     * Bind the memo to the current application instance. Each request — and each
     * test, which boots a fresh app — gets a clean bucket, so a value cached in
     * one request/test can never leak into the next (which, without this, made a
     * later request read a stale option).
     */
    protected static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        // P1-07: a StoreSettings write (from anywhere) also invalidates these derived memos.
        if (self::$memoApp !== $id || self::$memoVersion !== StoreSettings::version()) {
            self::$memoApp = $id;
            self::$memoVersion = StoreSettings::version();
            self::$optionMemo = [];
            self::$bulkMemo = [];
        }
    }

    /** Drop the per-request read memos (also called from the vault write path). */
    public static function flushOptionMemo(): void
    {
        self::$optionMemo = [];
        self::$bulkMemo = [];
        StoreSettings::flush(); // a rolled-back write must not leave stale settings in the per-request memo
    }

    /**
     * P1: memoized bulk read of all non-secret options for a store (decoded). Seeds the
     * single-key memo so a later getStoreOption($id,$key) in the same request is a hit,
     * not a query. Returns [option_name => value]; the caller overlays vault secrets.
     *
     * @return array<string,mixed>
     */
    public static function bulkOptions(int|string $storeId): array
    {
        self::scopeMemo();
        $storeId = (int) $storeId;
        if (array_key_exists($storeId, self::$bulkMemo)) {
            return self::$bulkMemo[$storeId];
        }

        $decoded = [];
        foreach (StoreSettings::legacyMap($storeId) as $name => $value) {
            $decoded[$name] = Str::isJson($value) ? json_decode($value) : $value;
            // Seed the single-key memo (non-secret keys) so getStoreOption is a memo hit.
            self::$optionMemo[$storeId.':'.$name] = $decoded[$name];
        }

        return self::$bulkMemo[$storeId] = $decoded;
    }

    /**
     * One store setting, JSON values decoded. BYOK gateway secrets come from
     * the encrypted vault first. A missing (or null) value returns the
     * default, and false when the default is empty.
     */
    public static function getStoreOption($storeId, $key, $default = null)
    {
        // P1-07: payment gateway settings live in store_payment_gateways (credentials encrypted).
        if (StorePaymentGateways::handles($key)) {
            $value = StorePaymentGateways::get((int) $storeId, (string) $key);
            if ($value !== null) {
                return (! StorePaymentGateways::isCredential((string) $key) && is_string($value) && Str::isJson($value))
                    ? json_decode($value) : $value;
            }

            return (! empty($default)) ? $default : false;
        }

        // BYOK gateway secrets live in the encrypted Credential vault, not here.
        if (GatewaySecretVault::isSecret($key)) {
            $secret = GatewaySecretVault::get($storeId, $key);
            if ($secret !== null) {
                return $secret;
            }
            // Fall through: read a not-yet-migrated plaintext value if one exists.
        }

        self::scopeMemo();
        $ck = $storeId.':'.$key;
        if (! array_key_exists($ck, self::$optionMemo)) {
            $legacy = StoreSettings::legacy((int) $storeId, (string) $key);
            self::$optionMemo[$ck] = $legacy !== null
                ? (Str::isJson($legacy) ? json_decode($legacy) : $legacy)
                : null;
        }
        $value = self::$optionMemo[$ck];
        if ($value !== null) {
            return $value;
        }

        return (! empty($default)) ? $default : false;
    }

    /**
     * Insert or overwrite one store setting; returns the save result. Gateway
     * secrets go to the encrypted vault instead (a masked echo of the stored
     * secret is ignored) and any plaintext row for them is removed.
     */
    public static function updateStoreOption($storeId, $key, $value)
    {
        // P1-07: payment gateway settings go to store_payment_gateways (masked echo of a credential
        // keeps it); a legacy store_options row for the same key is dropped.
        if (StorePaymentGateways::handles($key)) {
            StorePaymentGateways::put((int) $storeId, (string) $key, $value);

            return true;
        }

        // Route gateway credentials into the encrypted vault and never persist
        // them plaintext (drop any legacy plaintext row for the same key).
        if (GatewaySecretVault::isSecret($key)) {
            // The settings form echoes the display mask back for an untouched
            // secret field: keep the stored value.
            if (SecretMask::isMasked($value)) {
                return true;
            }
            GatewaySecretVault::put($storeId, $key, $value);
            StoreSettings::forget((int) $storeId, (string) $key);

            return true;
        }

        $saved = StoreSettings::set((int) $storeId, (string) $key, $value);
        self::flushOptionMemo();

        return $saved;
    }

    /**
     * Atomically add $delta to a numeric option value. The read-modify-write in
     * updateStoreOption() loses concurrent updates — two distinct paid orders for
     * the same store settling at once both read the same wallet_amount and one
     * write vanishes. StoreSettings::increment() does the accumulation under a
     * row lock on the store's settings. $delta is numeric — never user input.
     */
    public static function incrementStoreOption(int $storeId, string $key, float $delta): void
    {
        StoreSettings::increment($storeId, $key, $delta);
        self::flushOptionMemo();
    }
}
