<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Contracts\Cache\Repository;

/**
 * A cache repository view whose keys all carry one account prefix. Methods are forwarded to the underlying
 * repository with the key argument(s) prefixed.
 *
 * @mixin Repository
 */
final class TenantCacheRepository
{
    /** Methods whose first argument is an array of keys (many) or key => value pairs (putMany). */
    private const KEY_LIST = ['many'];

    private const KEY_MAP = ['putMany', 'setMultiple'];

    public function __construct(private readonly Repository $cache, private readonly string $prefix) {}

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** @param  array<int, mixed>  $args */
    public function __call(string $method, array $args): mixed
    {
        if ($args !== []) {
            if (is_string($args[0])) {
                $args[0] = $this->prefix.$args[0];
            } elseif (is_array($args[0]) && in_array($method, self::KEY_LIST, true)) {
                $args[0] = array_map(fn ($k) => $this->prefix.$k, $args[0]);
            } elseif (is_array($args[0]) && in_array($method, self::KEY_MAP, true)) {
                $mapped = [];
                foreach ($args[0] as $k => $v) {
                    $mapped[$this->prefix.$k] = $v;
                }
                $args[0] = $mapped;
            }
        }

        return $this->cache->{$method}(...$args);
    }
}
