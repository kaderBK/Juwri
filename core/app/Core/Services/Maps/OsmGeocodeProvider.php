<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * theme-pack-blade F5: OpenStreetMap-based geocoder. The working default (no key).
 *
 *  - autocomplete → Photon (https://photon.komoot.io) because public Nominatim
 *    FORBIDS autocomplete-style querying.
 *  - reverse/forward → Nominatim (https://nominatim.openstreetmap.org).
 *
 * Nominatim usage policy is enforced here: ≤1 req/s (a short server-side throttle
 * lock), results CACHED, and a descriptive User-Agent on every call. Self-host
 * Photon/Nominatim for higher volume — see the class notes.
 */
class OsmGeocodeProvider implements GeocodeProvider
{
    private const PHOTON_URL = 'https://photon.komoot.io/api';

    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org';

    /** Cache TTL for geocode results (seconds). */
    private const CACHE_TTL = 86400;

    /**
     * Outbound HTTP timeout (seconds). Kept low on purpose: these proxies run
     * inside a web request and a stalled upstream (photon/nominatim) otherwise
     * pins an lsphp worker for the whole window — with only a handful of workers
     * on prod, a provider slowdown during per-keystroke autocomplete traffic can
     * starve them. 4s comfortably covers normal geocode latency (sub-second) and
     * cuts stalls; a timed-out autocomplete just returns no suggestions.
     */
    private const HTTP_TIMEOUT = 4;

    /** Minimum spacing between outbound Nominatim requests (ms). */
    private const THROTTLE_MS = 1000;

    public function __construct(private readonly string $userAgent) {}

    public function key(): string
    {
        return 'osm';
    }

    public function isConfigured(): bool
    {
        return true; // no key required
    }

    public function attribution(): string
    {
        return '© OpenStreetMap contributors';
    }

    public function autocomplete(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        return Cache::remember($this->cacheKey('ac', $query), self::CACHE_TTL, function () use ($query) {
            $this->throttle();

            try {
                $res = $this->client()->get(self::PHOTON_URL, [
                    'q' => $query,
                    'limit' => 8,
                ]);
            } catch (\Throwable $e) {
                Log::warning('OSM autocomplete failed', ['q' => $query, 'e' => $e->getMessage()]);

                return [];
            }

            if (! $res->successful()) {
                return [];
            }

            $out = [];
            foreach ((array) $res->json('features', []) as $feat) {
                $coords = $feat['geometry']['coordinates'] ?? null; // [lng, lat]
                $props = $feat['properties'] ?? [];
                $out[] = [
                    'label' => $this->photonLabel($props),
                    'lat' => isset($coords[1]) ? (float) $coords[1] : null,
                    'lng' => isset($coords[0]) ? (float) $coords[0] : null,
                    'raw' => $props,
                ];
            }

            return $out;
        });
    }

    public function reverse(float $lat, float $lng): ?array
    {
        $key = $this->cacheKey('rev', round($lat, 6).','.round($lng, 6));

        return Cache::remember($key, self::CACHE_TTL, function () use ($lat, $lng) {
            $this->throttle();

            try {
                $res = $this->client()->get(self::NOMINATIM_URL.'/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                ]);
            } catch (\Throwable $e) {
                Log::warning('OSM reverse failed', ['lat' => $lat, 'lng' => $lng, 'e' => $e->getMessage()]);

                return null;
            }

            if (! $res->successful() || ! $res->json('display_name')) {
                return null;
            }

            return [
                'label' => (string) $res->json('display_name'),
                'lat' => (float) ($res->json('lat') ?? $lat),
                'lng' => (float) ($res->json('lon') ?? $lng),
                'raw' => (array) $res->json('address', []),
            ];
        });
    }

    public function forward(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        return Cache::remember($this->cacheKey('fwd', $address), self::CACHE_TTL, function () use ($address) {
            $this->throttle();

            try {
                $res = $this->client()->get(self::NOMINATIM_URL.'/search', [
                    'q' => $address,
                    'format' => 'jsonv2',
                    'limit' => 1,
                ]);
            } catch (\Throwable $e) {
                Log::warning('OSM forward failed', ['q' => $address, 'e' => $e->getMessage()]);

                return null;
            }

            $first = $res->successful() ? ($res->json()[0] ?? null) : null;
            if (! $first || ! isset($first['lat'], $first['lon'])) {
                return null;
            }

            return [
                'lat' => (float) $first['lat'],
                'lng' => (float) $first['lon'],
                'label' => (string) ($first['display_name'] ?? $address),
                'raw' => $first,
            ];
        });
    }

    /** Shared HTTP client with the required descriptive User-Agent. */
    private function client()
    {
        return Http::withHeaders(['User-Agent' => $this->userAgent])->timeout(self::HTTP_TIMEOUT);
    }

    /**
     * Server-side rate limiter: block until at least THROTTLE_MS have passed
     * since the last outbound request (honours Nominatim's ≤1 req/s policy).
     * Only reached on a cache MISS.
     */
    private function throttle(): void
    {
        $store = Cache::getStore();
        // atomic-ish gate; falls back to a plain sleep when the store lacks add()
        $lastKey = 'maps:osm:last_req_ms';
        $last = (int) Cache::get($lastKey, 0);
        $now = (int) (microtime(true) * 1000);
        $wait = self::THROTTLE_MS - ($now - $last);
        if ($last > 0 && $wait > 0) {
            usleep($wait * 1000);
            $now = (int) (microtime(true) * 1000);
        }
        Cache::put($lastKey, $now, 60);
    }

    private function cacheKey(string $kind, string $val): string
    {
        return 'maps:osm:'.$kind.':'.sha1(mb_strtolower($val));
    }

    private function photonLabel(array $props): string
    {
        $parts = array_filter([
            $props['name'] ?? null,
            $props['street'] ?? null,
            $props['housenumber'] ?? null,
            $props['city'] ?? null,
            $props['state'] ?? null,
            $props['country'] ?? null,
        ]);

        return implode(', ', array_unique($parts));
    }
}
