<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services;

use App\Services\SandboxStores;
use App\Services\SitemapService;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * MCP-expansion P2: the single gate for what the public Catalog MCP may expose.
 * A store is catalog-eligible ONLY when its owner is active, the owner's plan is
 * enabled, and the store has not opted out (`catalog_visible`, P2-5). This is the
 * same live/public predicate the SEO sitemap uses ({@see SitemapService}); it
 * lives here so every catalog tool (search_stores / search_items / get_store)
 * applies it identically and no non-public store ever leaks.
 *
 * NEVER emit owner PII here (email, revenue, internal ids beyond the public
 * slug/id) — only what a public store page already shows (spec §4).
 */
class CatalogQueryService
{
    /**
     * Base eligible-store query: active owner, no developer sandbox (F5-5).
     * Plan status + opt-out are checked in PHP.
     */
    public static function eligibleQuery()
    {
        return Store::excludingSandbox()->with('user')->whereHas('user', fn ($q) => $q->where('status', 1));
    }

    /**
     * Full eligibility incl. plan status + opt-out (defaults visible until P2-5
     * column exists) and the F5-5 sandbox exclusion — search_items() reaches
     * stores through Menu, not through eligibleQuery(), so the predicate has to
     * carry the sandbox check itself or fake sandbox dishes leak to the public.
     */
    public static function isVisible(Store $store): bool
    {
        return $store->user
            && (int) $store->user->status === 1
            && optional($store->user->plan())->status
            && (int) ($store->catalog_visible ?? 1) === 1
            && ! SandboxStores::isSandbox($store->id);
    }

    /**
     * Display price for a catalog item: the base menu price, or — when the item
     * is variant-priced (base 0) — the cheapest active variant ("from" price).
     * Keeps the public catalog from showing "free" for variant-only dishes.
     */
    public static function itemPrice(Menu $menu): float
    {
        $base = (float) $menu->price;
        if ($base > 0) {
            return $base;
        }
        $variantPrices = $menu->variants->map(fn ($v) => (float) $v->price)->filter(fn ($p) => $p > 0);

        return $variantPrices->isNotEmpty() ? (float) $variantPrices->min() : 0.0;
    }

    /** Public-safe store shape for catalog tools. No owner PII, ever. */
    public static function storeCard(Store $store): array
    {
        return [
            'name' => $store->title,
            'slug' => $store->slug,
            'tagline' => $store->sub_title,
            'address' => $store->address,
            'ordering_enabled' => whatsapp_ordering_enabled($store->id),
            'menu_url' => route('publicView', $store->slug),
            'manifest_url' => route('agent.manifest', $store->id),
            'storefront_mcp' => [
                'endpoint' => route('mcp.storefront'),
                'note' => 'Ordering needs a per-store agent token (X-Storefront-Token); ask the store to issue one.',
            ],
        ];
    }

    /** Fuller public detail for get_store — extends the card with hours + phone + links. */
    public static function storeDetail(Store $store): array
    {
        return array_merge(self::storeCard($store), [
            'phone' => $store->phone,
            'description' => $store->description,
            'business_hours' => $store->timing,
            'currency' => store_options($store->id, 'currency_code'),
            // Agent/storefront ordering is takeaway/on-table only (D8 — no delivery).
            'order_types' => ['takeaway', 'on-table'],
            'track_url_pattern' => url('/api/v1/track/{track_token}'),
        ]);
    }
}
