<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Feeds;

/**
 * Reserve-with-Google feed field rules (the documented Merchants / Services / Availability fields we send).
 *
 * One bad entry must never poison the whole drop: an entry that breaks a required rule is DROPPED and reported;
 * an optional field that breaks a rule (telephone, url) is removed and reported. Entries that refer to a merchant
 * (or merchant + service) that is not in the same run are dropped too, so a feed never points at nothing.
 *
 * Feed order matters: sanitize the merchants feed first, then services, then availability (the command does).
 * Pure: no database, no network.
 */
final class RwgFeedValidator
{
    private const PREPAYMENT = ['NOT_SUPPORTED', 'REQUIRED', 'OPTIONAL'];

    /** A Google Maps place ID (e.g. ChIJN1t_tDeuEmsRUsoyG83frY4). */
    public const PLACE_ID = '/^[A-Za-z0-9_-]{10,255}$/D';

    public function __construct(private readonly bool $allowHttp = false) {}

    /** @var array<string, true> */
    private array $merchants = [];

    /** @var array<string, true> merchant_id|service_id */
    private array $services = [];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    public function sanitize(string $filename, array $payload): array
    {
        return match ($filename) {
            'merchant_feed.json' => $this->merchants($payload),
            'service_feed.json' => $this->services($payload),
            'availability_feed.json' => $this->availability($payload),
            default => ['payload' => $payload, 'problems' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    private function merchants(array $payload): array
    {
        $problems = [];
        $keep = [];
        foreach ((array) ($payload['merchant'] ?? []) as $m) {
            $id = (string) ($m['merchant_id'] ?? '');
            $ref = "merchant {$id}";
            if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id)) {
                $problems[] = "{$ref}: merchant_id must be 1-64 letters, digits, dot, dash or underscore";

                continue;
            }
            if (isset($this->merchants[$id])) {
                $problems[] = "{$ref}: duplicate merchant_id";

                continue;
            }
            $name = trim((string) ($m['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) {
                $problems[] = "{$ref}: name is required (1-255 characters)";

                continue;
            }
            $lat = $m['geo']['latitude'] ?? null;
            $lng = $m['geo']['longitude'] ?? null;
            if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180 || ((float) $lat === 0.0 && (float) $lng === 0.0)) {
                $problems[] = "{$ref}: geo needs a real latitude (-90..90) and longitude (-180..180)";

                continue;
            }
            $tel = trim((string) ($m['telephone'] ?? ''));
            if ($tel !== '' && ! preg_match('/^\+?[0-9 ()\-.]{5,20}$/', $tel)) {
                $problems[] = "{$ref}: telephone removed (not a phone number)";
                $tel = '';
            }
            $url = trim((string) ($m['url'] ?? ''));
            if ($url !== '' && (! preg_match($this->allowHttp ? '#^https?://[^\s/]+#i' : '#^https://[^\s/]+#i', $url) || mb_strlen($url) > 2000)) {
                $problems[] = "{$ref}: url removed (must be an https address)";
                $url = '';
            }
            $placeId = $m['matching_hints']['place_id'] ?? null;
            if ($placeId !== null && (! is_string($placeId) || preg_match(self::PLACE_ID, $placeId) !== 1)) {
                $problems[] = "{$ref}: matching_hints.place_id removed (not a Google place ID)";
                unset($m['matching_hints']);
            }
            $m['name'] = $name;
            $m['telephone'] = $tel;
            $m['url'] = $url;
            $this->merchants[$id] = true;
            $keep[] = $m;
        }
        $payload['merchant'] = $keep;

        return ['payload' => $payload, 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    private function services(array $payload): array
    {
        $problems = [];
        $keep = [];
        foreach ((array) ($payload['service'] ?? []) as $s) {
            $mid = (string) ($s['merchant_id'] ?? '');
            $sid = (string) ($s['service_id'] ?? '');
            $ref = "service {$mid}/{$sid}";
            if (! isset($this->merchants[$mid])) {
                $problems[] = "{$ref}: its merchant is not in the merchants feed";

                continue;
            }
            if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $sid)) {
                $problems[] = "{$ref}: service_id must be 1-64 letters, digits, dot, dash or underscore";

                continue;
            }
            if (isset($this->services[$mid.'|'.$sid])) {
                $problems[] = "{$ref}: duplicate service_id for the merchant";

                continue;
            }
            if (isset($s['prepayment_type']) && ! in_array($s['prepayment_type'], self::PREPAYMENT, true)) {
                $problems[] = "{$ref}: prepayment_type must be one of ".implode(', ', self::PREPAYMENT);

                continue;
            }
            if (isset($s['waitlist_rules'])) {
                $min = $s['waitlist_rules']['min_party_size'] ?? null;
                $max = $s['waitlist_rules']['max_party_size'] ?? null;
                if (! is_int($min) || ! is_int($max) || $min < 1 || $max < $min || $max > 100) {
                    $problems[] = "{$ref}: waitlist_rules need 1 <= min_party_size <= max_party_size <= 100";

                    continue;
                }
            } elseif (! isset($s['type']) && ! isset($s['localized_service_name'])) {
                $problems[] = "{$ref}: a service needs a type or a localized_service_name";

                continue;
            }
            $this->services[$mid.'|'.$sid] = true;
            $keep[] = $s;
        }
        $payload['service'] = $keep;

        return ['payload' => $payload, 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    private function availability(array $payload): array
    {
        $problems = [];
        $seen = [];
        $blocks = [];
        foreach ((array) ($payload['service_availability'] ?? []) as $block) {
            $keep = [];
            foreach ((array) ($block['availability'] ?? []) as $a) {
                $mid = (string) ($a['merchant_id'] ?? '');
                $sid = (string) ($a['service_id'] ?? '');
                $start = $a['start_sec'] ?? null;
                $ref = "availability {$mid}/{$sid}@".(is_scalar($start) ? $start : '?');
                if (! isset($this->services[$mid.'|'.$sid])) {
                    $problems[] = "{$ref}: its service is not in the services feed";

                    continue;
                }
                $dur = $a['duration_sec'] ?? null;
                $total = $a['spots_total'] ?? null;
                $open = $a['spots_open'] ?? null;
                if (! is_int($start) || $start <= 0 || ! is_int($dur) || $dur <= 0 || $dur > 86400) {
                    $problems[] = "{$ref}: start_sec must be a positive Unix time and duration_sec 1..86400";

                    continue;
                }
                if (! is_int($total) || ! is_int($open) || $total < 0 || $open < 0 || $open > $total) {
                    $problems[] = "{$ref}: need 0 <= spots_open <= spots_total";

                    continue;
                }
                $key = $mid.'|'.$sid.'|'.$start;
                if (isset($seen[$key])) {
                    $problems[] = "{$ref}: duplicate slot";

                    continue;
                }
                $seen[$key] = true;
                $keep[] = $a;
            }
            $block['availability'] = $keep;
            $blocks[] = $block;
        }
        $payload['service_availability'] = $blocks;

        return ['payload' => $payload, 'problems' => $problems];
    }
}
