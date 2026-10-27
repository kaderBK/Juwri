<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Maps;

use Juwri\Core\Settings\PlatformSettings;

/**
 * theme-pack-blade F4+F5: resolves the active geocode provider from platform
 * settings (the admin Option store) and exposes the Haversine helper F4 uses.
 *
 * Settings (Option keys):
 *   - maps_provider          'osm' (default) | 'google'
 *   - maps_google_api_key    Google Maps/Places key (blank ⇒ google is inert)
 *   - maps_attribution       '1' (default) — show attribution on map surfaces
 *
 * When 'google' is selected but no key is configured, we fall back to OSM so the
 * storefront always has a working provider.
 */
class MapsManager
{
    /** @var array<string,GeocodeProvider> */
    private array $resolved = [];

    public function provider(?string $key = null): GeocodeProvider
    {
        $key ??= $this->configuredProviderKey();

        if ($key === 'google') {
            $google = $this->make('google');
            if ($google->isConfigured()) {
                return $google;
            }
            // Owner selected Google but supplied no key → stay functional on OSM.
            $key = 'osm';
        }

        return $this->make('osm');
    }

    /** The provider key stored in settings (unresolved — may be an inert 'google'). */
    public function configuredProviderKey(): string
    {
        $val = PlatformSettings::get('maps_provider', false);
        return in_array($val, ['osm', 'google'], true) ? $val : 'osm';
    }

    public function attributionEnabled(): bool
    {
        $val = PlatformSettings::get('maps_attribution', false);
        return $val === false ? true : (bool) $val; // default on
    }

    private function make(string $key): GeocodeProvider
    {
        return $this->resolved[$key] ??= match ($key) {
            'google' => new GoogleGeocodeProvider($this->googleKey()),
            default  => new OsmGeocodeProvider($this->userAgent()),
        };
    }

    private function googleKey(): ?string
    {
        $val = PlatformSettings::get('maps_google_api_key', false);
        return is_string($val) && trim($val) !== '' ? trim($val) : null;
    }

    /**
     * Descriptive User-Agent required by Nominatim's usage policy. Built from the
     * app name + URL so OSM can identify/​contact this deployment.
     */
    private function userAgent(): string
    {
        $name = config('app.name', 'juwri');
        $url  = config('app.url', 'https://localhost');
        return sprintf('%s/1.0 (+%s)', preg_replace('/\s+/', '', (string) $name), $url);
    }

    /**
     * F4 helper (optional): geocode a branch's address into {lat,lng} via the
     * active provider's forward(). Callers may persist the result on the branch.
     * Returns null when the address can't be resolved. Not required by the map
     * endpoint — owners can also set coordinates manually in branch admin.
     *
     * @return array{lat:float,lng:float}|null
     */
    public function geocodeAddress(?string $address): ?array
    {
        $address = trim((string) $address);
        if ($address === '') {
            return null;
        }
        $hit = $this->provider()->forward($address);
        return $hit ? ['lat' => $hit['lat'], 'lng' => $hit['lng']] : null;
    }

    /**
     * Great-circle distance in KILOMETRES between two coordinates (Haversine).
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0; // km
        $dLat  = deg2rad($lat2 - $lat1);
        $dLng  = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * asin(min(1.0, sqrt($a)));
    }
}
