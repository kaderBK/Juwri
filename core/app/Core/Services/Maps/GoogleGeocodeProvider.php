<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * theme-pack-blade F5: Google Places Autocomplete + Geocoding provider.
 *
 * ⚠ BLOCKED until the owner supplies a Maps API key. Without a key the provider
 * is INERT: isConfigured() is false and every call throws so MapsManager never
 * selects it (it falls back to OSM). Results are cached; all calls are server-side.
 */
class GoogleGeocodeProvider implements GeocodeProvider
{
    private const AUTOCOMPLETE_URL = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';

    private const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const CACHE_TTL = 86400;

    /**
     * Outbound HTTP timeout (seconds). Kept low on purpose: these proxies run
     * inside a web request and a stalled upstream otherwise pins an lsphp worker
     * for the whole window — with only a handful of workers on prod, a provider
     * slowdown during per-keystroke autocomplete traffic can starve them. 4s
     * covers normal geocode latency and cuts stalls; a timed-out autocomplete
     * just returns no suggestions.
     */
    private const HTTP_TIMEOUT = 4;

    public function __construct(private readonly ?string $apiKey) {}

    public function key(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    public function attribution(): string
    {
        return 'Powered by Google';
    }

    public function autocomplete(string $query): array
    {
        $this->guard();
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        return Cache::remember($this->cacheKey('ac', $query), self::CACHE_TTL, function () use ($query) {
            try {
                $res = Http::timeout(self::HTTP_TIMEOUT)->get(self::AUTOCOMPLETE_URL, [
                    'input' => $query,
                    'key' => $this->apiKey,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Google autocomplete failed', ['e' => $e->getMessage()]);

                return [];
            }

            $out = [];
            foreach ((array) $res->json('predictions', []) as $pred) {
                $out[] = [
                    'label' => (string) ($pred['description'] ?? ''),
                    'lat' => null, // Google autocomplete returns place_id, not coords
                    'lng' => null,
                    'raw' => $pred,
                ];
            }

            return $out;
        });
    }

    public function reverse(float $lat, float $lng): ?array
    {
        $this->guard();
        $key = $this->cacheKey('rev', round($lat, 6).','.round($lng, 6));

        return Cache::remember($key, self::CACHE_TTL, function () use ($lat, $lng) {
            $res = Http::timeout(self::HTTP_TIMEOUT)->get(self::GEOCODE_URL, [
                'latlng' => $lat.','.$lng,
                'key' => $this->apiKey,
            ]);
            $first = $res->json('results.0');
            if (! $first) {
                return null;
            }

            return [
                'label' => (string) ($first['formatted_address'] ?? ''),
                'lat' => (float) ($first['geometry']['location']['lat'] ?? $lat),
                'lng' => (float) ($first['geometry']['location']['lng'] ?? $lng),
                'raw' => $first,
            ];
        });
    }

    public function forward(string $address): ?array
    {
        $this->guard();
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        return Cache::remember($this->cacheKey('fwd', $address), self::CACHE_TTL, function () use ($address) {
            $res = Http::timeout(self::HTTP_TIMEOUT)->get(self::GEOCODE_URL, [
                'address' => $address,
                'key' => $this->apiKey,
            ]);
            $first = $res->json('results.0');
            if (! $first || ! isset($first['geometry']['location'])) {
                return null;
            }

            $loc = $first['geometry']['location'];

            return [
                'lat' => (float) $loc['lat'],
                'lng' => (float) $loc['lng'],
                'label' => (string) ($first['formatted_address'] ?? $address),
                'raw' => $first,
            ];
        });
    }

    /** Refuse to make a call without a key — keeps the provider inert. */
    private function guard(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('GoogleGeocodeProvider requires a Maps API key (not configured).');
        }
    }

    private function cacheKey(string $kind, string $val): string
    {
        return 'maps:google:'.$kind.':'.sha1(mb_strtolower($val));
    }
}
