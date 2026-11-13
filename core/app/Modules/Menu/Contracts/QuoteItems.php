<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use Juwri\Modules\Menu\Models\Menu;

/**
 * The items a cart quote reads, loaded once per quote with their choice groups, add-ons, variants and branch
 * settings (load-test follow-up: the quote was an N+1). Outside {@see during()} every lookup is a fresh query.
 */
interface QuoteItems
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function during(callable $fn): mixed;

    public function active(): bool;

    /** @param  list<string>  $publicIds */
    public function prime(int $storeId, array $publicIds): void;

    /** @param  list<mixed>  $ids  numeric item ids (others are left to a lookup of their own) */
    public function primeIds(int $storeId, array $ids): void;

    public function byPublicId(int $storeId, string $publicId): ?Menu;

    public function byId(int $storeId, mixed $id): ?Menu;
}
