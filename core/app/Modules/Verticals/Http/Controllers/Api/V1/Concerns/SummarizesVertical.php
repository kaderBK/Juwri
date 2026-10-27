<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\Api\V1\Concerns;

use App\Services\Verticals\FulfillmentRegistry;
use App\Services\Verticals\VerticalRegistry;
use Juwri\Core\Models\Store;

/**
 * rev/mv-surface-api P1 — the shared `store_type` + vertical-capability block
 * every owner/storefront REST endpoint exposes so an integrator can tell a
 * restaurant from a retail/cosmetic/clothing/carparts/laundry/pharmacy/hotel
 * store and know which capabilities + fulfillment modes apply.
 *
 * Extracted from StoreController so the token-scoped StorefrontController
 * returns the SAME shape (byte-identical keys/fallbacks) — a partner reads the
 * same vertical metadata from the owner-token and storefront-token surfaces.
 * The keys also mirror the MCP server's list_restaurants/get_restaurant_settings
 * verticalSummary(). No query — VerticalRegistry just reads config(). A
 * legacy/unknown store_type falls back to the registry default ('restaurant').
 */
trait SummarizesVertical
{
    /**
     * @return array{store_type:string, vertical:array<string,mixed>}
     */
    protected function verticalSummary(Store $store): array
    {
        $registry = app(VerticalRegistry::class);
        $type = $store->store_type ?: $registry->default();
        $config = $registry->config($type);

        return [
            'store_type' => $type,
            'vertical' => [
                'label' => (string) ($config['label'] ?? ucfirst($type)),
                'group' => $config['group'] ?? 'food',
                'capabilities' => $this->verticalCapabilities($config),
                'fulfillment_modes' => app(FulfillmentRegistry::class)->modesFor($type),
            ],
        ];
    }

    /**
     * The capability booleans, resolved once so the menu serializer and the
     * summary agree on what a store exposes.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,bool>
     */
    protected function verticalCapabilities(array $config): array
    {
        return [
            'food_attrs' => (bool) ($config['food_attrs'] ?? false),
            'variant_goods' => (bool) ($config['variant_goods'] ?? false),
            'fitment' => (bool) ($config['fitment'] ?? false),
            'age_gate' => (bool) ($config['age_gate'] ?? false),
            'expiry' => (bool) ($config['expiry'] ?? false),
            'service' => (bool) ($config['service'] ?? false),
        ];
    }
}
