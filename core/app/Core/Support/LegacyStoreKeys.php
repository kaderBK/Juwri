<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Juwri\Core\Http\Middleware\AcceptLegacyStoreKeys;

/**
 * P1-04 slice 3: the tenant columns `restaurant_id` / `post_id` are now `store_id`.
 * External clients (published Zapier / Make / n8n apps, custom storefronts, cached
 * storefront JS, MCP clients, merchant webhook receivers) still speak the old keys, so:
 *
 *  - requests: {@see alias()} turns an incoming `restaurant_id` / `post_id` into
 *    `store_id` (recursively; an explicit `store_id` wins) — used by
 *    {@see AcceptLegacyStoreKeys} for every non-webhook request;
 *  - responses: {@see mirror()} adds `restaurant_id` next to every `store_id` in the
 *    JSON of the public API (`api/*`) and in outbound webhook payloads, so readers of
 *    the old key keep working.
 *
 * `post_id` is accepted on input but never mirrored (it was never a public API key).
 */
final class LegacyStoreKeys
{
    /** Old request keys, in precedence order. */
    public const OLD_KEYS = ['restaurant_id', 'post_id'];

    public const KEY = 'store_id';

    public const MIRROR_KEY = 'restaurant_id';

    private const MAX_DEPTH = 16;

    /**
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $used  old keys that were found (for the deprecation log)
     * @return array<array-key, mixed>
     */
    public static function alias(array $input, array &$used = [], int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $input;
        }
        foreach (self::OLD_KEYS as $old) {
            if (array_key_exists($old, $input)) {
                $used[] = $old;
                if (! array_key_exists(self::KEY, $input)) {
                    $input[self::KEY] = $input[$old];
                }
                unset($input[$old]);
            }
        }
        foreach ($input as $k => $v) {
            if (is_array($v)) {
                $input[$k] = self::alias($v, $used, $depth + 1);
            }
        }

        return $input;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function mirror(array $data, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::mirror($v, $depth + 1);
            }
        }
        if (array_key_exists(self::KEY, $data) && ! array_key_exists(self::MIRROR_KEY, $data)
            && (is_int($data[self::KEY]) || is_string($data[self::KEY]) || $data[self::KEY] === null)) {
            $data[self::MIRROR_KEY] = $data[self::KEY];
        }

        return $data;
    }
}
