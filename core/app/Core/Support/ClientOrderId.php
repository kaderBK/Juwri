<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * J27-A2: the client order key every order-creating entry point accepts.
 *
 * The client (storefront / POS / app / integrator) mints one UUID per checkout attempt — UUIDv7 preferred, v4
 * accepted — and resends it unchanged on every retry. The server stores it in `orders.client_uuid` (UNIQUE per
 * store) with a fingerprint of the cart in `orders.client_order_hash`:
 *  - same key + same cart  → the SAME order comes back (no second row, no second charge);
 *  - same key + other cart → 409 (ClientOrderConflict);
 *  - a row without a fingerprint (created before J27-A2) always replays.
 */
final class ClientOrderId
{
    /** RFC 9562 UUID: versions 1-8, variant 10xx. */
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /** The key the POS register minted before J27-A2 — still accepted so sales queued offline replay. */
    private const LEGACY_POS = '/^pos-\d{10,16}-[a-z0-9]{1,16}$/';

    /**
     * Read the key from the body (`client_order_id`, else the legacy `client_uuid`) or the `Idempotency-Key`
     * header. Null when none was sent. A malformed key is a 422 — it must never silently disable the dedupe.
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, bool $acceptLegacyPos = false): ?string
    {
        foreach (['client_order_id', 'client_uuid'] as $field) {
            $value = $request->input($field);
            if ($value !== null && $value !== '') {
                return self::validated($value, $field, $acceptLegacyPos);
            }
        }

        $header = $request->header('Idempotency-Key');

        return $header !== null && $header !== '' ? self::validated($header, 'client_order_id', $acceptLegacyPos, 'Idempotency-Key') : null;
    }

    /**
     * A key that is not a UUID is a 422 under `$field`, worded "The <attribute> field must be a valid UUID." —
     * `$attribute` names what the client sent (the body field, or the Idempotency-Key header).
     *
     * @throws ValidationException
     */
    public static function validated(mixed $value, string $field = 'client_order_id', bool $acceptLegacyPos = false, ?string $attribute = null): string
    {
        $key = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match(self::UUID, $key) || ($acceptLegacyPos && preg_match(self::LEGACY_POS, $key))) {
            return $key;
        }

        throw ValidationException::withMessages([
            $field => trans('validation.uuid', ['attribute' => $attribute ?? str_replace('_', ' ', $field)]),
        ]);
    }

    /**
     * sha256 of the canonical cart. Key order, number spelling ("2" / 2 / 2.0) and empty fields do not matter;
     * line order does (a retry resends the same body).
     *
     * @param  array<string, mixed>  $cart
     */
    public static function fingerprint(array $cart): string
    {
        return hash('sha256', (string) json_encode(self::canonical($cart), JSON_UNESCAPED_UNICODE));
    }

    /**
     * The order a key already created in this store, or null. Throws when it was created from a different cart.
     *
     * @throws ClientOrderConflict
     */
    public static function replay(int $storeId, ?string $key, ?string $hash): ?Order
    {
        if ($key === null || $key === '') {
            return null;
        }

        $existing = Order::where('store_id', $storeId)->where('client_uuid', $key)->first();
        if ($existing) {
            self::assertSameCart($existing, $hash);
        }

        return $existing;
    }

    /** @throws ClientOrderConflict */
    public static function assertSameCart(Order $existing, ?string $hash): void
    {
        $stored = $existing->client_order_hash;
        if ($stored !== null && $stored !== '' && $hash !== null && ! hash_equals((string) $stored, $hash)) {
            throw new ClientOrderConflict((int) $existing->id);
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $v = self::canonical($v);
                if ($v === null || $v === '' || $v === []) {
                    continue;
                }
                $out[$k] = $v;
            }
            if (! array_is_list($value)) {
                ksort($out, SORT_STRING);
            } else {
                $out = array_values($out);
            }

            return $out;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

            return $number === '' || $number === '-0' ? '0' : $number;
        }

        return is_string($value) ? trim($value) : $value;
    }
}
