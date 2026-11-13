<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P1-23 extension point: rules a store type's law adds to customer sales — today the pharmacy rules of the Acceptable
 * Use Policy 3.1 (selling only in an enabled country, with a reviewed licence; a per-market quantity cap the store can
 * only tighten; no promotions on medicines where a market forbids them). Asked by every customer-facing surface: the
 * storefront page and menu API, the web order, the guest channels of OrderApiService, the goods cart and checkout,
 * and the coupon step (OrderPricing). Community default: Services\Orders\NoSalesRestrictions
 * (nothing restricted); the Pharmacy module binds its own.
 */
interface SalesRestrictions
{
    /** Machine reason this store may not be shown or sell to the public right now; null when it may. */
    public function unpublishedReason(Store $store): ?string;

    /**
     * A customer-facing refusal for an order of these lines (quantities summed per item), or null when allowed.
     *
     * @param  list<array{menu: Menu, quantity: int}>  $lines
     */
    public function orderRefusal(Store $store, array $lines): ?string;

    /**
     * Whether coupons and other promotions must not apply to an order holding these items.
     *
     * @param  iterable<Menu>  $items
     */
    public function promotionsBlocked(Store $store, iterable $items): bool;
}
