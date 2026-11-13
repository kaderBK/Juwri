<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;

/** P1-23: the Community default of {@see SalesRestrictions} — no store type restricts its sales. */
final class NoSalesRestrictions implements SalesRestrictions
{
    public function unpublishedReason(Store $store): ?string
    {
        return null;
    }

    public function orderRefusal(Store $store, array $lines): ?string
    {
        return null;
    }

    public function promotionsBlocked(Store $store, iterable $items): bool
    {
        return false;
    }
}
