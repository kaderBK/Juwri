<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use Illuminate\Http\Request;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\ViewModel\RetailFacets;
use Juwri\Modules\Verticals\Contracts\VehicleFitments;

/**
 * P3-08 / P3-06b phase B (kit/api `GET /catalog`): search, facet filters, stock and price filters, sort and an opaque
 * cursor over the store's catalogue.
 *
 *  - `q`: Scout (J27-B, juwri's database engine — LIKE plus the items name full-text index on MySQL / PostgreSQL),
 *    strictly inside the token's store; the relevance order is Scout's (exact substring matches first).
 *  - `filter[<facet>]=a,b`: OR inside a facet, AND across facets; an unknown facet or value matches nothing.
 *  - `vehicle` (P3-08b, aisle §13): `year|make|model` (optionally `|submodel|engine`) or `vin:<VIN>`; on a store that
 *    sells by vehicle only the products that fit it (a confirmed fitment) are kept, and `fits_vehicle` is on every card.
 *    A key that is not valid is 422; a store without fitments ignores it.
 *  - `in_stock=true`, `price_min` / `price_max` (minor units, on the price a card shows), `category`.
 *  - `sort`: relevance (with q; else position) | position | price_asc | price_desc | newest.
 *  - `cursor` + `per_page` (1–100, default 48): the cursor is an opaque, signed offset tied to the query (a cursor
 *    of another query or a tampered one is 422). A food store's catalogue stays one page unless the client pages
 *    or it holds more than `perf.storefront_catalog_auto_page` items (300 by default; then the first page comes with `next_cursor`).
 *
 * The answer keeps the View Model shape: categories with the page's item ids, the page's cards, `total` = every
 * match, `facets` (goods stores) with counts and the selected values, and `next_cursor`.
 */
final class StorefrontCatalogQuery
{
    public const SORTS = ['relevance', 'position', 'price_asc', 'price_desc', 'newest'];

    /**
     * @param  array{catalog: array<string, mixed>, index: array<string, array<string, mixed>>, facets: RetailFacets, food: bool, vehicle?: array<string, mixed>|null}  $blocks
     * @return array{catalog: array<string, mixed>, next_cursor: string|null}
     *
     * @throws StorefrontApiException
     */
    public function run(StorefrontApiContext $ctx, Request $request, array $blocks): array
    {
        $params = $this->params($request, (int) $ctx->store->id);
        $catalog = $blocks['catalog'];
        $index = $blocks['index'];

        // q: Scout over the store's items; the order is the relevance order.
        $relevance = null;
        if ($params['q'] !== '') {
            $relevance = Menu::query()->where('store_id', $ctx->store->id)->whereSearch($params['q'])
                ->pluck('public_id')->map(fn ($id) => (string) $id)->values()->all();
            $relevance = array_values(array_filter($relevance, fn (string $id) => isset($index[$id])));
        }

        // vehicle: only the products that confirmed to fit (null = no fitments entered: not a confirmed fit).
        $fitsOnly = false;
        if ($params['vehicle'] !== null && app(VehicleFitments::class)->enabled($ctx->store)) {
            if (($blocks['vehicle'] ?? null) === null) {
                throw StorefrontApiException::validation(['vehicle' => [___('This vehicle is not valid. Use year|make|model or vin:<VIN>.')]]);
            }
            $fitsOnly = true;
        }

        $base = $this->matching($index, $params, $relevance, null, $fitsOnly);
        $matches = $this->sorted($base, $index, $params['sort'], $relevance);

        // P4-16: a food catalogue stays one page unless the client pages or it is very large (config perf.storefront_catalog_auto_page:
        // 300 items by default, about 150 KB); then the first `per_page` (48) come with `next_cursor`, like a goods store
        $paged = ! $blocks['food'] || $request->has('per_page') || $request->has('cursor')
            || count($matches) > max(1, (int) config('perf.storefront_catalog_auto_page', 300));
        $offset = $params['offset'];
        $page = $paged ? array_slice($matches, $offset, $params['per_page']) : $matches;
        $next = $paged && $offset + count($page) < count($matches) ? $this->cursor($params, $offset + count($page), (int) $ctx->store->id) : null;

        $pageIds = array_flip($page);
        $categories = [];
        foreach ($catalog['categories'] as $category) {
            $inPage = array_values(array_filter($page, fn (string $id) => ($index[$id]['category'] ?? null) === $category['id']));
            $all = array_values(array_filter($matches, fn (string $id) => ($index[$id]['category'] ?? null) === $category['id']));
            if ($all === []) {
                continue; // §2.4: an empty category is hidden
            }
            $category['item_ids'] = $inPage;
            $category['count'] = count($all);
            $categories[] = $category;
        }
        $items = [];
        foreach ($page as $id) {
            $items[$id] = $catalog['items'][$id];
        }
        $out = ['categories' => $categories, 'items' => $items, 'total' => count($matches)];
        if (! $blocks['food']) {
            $out['facets'] = $blocks['facets']->facets($index, $params['filter'], fn (string $facet) => $this->matching($index, $params, $relevance, $facet, $fitsOnly));
        }

        return ['catalog' => $out, 'next_cursor' => $next];
    }

    /**
     * @param  array<string, array<string, mixed>>  $index
     * @param  array<string, mixed>  $params
     * @param  list<string>|null  $relevance
     * @return list<string> item ids that match every filter (but `$skipFacet`'s), in storefront order
     */
    private function matching(array $index, array $params, ?array $relevance, ?string $skipFacet, bool $fitsOnly = false): array
    {
        $allowed = $relevance !== null ? array_flip($relevance) : null;
        $out = [];
        foreach ($index as $id => $entry) {
            $id = (string) $id;
            if ($allowed !== null && ! isset($allowed[$id])) {
                continue;
            }
            if ($params['category'] !== null && $entry['category'] !== $params['category']) {
                continue;
            }
            if ($fitsOnly && ($entry['fits'] ?? null) !== true) {
                continue;
            }
            if ($params['in_stock'] && ! $entry['in_stock']) {
                continue;
            }
            if ($params['price_min'] !== null && $entry['price'] < $params['price_min']) {
                continue;
            }
            if ($params['price_max'] !== null && $entry['price'] > $params['price_max']) {
                continue;
            }
            foreach ($params['filter'] as $facet => $values) {
                if ($facet === $skipFacet) {
                    continue;
                }
                if (array_intersect($values, $entry['values'][$facet] ?? []) === []) {
                    continue 2;
                }
            }
            $out[] = $id;
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, array<string, mixed>>  $index
     * @param  list<string>|null  $relevance
     * @return list<string>
     */
    private function sorted(array $ids, array $index, string $sort, ?array $relevance): array
    {
        if ($sort === 'relevance' && $relevance !== null) {
            $rank = array_flip($relevance);
            usort($ids, fn ($a, $b) => $rank[$a] <=> $rank[$b]);

            return $ids;
        }
        $key = match ($sort) {
            'price_asc' => fn (string $id) => [$index[$id]['price'], $index[$id]['position']],
            'price_desc' => fn (string $id) => [-$index[$id]['price'], $index[$id]['position']],
            'newest' => fn (string $id) => [-$index[$id]['newest'], $index[$id]['position']],
            default => fn (string $id) => [$index[$id]['position']],
        };
        usort($ids, fn ($a, $b) => $key($a) <=> $key($b));

        return $ids;
    }

    /**
     * @return array{q: string, vehicle: string|null, category: string|null, filter: array<string, list<string>>, in_stock: bool, price_min: int|null, price_max: int|null, sort: string, per_page: int, offset: int}
     *
     * @throws StorefrontApiException
     */
    private function params(Request $request, int $storeId): array
    {
        $errors = [];
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) > 100) {
            $errors['q'] = [___('The search may be at most 100 characters.')];
        }
        $filter = [];
        $raw = $request->query('filter', []);
        if (! is_array($raw)) {
            $errors['filter'] = [___('The filters are not valid.')];
            $raw = [];
        }
        foreach ($raw as $facet => $values) {
            if (! is_string($facet) || ! is_string($values) || strlen($facet) > 64 || strlen($values) > 500) {
                $errors['filter'] = [___('The filters are not valid.')];

                continue;
            }
            $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $values)), fn ($v) => $v !== '')));
            if ($list !== []) {
                $filter[$facet] = $list;
            }
        }
        $inStock = $request->query('in_stock');
        $int = function (string $name) use ($request, &$errors): ?int {
            $value = $request->query($name);
            if ($value === null || $value === '') {
                return null;
            }
            if (! is_string($value) || preg_match('/^\d{1,15}$/', $value) !== 1) {
                $errors[$name] = [___('This must be a whole number of minor units.')];

                return null;
            }

            return (int) $value;
        };
        $priceMin = $int('price_min');
        $priceMax = $int('price_max');
        $sort = (string) $request->query('sort', $q !== '' ? 'relevance' : 'position');
        if (! in_array($sort, self::SORTS, true)) {
            $errors['sort'] = [___('This sort order is not available.')];
        }
        $perPage = $request->query('per_page', '48');
        if (! is_string($perPage) || preg_match('/^\d{1,3}$/', $perPage) !== 1 || (int) $perPage < 1 || (int) $perPage > 100) {
            $errors['per_page'] = [___('per_page must be between 1 and 100.')];
            $perPage = '48';
        }
        $vehicle = $request->query('vehicle');
        if ($vehicle !== null && $vehicle !== '' && (! is_string($vehicle) || mb_strlen($vehicle) > 160)) {
            $errors['vehicle'] = [___('This vehicle is not valid. Use year|make|model or vin:<VIN>.')];
            $vehicle = null;
        }
        $category = $request->query('category');
        $params = [
            'q' => $q,
            'vehicle' => is_string($vehicle) && trim($vehicle) !== '' ? trim($vehicle) : null,
            'category' => is_string($category) && $category !== '' ? $category : null,
            'filter' => $filter,
            'in_stock' => in_array($inStock, ['1', 'true', true], true),
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'sort' => $sort,
            'per_page' => (int) $perPage,
            'offset' => 0,
        ];
        $cursor = $request->query('cursor');
        if ($cursor !== null && $cursor !== '') {
            $offset = is_string($cursor) ? $this->offset($params, $cursor, $storeId) : null;
            if ($offset === null) {
                $errors['cursor'] = [___('This cursor is not valid for this query.')];
            } else {
                $params['offset'] = $offset;
            }
        }
        if ($errors !== []) {
            throw StorefrontApiException::validation($errors);
        }

        return $params;
    }

    /** @param  array<string, mixed>  $params */
    private function cursor(array $params, int $offset, int $storeId): string
    {
        $payload = $offset.'.'.$this->signature($params, $offset, $storeId);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /** @param  array<string, mixed>  $params */
    private function offset(array $params, string $cursor, int $storeId): ?int
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || preg_match('/^(\d{1,7})\.([0-9a-f]{32})$/', $decoded, $m) !== 1) {
            return null;
        }

        return hash_equals($this->signature($params, (int) $m[1], $storeId), $m[2]) ? (int) $m[1] : null;
    }

    /** @param  array<string, mixed>  $params */
    private function signature(array $params, int $offset, int $storeId): string
    {
        unset($params['offset']);
        ksort($params['filter']);

        return substr(hash_hmac('sha256', json_encode([$params, $offset]).'|'.$storeId, (string) config('app.key')), 0, 32);
    }
}
