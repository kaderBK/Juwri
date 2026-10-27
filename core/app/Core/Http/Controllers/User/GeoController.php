<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Maps\MapsManager;

/**
 * theme-pack-blade F4+F5: server-side proxy for maps/geocode and the
 * nearest-branch feed. Keeps provider keys + User-Agent off the browser.
 */
class GeoController extends Controller
{
    public function __construct(private readonly MapsManager $maps) {}

    /** F5 · GET /geo/autocomplete?q= — address suggestions. */
    public function autocomplete(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        $provider = $this->maps->provider();
        $results = mb_strlen(trim($q)) >= 2 ? $provider->autocomplete($q) : [];

        return response()->json([
            'provider' => $provider->key(),
            'attribution' => $this->maps->attributionEnabled() ? $provider->attribution() : null,
            'results' => $results,
        ]);
    }

    /** F5 · GET /geo/reverse?lat=&lng= — reverse geocode "use my location". */
    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $provider = $this->maps->provider();
        $result = $provider->reverse((float) $data['lat'], (float) $data['lng']);

        return response()->json([
            'provider' => $provider->key(),
            'attribution' => $this->maps->attributionEnabled() ? $provider->attribution() : null,
            'result' => $result,
        ]);
    }

    /**
     * F4 · GET /r/{store}/branches/geo — active branches with coords, plus
     * Haversine distance from an optional customer lat/lng, nearest-first.
     */
    public function branches(Request $request, string $store): JsonResponse
    {
        $store = Store::where('slug', $store)->firstOrFail();

        $data = $request->validate([
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);
        $custLat = isset($data['lat']) ? (float) $data['lat'] : null;
        $custLng = isset($data['lng']) ? (float) $data['lng'] : null;
        $haveCust = $custLat !== null && $custLng !== null;

        $branches = Branch::where('store_id', $store->id)
            ->where('active', true)
            ->orderBy('position')
            ->get(['id', 'name', 'address', 'phone', 'whatsapp', 'latitude', 'longitude']);

        $out = $branches->map(function (Branch $b) use ($haveCust, $custLat, $custLng) {
            $lat = $b->latitude !== null ? (float) $b->latitude : null;
            $lng = $b->longitude !== null ? (float) $b->longitude : null;

            $distance = null;
            if ($haveCust && $lat !== null && $lng !== null) {
                $distance = round(MapsManager::haversineKm($custLat, $custLng, $lat, $lng), 3);
            }

            return [
                'id' => $b->id,
                'name' => $b->name,
                'address' => $b->address,
                'phone' => $b->phone,
                'whatsapp' => $b->whatsapp,
                'latitude' => $lat,
                'longitude' => $lng,
                'distance_km' => $distance,
            ];
        })->values();

        // Nearest-first when a customer position is supplied; branches without
        // coords (null distance) sink to the bottom.
        if ($haveCust) {
            $out = $out->sortBy(function ($b) {
                return $b['distance_km'] ?? INF;
            })->values();
        }

        return response()->json([
            'provider' => $this->maps->configuredProviderKey(),
            'attribution' => $this->maps->attributionEnabled() ? $this->maps->provider()->attribution() : null,
            'branches' => $out,
        ]);
    }
}
