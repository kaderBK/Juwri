<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Tax;

use App\Models\Order;
use Juwri\Modules\Ordering\Contracts\OrderTax;

/** P6-126: the OrderTax contract over TaxEngine. */
final class EngineOrderTax implements OrderTax
{
    public function pricesIncludeTax(Order $order): bool
    {
        return (bool) (TaxEngine::compute($order)['inclusive'] ?? false);
    }

    public function levyLines(Order $order): array
    {
        return TaxEngine::levyLines($order);
    }

    public function levyTotal(Order $order): float
    {
        return TaxEngine::levyTotal($order);
    }

    public function storeRate(int $storeId): ?float
    {
        return TaxByRate::storeRate($storeId);
    }

    public function perLineEnabled(int $storeId): bool
    {
        return TaxEngine::enabledFor(store_options($storeId));
    }

    public function pricesIncludeVat(int $storeId): bool
    {
        return TaxEngine::inclusive(store_options($storeId));
    }

    public function byRateMinor(iterable $orders, int $storeId, string $currency): array
    {
        return TaxByRate::minor($orders, $storeId, $currency);
    }
}
