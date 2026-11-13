<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use App\Models\Customer;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Api\StorefrontCatalogQuery;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;

/**
 * P3-08 (aisle §6): the store page's catalogue of a goods store, filtered, sorted and paged on the server by the same
 * engine as the headless `GET /catalog` ({@see StorefrontCatalogQuery}: Scout search, facet filters, stock and price
 * filters, sort, signed cursor) — so the page URL is the query (shareable, the back button works) and a theme never
 * filters a large catalogue in the browser.
 *
 * The page's query is the shopper's, so it is forgiving where the API is strict: facet filters arrive as `filter[<facet>][]`
 * (checkboxes) or `filter[<facet>]=a,b`; the price inputs are in the store currency's major units; an unknown sort or a
 * stale cursor is ignored (the first page answers) instead of an error.
 */
final class RetailListing
{
    public const SORTS = ['position', 'price_asc', 'price_desc', 'newest'];

    public function __construct(
        private readonly ViewModelBuilder $builder,
        private readonly StorefrontCatalogQuery $query,
    ) {}

    /**
     * @return array{catalog: array<string, mixed>, listing: array<string, mixed>}|null null when the catalogue cannot be listed
     */
    public function run(Store $store, ViewModelRequest $request, Request $http, ?Customer $customer): ?array
    {
        $blocks = $this->builder->apiBlocks($request, 'catalog_index');
        if ($blocks === null) {
            return null;
        }
        $currency = strtoupper(store_currency((int) $store->id));
        $exponent = (int) currency_decimals($currency);
        $params = $this->normalise($http, $currency, $exponent);
        $ctx = StorefrontApiContext::forWeb($store, $http, $customer);

        try {
            $result = $this->query->run($ctx, $http->duplicate($params), $blocks);
        } catch (StorefrontApiException) {
            unset($params['cursor']);
            $result = $this->query->run($ctx, $http->duplicate(array_diff_key($params, ['filter' => 1, 'price_min' => 1, 'price_max' => 1, 'q' => 1, 'category' => 1])), $blocks);
            $params = array_diff_key($params, ['filter' => 1, 'price_min' => 1, 'price_max' => 1, 'q' => 1, 'category' => 1]);
        }

        $catalog = $result['catalog'];
        $next = $result['next_cursor'];
        $catalog['next_page_url'] = $next !== null ? $this->url($store, $params, $next) : null;

        $filters = 0;
        foreach ($catalog['facets'] ?? [] as $facet) {
            foreach ($facet['options'] as $option) {
                $filters += ! empty($option['selected']) ? 1 : 0;
            }
        }
        $filters += (isset($params['price_min']) || isset($params['price_max'])) ? 1 : 0;
        $filters += ! empty($params['in_stock']) ? 1 : 0;

        return [
            'catalog' => $catalog,
            'listing' => [
                'q' => (string) ($params['q'] ?? ''),
                'sort' => (string) ($params['sort'] ?? 'position'),
                'category' => $params['category'] ?? null,
                'in_stock' => ! empty($params['in_stock']),
                'price_min' => isset($params['price_min']) ? $this->major((int) $params['price_min'], $exponent) : '',
                'price_max' => isset($params['price_max']) ? $this->major((int) $params['price_max'], $exponent) : '',
                'filters' => $filters,
                'cursor' => isset($params['cursor']) ? (string) $params['cursor'] : null,
                'offset' => isset($params['cursor']) ? $this->offsetOf((string) $params['cursor']) : 0,
                'filtered' => $filters > 0 || ! empty($params['q']) || ! empty($params['category']),
                'query' => $this->queryString($params, false),
            ],
        ];
    }

    /**
     * @return array<string, mixed> the query the engine reads
     */
    private function normalise(Request $http, string $currency, int $exponent): array
    {
        $out = [];
        $q = trim((string) $http->query('q', ''));
        if ($q !== '') {
            $out['q'] = mb_substr($q, 0, 100);
        }
        $category = $http->query('category');
        if (is_string($category) && preg_match('/^[0-9a-f-]{36}$/i', $category) === 1) {
            $out['category'] = strtolower($category);
        }
        $filter = [];
        $raw = $http->query('filter');
        foreach (is_array($raw) ? $raw : [] as $facet => $values) {
            $list = is_array($values) ? $values : explode(',', (string) $values);
            $list = array_values(array_unique(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $list), fn ($v) => $v !== '' && strlen($v) <= 80)));
            if (is_string($facet) && preg_match('/^[a-z0-9_-]{1,64}$/i', $facet) === 1 && $list !== []) {
                $filter[$facet] = implode(',', $list);
            }
        }
        if ($filter !== []) {
            ksort($filter);
            $out['filter'] = $filter;
        }
        if (in_array($http->query('in_stock'), ['1', 'true', 1, true, 'on'], true)) {
            $out['in_stock'] = '1';
        }
        foreach (['price_min', 'price_max'] as $name) {
            $value = $http->query($name);
            if (is_string($value) && preg_match('/^\d{1,9}([.,]\d{1,'.max(1, $exponent).'})?$/', trim($value)) === 1) {
                $out[$name] = (string) Money::minorFromMajor((float) str_replace(',', '.', trim($value)), $currency);
            }
        }
        $sort = (string) $http->query('sort', 'position');
        $out['sort'] = in_array($sort, self::SORTS, true) ? $sort : 'position';
        if (isset($out['q'])) {
            $out['sort'] = in_array($sort, self::SORTS, true) && $sort !== 'position' ? $sort : 'relevance';
        }
        $cursor = $http->query('cursor');
        if (is_string($cursor) && preg_match('/^[A-Za-z0-9_-]{1,200}$/', $cursor) === 1) {
            $out['cursor'] = $cursor;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $params */
    private function url(Store $store, array $params, string $cursor): string
    {
        $params['cursor'] = $cursor;

        return route('publicView', ['slug' => $store->slug], false).'?'.$this->queryString($params, true);
    }

    /**
     * The page's own query without the cursor and the engine's minor-unit prices (links that keep the filters: sort,
     * chips, "clear").
     *
     * @param  array<string, mixed>  $params
     */
    private function queryString(array $params, bool $withCursor): string
    {
        $keep = [];
        foreach (['q', 'category', 'in_stock', 'sort', 'price_min', 'price_max', 'filter'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '' && ! ($key === 'sort' && in_array($params[$key], ['position', 'relevance'], true))) {
                $keep[$key] = $params[$key];
            }
        }
        if ($withCursor && isset($params['cursor'])) {
            $keep['cursor'] = $params['cursor'];
        }

        return http_build_query($keep, '', '&', PHP_QUERY_RFC3986);
    }

    /** the offset a (verified) cursor stands for — display only ("Showing 49 of 120"); 0 for anything else */
    private function offsetOf(string $cursor): int
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $decoded !== false && preg_match('/^(\d{1,7})\./', $decoded, $m) === 1 ? (int) $m[1] : 0;
    }

    private function major(int $minor, int $exponent): string
    {
        return $exponent === 0 ? (string) $minor : rtrim(rtrim(number_format($minor / (10 ** $exponent), $exponent, '.', ''), '0'), '.');
    }
}
