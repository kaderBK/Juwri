<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Vin;

/**
 * Pluggable VIN-decode seam (torque car-parts theme). The storefront "Scan your
 * VIN" flow POSTs a VIN and expects a resolved Year/Make/Model/Engine.
 *
 * Locked decision (multi-vertical-templates-loop): ship a MOCK provider now,
 * swap to a real decoder (e.g. NHTSA vPIC / a paid VIN API) later by binding a
 * different implementation in AppServiceProvider — no theme or controller change.
 */
interface VinDecodeProvider
{
    /**
     * Resolve a VIN to a vehicle, or null if it can't be decoded.
     *
     * @param  string    $vin      the raw VIN as typed/scanned
     * @param  int|null  $storeId  the store, so a provider may prefer a vehicle
     *                             the store actually stocks parts for
     * @return array{year:int,make:string,model:string,engine:?string}|null
     */
    public function decode(string $vin, ?int $storeId = null): ?array;
}
