<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp\Catalog;

use App\Services\Verticals\FulfillmentRegistry;
use App\Services\Verticals\VerticalRegistry;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Http\Controllers\McpError;
use Juwri\Modules\Agents\Mcp\DefinedTool;
use Juwri\Modules\Agents\Mcp\McpToolSet;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\CatalogQueryService;
use Juwri\Modules\Menu\Services\StarterMenuService;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;

/**
 * MCP-expansion P2 / P1-16: the tools of the platform-wide **Catalog MCP** server ({@see CatalogMcpServer},
 * POST /mcp/catalog) — moved unchanged from the former hand-rolled CatalogMcpController.
 *
 * Public and read-only — our answer to Shopify's Catalog: it lets an AI agent (ChatGPT, Claude) discover restaurants
 * and dishes across the WHOLE platform ("find shawarma near me"), then deep-link into a specific store's storefront
 * MCP to actually order. No auth, `throttle:60,1` (same as the dev MCP); the only guards are the rate limit and hard
 * result caps.
 *
 * Hard invariants (spec §4): only stores that are active, publicly visible, and not opted out of the catalog are ever
 * returned; owner PII (email, revenue, internal ids beyond what public pages already expose) is NEVER emitted. Every
 * tool is a public, read-only lookup (M3-2 annotations: readOnlyHint).
 */
final class CatalogTools implements McpToolSet
{
    /** Public read-only tools; the registry grows per spec §4 (P2-2…P2-4). */
    public function definitions(): array
    {
        return [
            DefinedTool::define(
                'search_stores',
                'Find stores across the whole platform by keyword and/or city — restaurants as well as '
                .'other verticals (retail, cosmetics, clothing, car parts, laundry & services, OTC pharmacy). '
                .'Returns up to 20 stores (name, slug, tagline, address, store_type, ordering_enabled, '
                .'public menu_url, manifest_url, and a storefront_mcp hint for placing orders). '
                .'Use this to discover WHERE to order; then open a store\'s menu_url or use its '
                .'storefront MCP. query matches name/tagline/description; city matches the address; '
                .'store_type filters to one vertical (restaurant, cafe, retail, cosmetic, clothing, carparts, '
                .'grocery, laundry, services, pharmacy) — omit it to search every vertical. Only active, publicly listed '
                .'stores are returned — no owner contact details.',
                ['query' => 'string', 'city' => 'string', 'store_type' => 'string', 'limit' => 'integer']
            ),
            DefinedTool::define(
                'search_items',
                'Find specific catalog items across ALL platform stores — dishes, but also retail/cosmetic/'
                .'clothing/car-parts products and pharmacy OTC items (e.g. "vegan burger" or "wireless mouse"). '
                .'Returns up to 30 in-stock items grouped by store, each with name, price ("from" price for a '
                .'variant-priced product), brand (when the item has one), currency, and a deep link to that '
                .'store\'s menu/catalog. Filters: query (name/description), dietary ("vegan"|"halal"|'
                .'"gluten_free", food stores only), max_price, city, store_type (restrict to one vertical — '
                .'restaurant, cafe, retail, cosmetic, clothing, carparts, grocery, laundry, services, pharmacy). Use this when the '
                .'shopper wants a specific ITEM; use search_stores when they want a place. Only active, '
                .'publicly listed stores are included.',
                ['query' => 'string', 'dietary' => 'string', 'max_price' => 'number', 'city' => 'string', 'store_type' => 'string', 'limit' => 'integer']
            ),
            DefinedTool::define(
                'get_store',
                'Full public detail for one store, by slug or id (as returned by search_stores). '
                .'Returns name, tagline, description, address, phone, business_hours, currency, '
                .'store_type, vertical (its label), order_types, ordering_enabled, the public menu_url, '
                .'a track_url_pattern, the manifest_url, and the storefront_mcp hint for ordering. '
                .'order_types lists ONLY the fulfillment modes the storefront agent-ordering MCP can '
                .'actually place today (takeaway/on-table for a restaurant) — it is empty for a '
                .'non-restaurant vertical, meaning that store\'s storefront MCP cannot place an order yet '
                .'even though it is listed here. Errors -32004 if the store is not found or is not '
                .'publicly listed.',
                ['slug' => 'string', 'id' => 'integer']
            ),
            // HPMS P2: the bundled one-click starter-menu presets a new store can seed
            // from. Public template data (no tenant data), read-only — helps an agent
            // onboarding a new restaurant pick a starting point.
            DefinedTool::define(
                'list_starter_menus',
                'The bundled starter-menu presets a brand-new store can seed its menu from in one click '
                .'(cafe, pizzeria, burger, bakery, lounge). Returns each preset\'s key, label, and a '
                .'preview (category names + how many items each has). These are platform templates, not a '
                .'live store\'s menu — use search_stores/search_items to find a real place to order.',
                []
            ),
        ];
    }

    /** Visible in the public catalog, and (P1-23, AUP 3.1) not an unpublished pharmacy. */
    private function listed(Store $store): bool
    {
        return CatalogQueryService::isVisible($store) && app(SalesRestrictions::class)->unpublishedReason($store) === null;
    }

    public function run(string $name, array $args): array
    {
        return match ($name) {
            'search_stores' => $this->searchStores($args),
            'search_items' => $this->searchItems($args),
            'get_store' => $this->getStore($args),
            'list_starter_menus' => $this->listStarterMenus(),
            default => throw new McpError(-32602, "Unknown tool: {$name}"),
        };
    }

    // --- Tools (spec §4) ---------------------------------------------------

    /**
     * P2-2: cross-store discovery by keyword/city; only visible, active stores.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function searchStores(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $city = trim((string) ($args['city'] ?? ''));
        $storeType = trim((string) ($args['store_type'] ?? ''));
        $limit = min(20, max(1, (int) ($args['limit'] ?? 10)));
        $registry = app(VerticalRegistry::class);

        $rows = CatalogQueryService::eligibleQuery()
            ->when($query !== '', fn ($q) => $q->where(fn (Builder $w) => SearchTerm::apply($w, ['title', 'sub_title', 'description'], $query)))
            ->when($city !== '', fn (Builder $q) => SearchTerm::applyOrNone($q, ['address'], $city))
            // rev/multi-vertical MCP-expansion fix 2: an optional store_type filter.
            ->when($storeType !== '', fn ($q) => $q->where('store_type', $storeType))
            ->orderBy('title')
            // Over-fetch a little so the plan-status/opt-out PHP filter still fills the page.
            ->limit($limit * 3)->get()
            ->filter(fn ($p) => $this->listed($p))
            ->take($limit)
            ->map(fn ($p) => array_merge(CatalogQueryService::storeCard($p), [
                'store_type' => $p->store_type ?: $registry->default(),
            ]))
            ->values()->all();

        return ['count' => count($rows), 'stores' => $rows];
    }

    /**
     * P2-3: cross-store dish search, grouped by store; visible stores only.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function searchItems(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $city = trim((string) ($args['city'] ?? ''));
        $dietary = trim((string) ($args['dietary'] ?? ''));
        $storeType = trim((string) ($args['store_type'] ?? ''));
        $maxPrice = isset($args['max_price']) ? (float) $args['max_price'] : null;
        $limit = min(30, max(1, (int) ($args['limit'] ?? 15)));
        $registry = app(VerticalRegistry::class);

        $dietaryColumn = match ($dietary) {
            'vegan' => 'is_vegan',
            'halal' => 'is_halal',
            'gluten_free' => 'is_gluten_free',
            default => null,
        };

        $items = Menu::where('active', '1')->where('is_sold_out', 0)
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => SearchTerm::apply($w, ['name', 'description'], $query)))
            ->when($dietaryColumn !== null, fn ($q) => $q->where($dietaryColumn, 1))
            // rev/multi-vertical MCP-expansion fix 2: an optional store_type filter,
            // scoped through the owning store.
            ->when($storeType !== '', fn ($q) => $q->whereHas('store', fn ($r) => $r->where('store_type', $storeType)))
            // Eager-load variants + brand so variant-priced items resolve a real
            // "from" price and non-food goods can surface their brand name.
            ->with('store.user', 'variants', 'brand')
            ->orderBy('price_minor')
            ->limit($limit * 6)->get()
            ->filter(fn ($m) => $m->store
                && $this->listed($m->store)
                && ($city === '' || str_contains(mb_strtolower((string) $m->store->address), mb_strtolower($city)))
                // max_price is applied to the EFFECTIVE price (base or cheapest
                // variant), so variant-priced items are filtered honestly.
                && ($maxPrice === null || CatalogQueryService::itemPrice($m) <= $maxPrice))
            ->take($limit);

        $grouped = $items->groupBy('store_id')->map(function ($rows) use ($registry) {
            $store = $rows->first()->store;

            return [
                'store' => $store->title,
                'slug' => $store->slug,
                'store_type' => $store->store_type ?: $registry->default(),
                'menu_url' => route('publicView', $store->slug),
                'currency' => store_options($store->id, 'currency_code'),
                'items' => $rows->map(fn ($m) => [
                    'name' => $m->getRawOriginal('name'),
                    'price' => CatalogQueryService::itemPrice($m),
                    'brand' => $m->brand?->name,
                ])->values()->all(),
            ];
        })->values()->all();

        return ['item_count' => $items->count(), 'stores' => $grouped];
    }

    /**
     * P2-4: full public detail for one store by slug or id; visible stores only.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function getStore(array $args): array
    {
        $slug = trim((string) ($args['slug'] ?? ''));
        $id = (int) ($args['id'] ?? 0);
        if ($slug === '' && $id <= 0) {
            throw new McpError(-32602, 'Provide slug or id.');
        }

        $store = CatalogQueryService::eligibleQuery()
            ->when($slug !== '', fn ($q) => $q->where('slug', $slug))
            ->when($slug === '', fn ($q) => $q->where('id', $id)) // slug or id > 0 is guaranteed above
            ->first();

        if (! $store || ! $this->listed($store)) {
            throw new McpError(-32004, 'Store not found or not publicly listed.');
        }

        $registry = app(VerticalRegistry::class);
        $type = $store->store_type ?: $registry->default();
        $detail = CatalogQueryService::storeDetail($store);
        $detail['store_type'] = $type;
        $detail['vertical'] = (string) ($registry->config($type)['label'] ?? ucfirst($type));

        /* rev/multi-vertical MCP-expansion fix 2/4: order_types must reflect what
           the storefront agent-ordering MCP can ACTUALLY place, not just what the
           vertical's checkout supports in the dashboard. The storefront MCP's
           create_order is frozen to takeaway/on-table only (D8), so intersect
           rather than substitute the vertical's full fulfillment set — a
           non-restaurant store correctly gets an empty order_types (it is listed
           here for discovery, but its storefront MCP cannot place an order yet). */
        $detail['order_types'] = array_values(array_intersect(
            ['takeaway', 'on-table'],
            app(FulfillmentRegistry::class)->modesFor($type)
        ));

        return $detail;
    }

    /**
     * HPMS P2: read the bundled starter-menu presets (StarterMenuService::PRESETS)
     * and their preview — public template files under database/starter-menus, no
     * tenant data. A missing/malformed file is skipped, never a 500.
     *
     * @return array<string, mixed>
     */
    private function listStarterMenus(): array
    {
        $presets = [];
        foreach (StarterMenuService::PRESETS as $key) {
            $path = database_path('starter-menus/'.$key.'.json');
            if (! is_file($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (! is_array($data)) {
                continue;
            }

            $categories = [];
            foreach (($data['categories'] ?? []) as $cat) {
                if (! is_array($cat)) {
                    continue;
                }
                $categories[] = [
                    'name' => (string) ($cat['name'] ?? ''),
                    'items' => count((array) ($cat['items'] ?? [])),
                ];
            }

            $presets[] = [
                'key' => $key,
                'label' => (string) ($data['label'] ?? ucfirst($key)),
                'categories' => $categories,
            ];
        }

        return ['count' => count($presets), 'presets' => $presets];
    }
}
