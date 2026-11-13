<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Contracts;

use Juwri\Core\Models\Store;

/**
 * P3-08b (aisle §13, kit/api `GET /catalog` `vehicle`): the vehicle a shopper picked and which products fit it, for
 * the storefront view model (`context.vehicle`, `ItemCard.fits_vehicle`) and the catalogue's vehicle filter.
 * Implemented by Services\Fitment\FitmentLookup, bound in VerticalsServiceProvider. Strictly scoped to `$store`.
 *
 * A vehicle key is `year|make|model` (optionally `|submodel|engine`), e.g. `2016|Honda|Civic`, or `vin:<VIN>` (a
 * full VIN or its first characters; matched against the VIN prefixes stores put in their fitment file).
 */
interface VehicleFitments
{
    /** Does this store sell by vehicle (its type has the fitment capability)? */
    public function enabled(Store $store): bool;

    /**
     * @return array{label: string, year?: int, make?: string, model?: string, submodel?: string, engine?: string, vin?: string}|null
     *                                                                                                                                  the vehicle of a key, or null when the key is not a valid one
     */
    public function resolve(Store $store, string $key): ?array;

    /**
     * Per product: true = fits the vehicle, false = it has fitments but none for this vehicle, null = it has no
     * fitments (unknown: universal or not yet entered).
     *
     * @param  list<int>  $menuIds
     * @param  array<string, mixed>  $vehicle  from {@see self::resolve()}
     * @return array<int, bool|null>
     */
    public function fits(Store $store, array $menuIds, array $vehicle): array;
}
