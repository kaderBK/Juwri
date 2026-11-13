<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Api\V1;

use App\Services\Verticals\VerticalRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Api\StorefrontCatalogQuery;
use Juwri\Modules\Storefront\Services\ViewModel\GroceryPresenter;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;

/**
 * P3-06b phase A — the headless storefront reads (kit/api openapi.json `getStore`, `getCatalog`, `getItem`): the
 * View Model blocks a theme renders, for the token's store only, in the `Accept-Language` language. Public ids only,
 * money as `{minor, currency, formatted}`.
 *
 * Caching (README §2.10): `public, max-age=120` with a weak ETag of the answer itself and 304 on `If-None-Match` —
 * the body hash, not the menu-cache signature, because the store block also carries the open/closed state and the
 * catalogue the scheduled (happy-hour) prices, which change with the clock and no menu write.
 */
class StorefrontCatalogController extends Controller
{
    public function __construct(
        private readonly ViewModelBuilder $builder,
        private readonly RetailCatalog $retail,
        private readonly StorefrontCatalogQuery $query,
        private readonly GroceryCatalog $grocery,
    ) {}

    /** GET /api/v1/storefront/store — StoreResponse. */
    public function store(Request $request): Response|JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $blocks = $this->builder->apiBlocks($ctx->viewModel('home', ['vehicle' => $this->vehicleKey($request)]), 'store') ?? [];
        $id = (int) $ctx->store->id;
        $body = $blocks + [
            // Phase D (P3-10) owns booking; a services store answers its "Verify guest phone" rule already (API-7).
            'booking' => $this->isServices($ctx) ? ['verify_guest_phone' => StoreSettings::bool($id, 'booking_verify_guest_phone')] : null,
            // P3-09 / phase C (API-22, API-25, API-30): the store's "If it's out of stock" choice with its picker actions.
            'substitution' => $this->substitution($ctx),
            'holds' => ['release_during_payment' => StoreSettings::bool($id, 'holds_release_during_payment')],
        ];

        return $this->cacheable($request, $ctx, $body);
    }

    /**
     * GET /api/v1/storefront/kiosk — KioskResponse (kiosk token only; API-13 gates in StorefrontTokenAuth): the kiosk
     * block (idle time, order types, receipt printing — never a PIN) and the ordering context the kiosk screens use.
     */
    public function kiosk(Request $request): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        app()->setLocale($ctx->locale);
        if (! $ctx->isKiosk()) {
            throw new StorefrontApiException(403, 'forbidden', ___('This call needs a kiosk token.'));
        }
        $context = (array) ($this->builder->build($ctx->viewModel('home', ['kiosk' => true]))['context'] ?? []);
        $kiosk = (array) ($context['kiosk'] ?? ['on' => true]);

        return response()->json(['kiosk' => $kiosk, 'context' => $context], 200,
            ['Cache-Control' => 'no-store', 'Content-Language' => $ctx->locale], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/v1/storefront/catalog — CatalogResponse. P3-08 / phase B: Scout search, facet / stock / price filters,
     * sort and an opaque cursor ({@see StorefrontCatalogQuery}); a food store's catalogue stays one page.
     */
    public function catalog(Request $request): Response|JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        /** @var array{catalog: array<string, mixed>, index: array<string, array<string, mixed>>, facets: \Juwri\Modules\Storefront\Services\ViewModel\RetailFacets, food: bool, vehicle?: array<string, mixed>|null} $blocks */
        $blocks = $this->builder->apiBlocks($ctx->viewModel('home', ['vehicle' => $this->vehicleKey($request)]), 'catalog_index') ?? [];

        return $this->cacheable($request, $ctx, $this->query->run($ctx, $request, $blocks));
    }

    /** The `vehicle` query (P3-08b): a key, or null; whether it is valid is the catalogue query's call. */
    private function vehicleKey(Request $request): ?string
    {
        $vehicle = $request->query('vehicle');

        return is_string($vehicle) && trim($vehicle) !== '' ? trim($vehicle) : null;
    }

    /** GET /api/v1/storefront/items/{item_id} — ItemResponse (`?vehicle=` adds `item.fits_vehicle`). */
    public function item(Request $request, string $item_id): Response|JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $found = $this->builder->apiBlocks($ctx->viewModel('item', ['item' => $item_id, 'vehicle' => $this->vehicleKey($request)]), 'item');
        if ($found === null) {
            throw StorefrontApiException::notFound();
        }
        /** @var Menu $menu */
        $menu = $found['model'];
        app()->setLocale($ctx->locale); // the stock labels below
        $body = ['item' => $found['item'], 'sell' => $this->sell($ctx, $menu)];
        // P3-09 (API-22, API-25): the item's out-of-stock choice — its group (one of item.option_groups) and actions.
        $group = $this->isServices($ctx) ? null : $this->grocery->substitutionGroupFor($ctx->store, $menu);
        if ($group !== null) {
            $body['substitution_group_id'] = (string) $group->public_id;
        }
        $variants = $this->variantRows($ctx, $menu);
        if ($variants !== []) {
            $body['variants'] = $variants; // P3-08: the matrix with its stock states
        }
        $body['stock'] = $this->retail->stockState($menu);
        if ($group !== null) {
            $body['substitution_picker_actions'] = $this->grocery->pickerActions($group);
        }

        return $this->cacheable($request, $ctx, $body);
    }

    /**
     * GET /api/v1/storefront/items/{item_id}/variants — VariantsResponse (P3-08, API-6): the item's matrix with live
     * stock states, a short cache (`max-age=30`).
     */
    public function variants(Request $request, string $item_id): Response|JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $found = $this->builder->apiBlocks($ctx->viewModel('item', ['item' => $item_id]), 'item');
        if ($found === null) {
            throw StorefrontApiException::notFound();
        }
        /** @var Menu $menu */
        $menu = $found['model'];

        return $this->cacheable($request, $ctx, ['item_id' => (string) $menu->public_id, 'variants' => $this->variantRows($ctx, $menu)], 30);
    }

    /** @return list<array<string, mixed>> kit/api Variant rows */
    private function variantRows(StorefrontApiContext $ctx, Menu $menu): array
    {
        $money = new MoneyFormat($ctx->store);
        $previous = app()->getLocale();
        app()->setLocale($ctx->locale);
        try {
            $rows = [];
            foreach ($this->retail->matrix($ctx->store, $menu) as $cell) {
                $row = ['id' => $cell['public_id']];
                if ($cell['sku'] !== null) {
                    $row['sku'] = $cell['sku'];
                }
                if ($cell['gtin'] !== null) {
                    $row['gtin'] = $cell['gtin'];
                }
                $row['options'] = (object) $cell['options'];
                $row['price'] = $money->money($cell['price_minor']);
                if ($cell['compare_at_minor'] !== null) {
                    $row['compare_at'] = $money->money($cell['compare_at_minor']);
                }
                $row['stock'] = $cell['stock'];
                if ($cell['image'] !== null) {
                    $row['image'] = ['src' => media_url('storage/menu/', $cell['image'], (int) $ctx->store->id), 'alt' => trim((string) $menu->name.' '.$cell['label'])];
                }
                if ($cell['notify_available']) {
                    $row['notify_available'] = true;
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * @return array<string, mixed> SellRule: services by duration; P3-09 goods sold by weight (weight group, unit price
     *                              per kg, cap %) or in packs (pack size + unit, unit price); everything else per unit
     */
    private function sell(StorefrontApiContext $ctx, Menu $menu): array
    {
        $attributes = json_decode((string) json_encode($menu->getAttribute('attributes')), true);
        $duration = is_array($attributes) && isset($attributes['duration_min']) && is_numeric($attributes['duration_min'])
            ? (int) $attributes['duration_min'] : null;
        if ($duration !== null && $this->isServices($ctx)) {
            return ['kind' => 'service', 'duration_min' => $duration];
        }
        if ($ctx->store->isFoodVertical()) {
            return ['kind' => 'unit'];
        }

        $rule = $this->grocery->sellRule($ctx->store, $menu);
        $money = new MoneyFormat($ctx->store);
        $out = ['kind' => $rule['kind']];
        foreach (['pack_size', 'pack_unit'] as $key) {
            if (isset($rule[$key])) {
                $out[$key] = $rule[$key];
            }
        }
        if (isset($rule['unit_price_minor'])) {
            $out['unit_price'] = $money->money((int) $rule['unit_price_minor']);
            $out['unit_price_per'] = $rule['unit_price_per'];
        }
        if (isset($rule['weight_option_group'])) {
            $out['weight_option_group'] = $rule['weight_option_group'];
            $out['cap_percent'] = (int) $rule['cap_percent'];
            $out['qty_max'] = 10;
        }

        return $out;
    }

    /** @return array<string, mixed>|null StoreResponse.substitution (API-22, API-25, API-30) */
    private function substitution(StorefrontApiContext $ctx): ?array
    {
        if ($this->isServices($ctx) || $ctx->store->isFoodVertical()) {
            return null;
        }
        $group = $this->grocery->substitutionGroup($ctx->store);
        $default = $this->grocery->defaultSubstitution($ctx->store);
        if ($group === null || $default === null) {
            return null;
        }

        return [
            'group' => GroceryPresenter::substitutionGroup($group, $default),
            'default_option_id' => (string) $default->public_id,
            'picker_actions' => $this->grocery->pickerActions($group),
        ];
    }

    private function isServices(StorefrontApiContext $ctx): bool
    {
        $registry = app(VerticalRegistry::class);
        $config = $registry->config((string) ($ctx->store->store_type ?: $registry->default()));

        return ($config['themes'] ?? null) === 'service';
    }

    /** @param  array<string, mixed>  $body */
    private function cacheable(Request $request, StorefrontApiContext $ctx, array $body, int $maxAge = 120): Response
    {
        $json = ViewModelJson::encode($body);
        $etag = 'W/"'.substr(hash('sha256', $json), 0, 32).'"';
        $headers = ['Cache-Control' => 'public, max-age='.$maxAge, 'ETag' => $etag, 'Vary' => 'Accept-Language', 'Content-Language' => $ctx->locale];
        $sent = array_map('trim', explode(',', (string) $request->headers->get('If-None-Match', '')));
        if (in_array($etag, $sent, true) || in_array(substr($etag, 2), $sent, true)) {
            return response('', 304, $headers);
        }

        return response($json, 200, $headers + ['Content-Type' => 'application/json']);
    }
}
