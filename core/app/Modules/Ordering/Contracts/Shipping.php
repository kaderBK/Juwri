<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use Carbon\CarbonImmutable;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Data\ShippingOption;
use Juwri\Modules\Ordering\Models\ShippingZone;

/**
 * P3-08: the shipping methods a store offers for an address and a cart (zones, fees with the free-shipping threshold,
 * delivery dates with cut-offs, pickup in store). Implemented by Services\Shipping\ShippingQuotes.
 */
interface Shipping
{
    /** @param  array<string, mixed>  $address */
    public function zoneFor(Store $store, array $address): ?ShippingZone;

    /**
     * @param  array<string, mixed>  $address
     * @return list<ShippingOption>
     */
    public function options(Store $store, array $address, int $itemsSubtotalMinor, ?CarbonImmutable $now = null): array;

    /** @param  array<string, mixed>  $address */
    public function find(Store $store, string $publicId, array $address, int $itemsSubtotalMinor, ?CarbonImmutable $now = null): ?ShippingOption;
}
