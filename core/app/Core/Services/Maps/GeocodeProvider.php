<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Maps;

/**
 * theme-pack-blade F5: geocode/maps provider contract. One implementation per
 * backend (OSM/Photon+Nominatim, Google). MapsManager resolves the active one
 * from platform settings. ALL calls run server-side (proxy endpoints) so keys
 * and the descriptive User-Agent never reach the browser.
 */
interface GeocodeProvider
{
    /** Provider key, e.g. 'osm' | 'google'. */
    public function key(): string;

    /** True when the provider is usable (e.g. Google needs an API key). */
    public function isConfigured(): bool;

    /**
     * Address suggestions for a partial query.
     *
     * @return array<int,array{label:string,lat:float|null,lng:float|null,raw:array}>
     */
    public function autocomplete(string $query): array;

    /**
     * Reverse-geocode a coordinate to an address.
     *
     * @return array{label:string,lat:float,lng:float,raw:array}|null
     */
    public function reverse(float $lat, float $lng): ?array;

    /**
     * Forward-geocode an address string to a coordinate.
     *
     * @return array{lat:float,lng:float,label:string,raw:array}|null
     */
    public function forward(string $address): ?array;

    /** Attribution string to render on any map/autocomplete surface. */
    public function attribution(): string;
}
