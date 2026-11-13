<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Core\Support\SecretMask;
use Juwri\Modules\Payments\Models\StorePaymentGateway;

/**
 * P1-07: per-store payment gateway settings — one `store_payment_gateways` row per store + gateway,
 * non-secret fields in `config`, secret fields encrypted in `credentials`.
 *
 * Settings keep their legacy names (`restaurant_{gateway}_{field}`) at the edges: StoreOption /
 * store_options() and GatewaySecretVault route those names here, so drivers and forms read and write
 * them unchanged while the storage is the new table.
 *
 * Security:
 *  - every read and write is scoped by an explicit store id (a row of another store is never
 *    returned, whatever the key);
 *  - a field is a credential when GatewaySecretVault::isSecret() says so (the one list); credentials
 *    are encrypted at rest, a masked echo from a form keeps the stored value, a blank clears it;
 *  - masked() is what screens get; all() (plaintext) is for runtime payment code only;
 *  - the model hides `credentials` from toArray()/toJson().
 */
final class StorePaymentGateways
{
    /** Gateways stored here (the owner-facing store gateways + 2Checkout + the BNPL switches). */
    public const GATEWAYS = [
        'paypal', 'stripe', 'razorpay', 'mollie', 'paytm', 'paystack', 'payumoney', 'iyzico', 'midtrans', 'paytabs',
        'telr', 'ccavenue', 'tap', 'flutterwave', 'tdsp', 'paylink', 'paymob', 'geidea', 'tamara', 'tabby', 'fawry',
        'fatora', 'noon', 'moyasar', 'wallet_transfer', 'ecash', 'qrpay', '2checkout', 'bnpl',
        // P1-23 part 6: the store's own bank details for store payments paid by bank transfer (confirmed by the owner).
        'wire_transfer',
    ];

    /** @var array<int, array<string, StorePaymentGateway>> store id => gateway => row */
    private static array $memo = [];

    private static ?int $memoApp = null;

    /**
     * Split a legacy key into gateway + field, or null when the key is not a gateway setting.
     *
     * @return array{gateway: string, field: string}|null
     */
    public static function parse(?string $key): ?array
    {
        if ($key === null || ! str_starts_with($key, 'restaurant_')) {
            return null;
        }
        $rest = substr($key, strlen('restaurant_'));
        $best = null;
        foreach (self::GATEWAYS as $gateway) {
            if (str_starts_with($rest, $gateway.'_') && strlen($rest) > strlen($gateway) + 1
                && ($best === null || strlen($gateway) > strlen($best))) {
                $best = $gateway;
            }
        }

        return $best === null ? null : ['gateway' => $best, 'field' => substr($rest, strlen($best) + 1)];
    }

    public static function handles(?string $key): bool
    {
        return self::parse($key) !== null;
    }

    public static function isCredential(string $key): bool
    {
        return self::handles($key) && GatewaySecretVault::isSecret($key);
    }

    /** The stored value of one legacy key for one store (credentials decrypted), or null. */
    public static function get(int $storeId, string $key): mixed
    {
        $parsed = self::parse($key);
        if ($parsed === null) {
            return null;
        }
        $row = self::rows($storeId)[$parsed['gateway']] ?? null;
        if ($row === null) {
            return null;
        }

        return self::isCredential($key)
            ? ($row->credentials[$parsed['field']] ?? null)
            : ($row->config[$parsed['field']] ?? null);
    }

    /**
     * Save one legacy key for one store. Credentials: a masked echo keeps the stored secret,
     * null/'' clears it. Config: null clears the field. A row with nothing left is removed.
     */
    public static function put(int $storeId, string $key, mixed $value): void
    {
        $parsed = self::parse($key);
        if ($parsed === null) {
            return;
        }
        $credential = self::isCredential($key);
        if ($credential && SecretMask::isMasked($value)) {
            return;
        }

        $row = StorePaymentGateway::query()
            ->where('store_id', $storeId)
            ->where('gateway', $parsed['gateway'])
            ->first()
            ?? new StorePaymentGateway(['store_id' => $storeId, 'gateway' => $parsed['gateway']]);

        $field = $parsed['field'];
        if ($credential) {
            $credentials = $row->credentials ?? [];
            if ($value === null || $value === '') {
                unset($credentials[$field]);
            } else {
                $credentials[$field] = (string) $value;
            }
            $row->credentials = $credentials === [] ? null : $credentials;
        } else {
            $config = $row->config ?? [];
            if ($value === null) {
                unset($config[$field]);
            } else {
                // The text form store_options held ('1'/'0', numbers as strings).
                $config[$field] = match (true) {
                    is_bool($value) => $value ? '1' : '0',
                    is_int($value), is_float($value) => (string) $value,
                    default => $value,
                };
            }
            $row->config = $config === [] ? null : $config;
            if ($field === 'install') {
                $row->enabled = filter_var($config['install'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }

        if ($row->config === null && $row->credentials === null) {
            if ($row->exists) {
                $row->delete();
            }
        } else {
            $row->account_id ??= self::accountId($storeId);
            $row->save();
        }
        self::forgetMemo();
    }

    /**
     * Every gateway setting of a store under its legacy key, credentials in PLAINTEXT — for runtime
     * payment code (store_options() overlay). Never pass this to a view or a response.
     *
     * @return array<string, mixed>
     */
    public static function all(int $storeId): array
    {
        $out = [];
        foreach (self::rows($storeId) as $gateway => $row) {
            foreach ($row->config ?? [] as $field => $value) {
                $out['restaurant_'.$gateway.'_'.$field] = $value;
            }
            foreach ($row->credentials ?? [] as $field => $value) {
                $out['restaurant_'.$gateway.'_'.$field] = $value;
            }
        }

        return $out;
    }

    /**
     * Like all(), with every credential replaced by its display mask — what a settings form or an
     * API response may show.
     *
     * @return array<string, mixed>
     */
    public static function masked(int $storeId): array
    {
        $out = self::all($storeId);
        foreach ($out as $key => $value) {
            if (self::isCredential($key)) {
                $out[$key] = SecretMask::mask(is_scalar($value) ? (string) $value : '');
            }
        }

        return $out;
    }

    /**
     * The NON-secret gateway settings of a store as store_options-shaped rows, for the legacy
     * store backup (credentials never leave).
     *
     * @return list<array{store_id: int, option_name: string, option_value: mixed}>
     */
    public static function legacyRows(int $storeId): array
    {
        $rows = [];
        foreach (self::all($storeId) as $name => $value) {
            if (! self::isCredential($name)) {
                $rows[] = ['store_id' => $storeId, 'option_name' => $name, 'option_value' => is_array($value) ? json_encode($value) : $value];
            }
        }

        return $rows;
    }

    /**
     * Gateways this store has switched on.
     *
     * @return list<string>
     */
    public static function enabledGateways(int $storeId): array
    {
        return array_keys(array_filter(self::rows($storeId), fn (StorePaymentGateway $r) => $r->enabled));
    }

    /**
     * Ownership transfer (WI-06f): the new owner reconnects. Credentials and every config field except
     * the ones in $keepFields are removed and the gateway is switched off, for the listed gateways.
     * Returns the legacy keys that were reset.
     *
     * @param  list<string>  $gateways
     * @param  list<string>  $keepFields
     * @return list<string>
     */
    public static function resetForTransfer(int $storeId, array $gateways, array $keepFields = ['title']): array
    {
        $reset = [];
        foreach (StorePaymentGateway::query()->where('store_id', $storeId)->whereIn('gateway', $gateways)->get() as $row) {
            $keep = [];
            foreach ($row->config ?? [] as $field => $value) {
                if (in_array($field, $keepFields, true)) {
                    $keep[$field] = $value;
                } else {
                    $reset[] = 'restaurant_'.$row->gateway.'_'.$field;
                }
            }
            foreach (array_keys($row->credentials ?? []) as $field) {
                $reset[] = 'restaurant_'.$row->gateway.'_'.$field;
            }
            if ($keep === []) {
                $row->delete();
            } else {
                $row->forceFill(['config' => $keep, 'credentials' => null, 'enabled' => false])->save();
            }
        }
        self::forgetMemo();
        sort($reset);

        return $reset;
    }

    public static function forgetMemo(): void
    {
        self::$memo = [];
    }

    /** @return array<string, StorePaymentGateway> gateway => row, for ONE store only */
    private static function rows(int $storeId): array
    {
        $app = RequestEpoch::id();
        if (self::$memoApp !== $app) {
            self::$memoApp = $app;
            self::$memo = [];
        }

        return self::$memo[$storeId] ??= StorePaymentGateway::query()
            ->where('store_id', $storeId)
            ->get()
            ->keyBy('gateway')
            ->all();
    }

    private static function accountId(int $storeId): ?int
    {
        $id = DB::table('stores')->where('id', $storeId)->value('account_id');

        return $id === null ? null : (int) $id;
    }
}
