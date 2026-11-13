<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering;

/**
 * P5-20 — Google Ordering Redirect feed field rules (offline only: no database, no network).
 *
 * One bad entry must never poison the whole drop: an entry that breaks a required rule is
 * DROPPED and reported; an optional field that breaks a rule (telephone, image url, place id)
 * is removed and reported. Entries that refer to a merchant not in the same run are dropped
 * too, so a feed never points at nothing.
 *
 * Money is integer MINOR units + an ISO currency, matching the storefront (MoneyFormat) — a
 * float, a string amount or a missing currency is a drop.
 *
 * Feed order matters: sanitize the merchants feed first, then services, then menu (the command
 * does), so the cross-references resolve.
 */
final class GoogleOrderingFeedValidator
{
    /** A Google Maps place ID (e.g. ChIJN1t_tDeuEmsRUsoyG83frY4). */
    public const PLACE_ID = '/^[A-Za-z0-9_-]{10,255}$/D';

    private const ID = '/^[A-Za-z0-9._-]{1,64}$/';

    private const CURRENCY = '/^[A-Z]{3}$/';

    public function __construct(private readonly bool $allowHttp = false) {}

    /** @var array<string, true> */
    private array $merchants = [];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    public function sanitize(string $filename, array $payload): array
    {
        return match ($filename) {
            'merchant_feed.json' => $this->merchants($payload),
            'service_feed.json' => $this->services($payload),
            'menu_feed.json' => $this->menus($payload),
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
            if (preg_match(self::ID, $id) !== 1) {
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
            if ($tel !== '' && preg_match('/^\+?[0-9 ()\-.]{5,20}$/', $tel) !== 1) {
                $problems[] = "{$ref}: telephone removed (not a phone number)";
                $tel = '';
            }
            $url = trim((string) ($m['url'] ?? ''));
            if ($url !== '' && ! $this->isLink($url)) {
                $problems[] = "{$ref}: url removed (must be a stable https address)";
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
            $m['category'] = trim((string) ($m['category'] ?? '')) !== '' ? (string) $m['category'] : 'restaurant';
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
        $seen = [];
        foreach ((array) ($payload['service'] ?? []) as $s) {
            $mid = (string) ($s['merchant_id'] ?? '');
            $sid = (string) ($s['service_id'] ?? '');
            $ref = "service {$mid}/{$sid}";
            if (! isset($this->merchants[$mid])) {
                $problems[] = "{$ref}: its merchant is not in the merchants feed";

                continue;
            }
            if (preg_match(self::ID, $sid) !== 1) {
                $problems[] = "{$ref}: service_id must be 1-64 letters, digits, dot, dash or underscore";

                continue;
            }
            if (isset($seen[$mid.'|'.$sid])) {
                $problems[] = "{$ref}: duplicate service_id for the merchant";

                continue;
            }
            $links = [];
            foreach ((array) ($s['action_links'] ?? []) as $link) {
                $url = trim((string) (is_array($link) ? ($link['url'] ?? '') : ''));
                if (! $this->isLink($url)) {
                    $problems[] = "{$ref}: action link removed (must be a stable https address)";

                    continue;
                }
                $links[] = ['url' => $url, 'locale' => (string) (is_array($link) ? ($link['locale'] ?? '') : '')];
            }
            if ($links === []) {
                $problems[] = "{$ref}: no action link → the service is dropped (Google has nowhere to send the diner)";

                continue;
            }
            if (isset($s['eta_min_minutes']) && (! is_int($s['eta_min_minutes']) || $s['eta_min_minutes'] < 0)) {
                $problems[] = "{$ref}: eta_min_minutes must be a non-negative integer";
                unset($s['eta_min_minutes']);
            }
            foreach (['delivery_fee', 'minimum_order'] as $moneyField) {
                if (! isset($s[$moneyField])) {
                    continue;
                }
                if (! $this->isMoney($s[$moneyField])) {
                    $problems[] = "{$ref}: {$moneyField} removed (needs an integer minor amount and an ISO currency)";
                    unset($s[$moneyField]);
                }
            }
            if (isset($s['currency']) && preg_match(self::CURRENCY, (string) $s['currency']) !== 1) {
                $problems[] = "{$ref}: currency removed (must be an ISO 4217 code)";
                unset($s['currency']);
            }
            $s['action_links'] = $links;
            $seen[$mid.'|'.$sid] = true;
            $keep[] = $s;
        }
        $payload['service'] = $keep;

        return ['payload' => $payload, 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    private function menus(array $payload): array
    {
        $problems = [];
        $keep = [];
        foreach ((array) ($payload['menu'] ?? []) as $menu) {
            $mid = (string) ($menu['merchant_id'] ?? '');
            $ref = "menu {$mid}";
            if (! isset($this->merchants[$mid])) {
                $problems[] = "{$ref}: its merchant is not in the merchants feed";

                continue;
            }
            $sections = [];
            foreach ((array) ($menu['section'] ?? []) as $section) {
                $sname = trim((string) ($section['name'] ?? ''));
                if ($sname === '') {
                    $problems[] = "{$ref}: a section without a name is dropped";

                    continue;
                }
                $items = [];
                foreach ((array) ($section['item'] ?? []) as $item) {
                    $iname = trim((string) ($item['name'] ?? ''));
                    if ($iname === '') {
                        $problems[] = "{$ref}/{$sname}: an item without a name is dropped";

                        continue;
                    }
                    if (! $this->isMoney($item['price'] ?? null)) {
                        $problems[] = "{$ref}/{$sname}: item '{$iname}' dropped (needs an integer minor price and an ISO currency)";

                        continue;
                    }
                    if (isset($item['image'])) {
                        $image = trim((string) $item['image']);
                        if ($image === '' || ! $this->isLink($image)) {
                            $problems[] = "{$ref}/{$sname}: item '{$iname}' image removed (must be a stable https address)";
                            unset($item['image']);
                        }
                    }
                    if (isset($item['dietary_tags'])) {
                        $tags = $item['dietary_tags'];
                        if (! is_array($tags) || $tags !== array_values(array_filter($tags, 'is_string'))) {
                            $problems[] = "{$ref}/{$sname}: item '{$iname}' dietary_tags removed (must be a list of strings)";
                            unset($item['dietary_tags']);
                        }
                    }
                    $item['name'] = $iname;
                    $item['available'] = (bool) ($item['available'] ?? true);
                    $items[] = $item;
                }
                $sections[] = ['name' => $sname, 'item' => $items];
            }
            $menu['section'] = $sections;
            $keep[] = $menu;
        }
        $payload['menu'] = $keep;

        return ['payload' => $payload, 'problems' => $problems];
    }

    /** A stable link Google will follow: https (http allowed only in local/testing). */
    private function isLink(string $url): bool
    {
        if (mb_strlen($url) > 2000) {
            return false;
        }

        return preg_match($this->allowHttp ? '#^https?://[^\s/]+#i' : '#^https://[^\s/]+#i', $url) === 1;
    }

    /** Integer minor amount + an ISO currency, matching the storefront money shape. */
    private function isMoney(mixed $money): bool
    {
        if (! is_array($money)) {
            return false;
        }
        $minor = $money['minor'] ?? null;
        $currency = $money['currency'] ?? null;

        return is_int($minor) && $minor >= 0 && is_string($currency) && preg_match(self::CURRENCY, $currency) === 1;
    }
}
