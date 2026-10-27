<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Marketplace\ShopperBag;

/**
 * P1-13 Community default: no marketplace shoppers — the cross-store cart is owned by its guest cookie token, no
 * store customer is linked and there is no marketplace bag quote (no delivery-zone fee, no round-up donation).
 */
final class NoShopperBag implements ShopperBag
{
    public function shopperId(): ?int
    {
        return null;
    }

    public function shopper(): ?object
    {
        return null;
    }

    public function linkStoreCustomer(object $shopper, int $storeId, array $contact): ?int
    {
        return null;
    }

    public function quote(object $cart, ?float $lat, ?float $lng): array
    {
        return [];
    }

    public function quoteWithCoupons(?float $lat, ?float $lng): array
    {
        return [];
    }
}
