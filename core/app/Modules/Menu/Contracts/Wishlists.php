<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use Juwri\Core\Models\Store;

/**
 * P3-08 (aisle §12): a shopper's wishlist at one store — a signed-in customer's (`$customerId`) or a guest's (the
 * hash of the browser's token, {@see guestKey()}). Implemented by Services\Retail\Wishlist.
 */
interface Wishlists
{
    /** The guest owner key of a browser token (null for a malformed token). */
    public static function guestKey(string $token): ?string;

    /** @return list<array{item_id: string, variant_id: string|null, added_at: string|null}> */
    public function list(Store $store, ?int $customerId, ?string $guestKey): array;

    /** @throws \DomainException not_found | no_owner */
    public function add(Store $store, ?int $customerId, ?string $guestKey, string $itemPublicId, ?string $variantPublicId = null): void;

    public function remove(Store $store, ?int $customerId, ?string $guestKey, string $itemPublicId): void;

    /**
     * Sign-in sync: the guest's list (and a headless client's own list of item ids) into the customer's.
     *
     * @param  list<string>  $itemPublicIds
     * @return list<array{item_id: string, variant_id: string|null, added_at: string|null}>
     */
    public function merge(Store $store, int $customerId, ?string $guestKey, array $itemPublicIds = []): array;
}
