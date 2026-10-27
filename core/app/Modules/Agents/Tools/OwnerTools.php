<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Tools;

use App\Exceptions\PlanLimitReachedException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\VehicleFitment;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\SalesAnalyticsService;
use App\Services\Verticals\VerticalRegistry;
use App\Support\SearchTerm;
use App\Support\StoreClock;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Delivery\Models\Shipment;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Services\MenuAdminService;
use Juwri\Modules\Ordering\Enums\OrderStatus;

/**
 * P1-16: the store owner's agent tools (#102 owner MCP), moved verbatim from McpController onto the shared
 * {@see AgentToolRegistry}. Every tool is scoped to the authenticated account's stores — a call can never touch
 * another tenant's data (ownedRestaurant / ownedVariant / the coupon owner check refuse with -32004). Read tools are
 * default-on; mutating tools need a write ability and are logged via log_activity() (#79).
 */
final class OwnerTools
{
    public static function define(AgentToolRegistry $registry): void
    {
        $tools = new self;
        foreach ($tools->spec() as $name => $def) {
            $handler = $def['handler'];
            $registry->register(new AgentTool(
                name: $name,
                description: $def['description'],
                inputSchema: self::schema($def['props'], $def['required']),
                audience: AgentTool::OWNER,
                channels: [AgentTool::CHANNEL_MCP],
                handler: fn (ToolContext $context, array $args) => $tools->{$handler}((int) $context->userId, $args),
                ability: $def['ability'],
                feature: $def['feature'] ?? null,
                annotations: self::annotationsFor($name, $def['ability']),
            ));
        }
    }

    /**
     * @param  array<string, string>  $props  name => JSON type
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function schema(array $props, array $required): array
    {
        $properties = [];
        foreach ($props as $k => $type) {
            $properties[$k] = ['type' => $type];
        }

        return ['type' => 'object', 'properties' => (object) $properties, 'required' => $required];
    }

    /**
     * P1-1: the single declarative source of truth for every owner MCP tool.
     * `tools/list`, ability gating, and dispatch all derive from this one map,
     * so a tool is defined in exactly one place.
     *
     * Each entry: [ability, feature?, description, props, required, handler].
     * `ability` is the granular ApiToken ability the tool needs (e.g.
     * `menu:write`) or null for a read tool available to any valid token.
     * `feature` (FIX-6 F6-1, optional) is the feature key the TOKEN OWNER must
     * hold — the MCP twin of the `feature:<key>` route middleware; omit it for
     * tools whose web equivalent is ungated. `handler` is a method called as
     * `$this->handler($userId, $args)`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function spec(): array
    {
        return [
            'list_restaurants' => [
                'ability' => null, 'handler' => 'listRestaurants',
                'description' => 'List the restaurants (or other vertical stores) you own. Each row includes '
                    .'store_type (restaurant, cafe, retail, cosmetic, clothing, carparts, grocery, laundry, services, pharmacy) and a '
                    .'vertical summary (label + capabilities: variant_goods, fitment, age_gate, expiry, service) '
                    .'so an agent can tell what kind of store it is before calling get_menu or a vertical tool.',
                'props' => [], 'required' => [],
            ],
            'get_menu' => [
                'ability' => null, 'handler' => 'getMenu',
                'description' => "Get a restaurant's categories and items.",
                'props' => ['store_id' => 'integer'], 'required' => [],
            ],
            'list_orders' => [
                'ability' => 'orders:read', 'handler' => 'listOrders',
                'description' => "List a restaurant's orders, newest first. Filter with status, a created-at date range (date_from / date_to, YYYY-MM-DD, inclusive), and search (matches customer name or phone, case-insensitive). limit caps results (default 30, max 100). Returns a summary row per order; call get_order for full line items.",
                'props' => ['store_id' => 'integer', 'status' => 'string', 'date_from' => 'string', 'date_to' => 'string', 'search' => 'string', 'limit' => 'integer'],
                'required' => ['store_id'],
            ],
            'get_order' => [
                'ability' => 'orders:read', 'handler' => 'getOrder',
                'description' => 'Full detail for one order by id: status, type, table, customer name + phone, paid flag, total, timestamps, and every line item (name, quantity, unit price, note). Use list_orders to find the id first. Errors -32004 if the order is not found or not yours.',
                'props' => ['store_id' => 'integer', 'order_id' => 'integer'],
                'required' => ['store_id', 'order_id'],
            ],
            'get_analytics' => [
                'ability' => null, 'handler' => 'getAnalytics',
                'description' => 'Order count and revenue for a restaurant over N days.',
                'props' => ['store_id' => 'integer', 'days' => 'integer'],
                'required' => ['store_id'],
            ],
            'update_order_status' => [
                'ability' => 'write', 'handler' => 'updateOrderStatus',
                'description' => "Advance an order's status.",
                'props' => ['store_id' => 'integer', 'order_id' => 'integer', 'status' => 'string'],
                'required' => ['store_id', 'order_id', 'status'],
            ],
            'add_menu_item' => [
                'ability' => 'write', 'handler' => 'addMenuItem',
                'description' => 'Create a catalog item. For a non-food store (retail/pharmacy/etc) you may also set goods fields: brand_id, weight, pricing_unit, sku, track_inventory, stock_qty, and pharmacy OTC fields age_restricted, batch_no, expiry_date (YYYY-MM-DD). These are ignored for a restaurant.',
                'props' => ['store_id' => 'integer', 'category_id' => 'integer', 'name' => 'string', 'price' => 'number', 'description' => 'string', 'brand_id' => 'integer', 'weight' => 'number', 'pricing_unit' => 'string', 'sku' => 'string', 'track_inventory' => 'boolean', 'stock_qty' => 'integer', 'age_restricted' => 'boolean', 'batch_no' => 'string', 'expiry_date' => 'string'],
                'required' => ['store_id', 'name', 'price'],
            ],
            'update_menu_item' => [
                'ability' => 'write', 'handler' => 'updateMenuItem',
                'description' => 'Update a catalog item. Sets name/price/description, and for a non-food store the goods fields brand_id, weight, pricing_unit, sku, track_inventory, stock_qty, age_restricted, batch_no, expiry_date (YYYY-MM-DD) — ignored for a restaurant. Only the fields you pass change.',
                'props' => ['store_id' => 'integer', 'item_id' => 'integer', 'name' => 'string', 'price' => 'number', 'description' => 'string', 'brand_id' => 'integer', 'weight' => 'number', 'pricing_unit' => 'string', 'sku' => 'string', 'track_inventory' => 'boolean', 'stock_qty' => 'integer', 'age_restricted' => 'boolean', 'batch_no' => 'string', 'expiry_date' => 'string'],
                'required' => ['store_id', 'item_id'],
            ],

            // P1-2 — Category tools (menu:read / menu:write).
            'list_categories' => [
                'ability' => 'menu:read', 'handler' => 'listCategories',
                'description' => 'List a restaurant\'s menu categories (id, name, position, and items_count). Use this to discover category ids before add_menu_item, update_category, or delete_category.',
                'props' => ['store_id' => 'integer'], 'required' => ['store_id'],
            ],
            'add_category' => [
                'ability' => 'menu:write', 'handler' => 'addCategory',
                'description' => 'Create a menu category. Returns {id, name}. Only name is required; optional position (sort order) and active. Create the category first, then pass its id as category_id to add_menu_item.',
                'props' => ['store_id' => 'integer', 'name' => 'string', 'position' => 'integer', 'active' => 'boolean'],
                'required' => ['store_id', 'name'],
            ],
            'update_category' => [
                'ability' => 'menu:write', 'handler' => 'updateCategory',
                'description' => 'Rename or reorder a menu category. Pass category_id plus any of name/position/active. Returns {id, name}. Errors -32004 if the category is not found or not yours.',
                'props' => ['store_id' => 'integer', 'category_id' => 'integer', 'name' => 'string', 'position' => 'integer', 'active' => 'boolean'],
                'required' => ['store_id', 'category_id'],
            ],
            'delete_category' => [
                'ability' => 'menu:write', 'handler' => 'deleteCategory',
                'description' => 'Delete a menu category. REFUSES with error -32006 when the category still has items — move or delete those items first (an empty category deletes cleanly). Returns {deleted:true, id}.',
                'props' => ['store_id' => 'integer', 'category_id' => 'integer'],
                'required' => ['store_id', 'category_id'],
            ],

            // P1-3 — Item tools (menu:write).
            'delete_menu_item' => [
                'ability' => 'menu:write', 'handler' => 'deleteMenuItem',
                'description' => 'Permanently delete a menu item by id. Returns {deleted:true, id}. To temporarily hide an item that is out of stock, use set_item_availability instead — deletion is irreversible. Errors -32004 if the item is not found or not yours.',
                'props' => ['store_id' => 'integer', 'item_id' => 'integer'],
                'required' => ['store_id', 'item_id'],
            ],
            'set_item_availability' => [
                'ability' => 'menu:write', 'handler' => 'setItemAvailability',
                'description' => 'Mark a menu item in or out of stock (the "86" toggle) without deleting it. Pass available:true to make it orderable again, available:false to mark it sold out. Returns {id, available, is_sold_out}. Prefer this over delete_menu_item for temporary stock-outs.',
                'props' => ['store_id' => 'integer', 'item_id' => 'integer', 'available' => 'boolean'],
                'required' => ['store_id', 'item_id', 'available'],
            ],

            // P1-5 — Customer + analytics tools.
            /* FIX-6 F6-1: both customer tools carry `feature` => 'crm_suite',
               mirroring the web CRM's `feature:crm_suite` route gate. Customer
               PII is the asset — a token ability alone never unlocks it. */
            'list_customers' => [
                'ability' => 'customers:read', 'feature' => 'crm_suite', 'handler' => 'listCustomers',
                'description' => "List a restaurant's customers (CRM), most-recent order first. Optional search matches name, phone, or email (case-insensitive); limit caps results (default 30, max 100). Each row: id, name, phone, email, orders_count, total_spent, last_order_at. Call get_customer for one customer's full record.",
                'props' => ['store_id' => 'integer', 'search' => 'string', 'limit' => 'integer'],
                'required' => ['store_id'],
            ],
            'get_customer' => [
                'ability' => 'customers:read', 'feature' => 'crm_suite', 'handler' => 'getCustomer',
                'description' => 'Full CRM record for one customer by id: name, phone, email, orders_count, total_spent, last_order_at, tags, created_at. Errors -32004 if the customer is not found or not yours.',
                'props' => ['store_id' => 'integer', 'customer_id' => 'integer'],
                'required' => ['store_id', 'customer_id'],
            ],
            'sales_report' => [
                'ability' => 'analytics:read', 'handler' => 'salesReport',
                'description' => 'Sales summary for a date range: order counts (total/paid, by status, by type), revenue (gross/paid/avg order value), and the top 10 items by quantity. Pass from and to as YYYY-MM-DD (inclusive); default is the last 30 days. This is the authoritative revenue source — do not sum orders yourself.',
                'props' => ['store_id' => 'integer', 'from' => 'string', 'to' => 'string'],
                'required' => ['store_id'],
            ],

            // P1-6 — Settings tools.
            'get_restaurant_settings' => [
                'ability' => 'restaurants:read', 'handler' => 'getRestaurantSettings',
                'description' => 'Read-only snapshot of a restaurant\'s profile + ordering settings: name, address, phone, business_hours (free-text), currency, store_type, a vertical summary (label + capabilities), and ordering flags (online_ordering_enabled, delivery_charge). Use this to inspect config before update_business_hours.',
                'props' => ['store_id' => 'integer'],
                'required' => ['store_id'],
            ],
            'update_business_hours' => [
                'ability' => 'restaurants:write', 'handler' => 'updateBusinessHours',
                'description' => 'Set the restaurant\'s business-hours text (free-form, e.g. "Mon–Sat 9am–11pm, Sun closed"), shown on the public page. Pass business_hours (max 255 chars). Returns {id, business_hours}. This is the whole hours field — send the complete text, it replaces the previous value.',
                'props' => ['store_id' => 'integer', 'business_hours' => 'string'],
                'required' => ['store_id', 'business_hours'],
            ],

            // P1-9 (decision A) — Coupon-management tools. Owner-scoped (a coupon
            // belongs to the token owner, applied to their food orders at checkout).
            'list_coupons' => [
                'ability' => 'orders:read', 'handler' => 'listCoupons',
                'description' => 'List your discount coupons (id, code, type, value, usage limit, used count, min_order_amount, expiry). Coupons are account-level (apply across your restaurants\' food orders). limit caps results (default 50, max 100).',
                'props' => ['limit' => 'integer'],
                'required' => [],
            ],
            'create_coupon' => [
                'ability' => 'orders:write', 'handler' => 'createCoupon',
                'description' => 'Create a food-order discount coupon. coupon_type is "percentage" (value = percent off, e.g. 15) or "fixed" (value = amount off in your currency). Optional: limit (max total redemptions, default 9999), expiry_at (YYYY-MM-DD), min_order_amount. Codes are uppercased and must be unique among your coupons (error -32602 on clash). Returns the created coupon.',
                'props' => ['code' => 'string', 'coupon_type' => 'string', 'value' => 'number', 'limit' => 'integer', 'expiry_at' => 'string', 'min_order_amount' => 'number'],
                'required' => ['code', 'coupon_type', 'value'],
            ],
            'update_coupon' => [
                'ability' => 'orders:write', 'handler' => 'updateCoupon',
                'description' => 'Edit one of your coupons by id. Pass any of code, coupon_type, value, limit, expiry_at, min_order_amount. Returns the updated coupon. Errors -32004 if not found/not yours, -32602 on a code clash.',
                'props' => ['coupon_id' => 'integer', 'code' => 'string', 'coupon_type' => 'string', 'value' => 'number', 'limit' => 'integer', 'expiry_at' => 'string', 'min_order_amount' => 'number'],
                'required' => ['coupon_id'],
            ],
            'delete_coupon' => [
                'ability' => 'orders:write', 'handler' => 'deleteCoupon',
                'description' => 'Delete one of your coupons by id. Returns {deleted:true, id}. Errors -32004 if not found or not yours. Irreversible.',
                'props' => ['coupon_id' => 'integer'],
                'required' => ['coupon_id'],
            ],

            // rev/multi-vertical MCP-expansion fix 1(b) — vertical-scoped catalog/
            // fulfillment tools, gated on the SAME `multi_vertical` entitlement the
            // dashboard's non-restaurant manage-surfaces require (mirrors
            // Entitlements::allows($owner,'multi_vertical') via the `feature` key's
            // FeatureAccess twin, see featureOk()). Read-only; a restaurant store
            // simply has no brands/variants/shipments/fitments/expiry-tracked items,
            // so these are additive no-ops for the legacy restaurant fleet.
            'list_brands' => [
                'ability' => 'menu:read', 'feature' => 'multi_vertical', 'handler' => 'listBrands',
                'description' => 'List a store\'s brands (retail, cosmetic, clothing, car-parts, pharmacy — Multi-Vertical Stores feature). Returns id, name, slug, active, position, items_count. Use this to discover brand ids before filtering or assigning items to a brand.',
                'props' => ['store_id' => 'integer'], 'required' => ['store_id'],
            ],
            'list_variants' => [
                'ability' => 'menu:read', 'feature' => 'multi_vertical', 'handler' => 'listVariants',
                'description' => 'List one item\'s variants (size/color/etc. matrix) for a variant-tracked goods store. Pass item_id. Each row: id, sku, barcode, options (the axis combination, e.g. "Size: M, Color: Red"), stock_qty (null = not stock-tracked), price. Errors -32004 if the item is not found or not yours.',
                'props' => ['store_id' => 'integer', 'item_id' => 'integer'],
                'required' => ['store_id', 'item_id'],
            ],
            'list_shipments' => [
                'ability' => 'orders:read', 'feature' => 'multi_vertical', 'handler' => 'listShipments',
                'description' => 'List fulfillment/shipment status for a restaurant\'s physical-goods orders, newest first. Filter with order_id and/or status (pending, shipped, delivered). limit caps results (default 30, max 100). Each row: order_id, status, carrier, tracking_number, shipped_at, delivered_at.',
                'props' => ['store_id' => 'integer', 'order_id' => 'integer', 'status' => 'string', 'limit' => 'integer'],
                'required' => ['store_id'],
            ],
            'list_fitments' => [
                'ability' => 'menu:read', 'feature' => 'multi_vertical', 'handler' => 'listFitments',
                'description' => 'List vehicle Year/Make/Model fitment rows for a car-parts item. Pass item_id. Each row: id, year, make, model, submodel, engine, label (e.g. "2016 Honda Civic EX (2.0L)"), notes. Errors -32004 if the item is not found or not yours.',
                'props' => ['store_id' => 'integer', 'item_id' => 'integer'],
                'required' => ['store_id', 'item_id'],
            ],
            'list_expiring_items' => [
                'ability' => 'menu:read', 'feature' => 'multi_vertical', 'handler' => 'listExpiringItems',
                'description' => 'Batch/expiry compliance read for a pharmacy (OTC-only) or other expiry-tracked store. Pass days to set the look-ahead window for "expiring soon" (default 30, max 365). Returns {expired: [...], expiring_soon: [...]}, each row {id, name, batch_no, expiry_date, age_restricted}. Never returns prescription, pharmacist, controlled-substance, or patient data — only batch_no/expiry_date on the item.',
                'props' => ['store_id' => 'integer', 'days' => 'integer'],
                'required' => ['store_id'],
            ],
            // rev/mv-surface-api: variant WRITE (write + multi_vertical) — the twin of
            // list_variants, so an agent can build a goods item's size×color matrix.
            'add_variant' => [
                'ability' => 'write', 'feature' => 'multi_vertical', 'handler' => 'addVariant',
                'description' => 'Add a variant (a size/color/option combination) to a goods item. Pass item_id and options — the axis map, e.g. {"Size":"M","Color":"Red"} — plus price. Optional sku, barcode, stock_qty (omit or null = not stock-tracked). Returns the created variant. Errors -32004 if the item is not found or not yours.',
                'props' => ['store_id' => 'integer', 'item_id' => 'integer', 'options' => 'object', 'price' => 'number', 'sku' => 'string', 'barcode' => 'string', 'stock_qty' => 'integer'],
                'required' => ['store_id', 'item_id', 'options', 'price'],
            ],
            'update_variant' => [
                'ability' => 'write', 'feature' => 'multi_vertical', 'handler' => 'updateVariant',
                'description' => 'Update a goods variant by variant_id — price, sku, barcode, stock_qty, active. Only the fields you pass change. Errors -32004 if the variant is not found or not yours.',
                'props' => ['store_id' => 'integer', 'variant_id' => 'integer', 'price' => 'number', 'sku' => 'string', 'barcode' => 'string', 'stock_qty' => 'integer', 'active' => 'boolean'],
                'required' => ['store_id', 'variant_id'],
            ],
            'delete_variant' => [
                'ability' => 'write', 'feature' => 'multi_vertical', 'handler' => 'deleteVariant',
                'description' => 'Delete a goods variant by variant_id. Errors -32004 if the variant is not found or not yours.',
                'props' => ['store_id' => 'integer', 'variant_id' => 'integer'],
                'required' => ['store_id', 'variant_id'],
            ],
        ];
    }

    /**
     * M3-2: MCP tool annotations derived from the registry so new tools inherit
     * correct hints automatically. A tool needing a `*:write`/`write` ability is
     * a write; `delete_*` names are destructive; `update_*`/`set_*` are
     * idempotent (re-applying the same values yields the same state).
     *
     * @return array<string,bool>
     */
    public static function annotationsFor(string $name, ?string $ability): array
    {
        $isWrite = $ability === 'write' || ($ability !== null && str_ends_with($ability, ':write'));
        if (! $isWrite) {
            return ['readOnlyHint' => true];
        }

        return [
            'readOnlyHint' => false,
            'destructiveHint' => str_starts_with($name, 'delete_'),
            'idempotentHint' => str_starts_with($name, 'update_') || str_starts_with($name, 'set_'),
        ];
    }

    // --- Ownership guard ---------------------------------------------------

    /**
     * @param  mixed  $storeId
     */
    private function ownedRestaurant(int $userId, $storeId): Store
    {
        $store = Store::where('user_id', $userId)->find((int) $storeId);
        if (! $store) {
            throw new ToolError(-32004, 'Restaurant not found or not yours.');
        }

        return $store;
    }

    // --- Read tools --------------------------------------------------------

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listRestaurants(int $userId, array $args = []): array
    {
        return Store::where('user_id', $userId)->get()
            ->map(fn ($p) => array_merge(
                ['id' => $p->id, 'title' => $p->title, 'slug' => $p->slug],
                $this->verticalSummary($p->store_type ?? null)
            ))->values()->all();
    }

    /**
     * rev/multi-vertical MCP-expansion fix 1(a) — the store_type + a short
     * vertical label/capabilities summary shared by list_restaurants and
     * get_restaurant_settings. A legacy/unknown store_type falls back to the
     * registry default ('restaurant'), exactly like VerticalRegistry does
     * everywhere else, so this is purely additive for the restaurant fleet.
     *
     * @return array{store_type:string, vertical:array{label:string,capabilities:array<string,bool>}}
     */
    private function verticalSummary(?string $storeType): array
    {
        $registry = app(VerticalRegistry::class);
        $type = $storeType ?: $registry->default();
        $config = $registry->config($type);

        return [
            'store_type' => $type,
            'vertical' => [
                'label' => (string) ($config['label'] ?? ucfirst($type)),
                'capabilities' => [
                    'variant_goods' => (bool) ($config['variant_goods'] ?? false),
                    'fitment' => (bool) ($config['fitment'] ?? false),
                    'age_gate' => (bool) ($config['age_gate'] ?? false),
                    'expiry' => (bool) ($config['expiry'] ?? false),
                    'service' => (bool) ($config['service'] ?? false),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function getMenu(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $isFood = $store->isFoodVertical();
        $cfg = $store->verticalConfig();

        $categories = MenuCategory::where('store_id', $store->id)->orderBy('position')->get();
        $itemsQuery = Menu::where('store_id', $store->id);
        if (! $isFood) {
            // Eager-load only what the goods row below reads (fitments = car-parts).
            $with = ['brand', 'variants'];
            if (! empty($cfg['fitment'])) {
                $with[] = 'fitments';
            }
            $itemsQuery->with($with);
        }
        $items = $itemsQuery->get();

        return $categories->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'items' => $items->where('category_id', $c->id)
                ->map(fn ($i) => $this->mcpMenuItemRow($i, $isFood, $cfg))
                ->values()->all(),
        ])->values()->all();
    }

    /**
     * rev/mv-mcp-parity: one get_menu row. Goods verticals expose
     * brand/stock/variant-count + capability-gated OTC (age/expiry) and
     * fitment_count instead of a bare food row, so an admin agent sees a shop's
     * catalog properly. Food stores keep the original shape.
     *
     * @param  array<string,mixed>  $cfg
     * @return array<string,mixed>
     */
    private function mcpMenuItemRow(Menu $i, bool $isFood, array $cfg): array
    {
        $row = ['id' => $i->id, 'name' => $i->name, 'price' => (float) $i->price, 'active' => (string) $i->active === '1'];
        if ($isFood) {
            return $row;
        }
        if ($i->brand) {
            $row['brand'] = $i->brand->name;
        }
        if ($i->track_inventory && $i->stock_qty !== null) {
            $row['stock'] = (int) $i->stock_qty;
        }
        if ($i->variants->isNotEmpty()) {
            $row['variant_count'] = $i->variants->count();
        }
        if (! empty($cfg['age_gate'])) {
            $row['age_restricted'] = (bool) $i->age_restricted;
        }
        if (! empty($cfg['expiry']) && $i->expiry_date !== null) {
            $row['expiry_date'] = $i->expiry_date->toDateString();
        }
        if (! empty($cfg['fitment']) && $i->relationLoaded('fitments') && $i->fitments->isNotEmpty()) {
            $row['fitment_count'] = $i->fitments->count();
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listOrders(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $limit = min(100, max(1, (int) ($args['limit'] ?? 30)));
        $search = trim((string) ($args['search'] ?? ''));

        return Order::where('store_id', $store->id)
            ->when($args['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->tap(fn ($q) => StoreClock::whereLocalDateBetween($q, 'created_at', $store->id, $args['date_from'] ?? null, $args['date_to'] ?? null))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => SearchTerm::apply($w, ['customer_name'], $search, ['phone_number'])))
            ->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn ($o) => [
                'id' => $o->id, 'status' => $o->status, 'type' => $o->type,
                'table_number' => $o->table_number, 'customer_name' => $o->customer_name,
                'total' => (float) $o->total,
                'created_at' => optional($o->created_at)->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function getOrder(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $order = Order::where('store_id', $store->id)->with('items.menu')->find((int) ($args['order_id'] ?? 0));
        if (! $order) {
            throw new ToolError(-32004, 'Order not found or not yours.');
        }

        return [
            'id' => $order->id,
            'status' => $order->status,
            'type' => $order->type,
            'table_number' => $order->table_number,
            'customer_name' => $order->customer_name,
            'phone' => $order->phone_number,
            'total' => (float) $order->total,
            'is_paid' => (bool) $order->is_paid,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'items' => $order->items->map(fn ($it) => [
                'item_id' => $it->item_id,
                'name' => optional($it->menu)->getRawOriginal('name') ?? '—',
                'quantity' => (int) $it->quantity,
                'price' => (float) (optional($it->menu)->price ?? 0),
                'note' => $it->order_note,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function getAnalytics(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $days = min(365, max(1, (int) ($args['days'] ?? 7)));
        $since = now()->subDays($days);

        $orders = Order::where('store_id', $store->id)->where('created_at', '>=', $since)
            ->with(['items.menu', 'items.variant'])->get();

        return [
            'store_id' => $store->id,
            'period_days' => $days,
            'orders_count' => $orders->count(),
            'revenue' => Money::round($orders->sum(fn ($o) => (float) $o->total), 2),
        ];
    }

    // --- Write tools (audited) --------------------------------------------

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function updateOrderStatus(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $status = $args['status'] ?? '';
        // R1a: enum-sourced (same six MCP-settable statuses; excludes call_bill/pending_approval).
        $mcpSettable = [
            OrderStatus::New->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
            OrderStatus::Delivered->value,
            OrderStatus::Completed->value,
            OrderStatus::Cancelled->value,
        ];
        if (! in_array($status, $mcpSettable, true)) {
            throw new ToolError(-32602, 'Invalid status.');
        }
        $order = Order::where('store_id', $store->id)->find((int) ($args['order_id'] ?? 0));
        if (! $order) {
            throw new ToolError(-32004, 'Order not found.');
        }
        $order->status = $status;
        $order->save();
        log_activity('order.status', $order, ['status' => $status, 'order_id' => $order->id, 'via' => 'mcp'], $store->id);

        return ['id' => $order->id, 'status' => $status];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function addMenuItem(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            throw new ToolError(-32602, 'Name is required.');
        }

        $categoryId = null;
        if (! empty($args['category_id'])) {
            $cat = MenuCategory::where('store_id', $store->id)->find((int) $args['category_id']);
            if (! $cat) {
                // P4-11: a category that is not this store's (another account's, or gone) is refused like update_category does,
                // not silently dropped into an uncategorised item.
                throw new ToolError(-32004, 'Category not found.');
            }
            $categoryId = $cat->id;
        }

        /* F7-9: one canonical counter — this tool used to count items
           per-restaurant while the dashboard counted per-category, so the same
           plan limit gave two different answers. Both now share
           MenuAdminService::usedFor (per (restaurant, category) bucket). */
        $limit = effective_limit('menu', $store->user);
        if (! Entitlements::isUnlimitedSentinel($limit)
            && MenuAdminService::usedFor((int) $store->id, 'menu', $categoryId) >= $limit) { // P6-1
            throw new ToolError(-32005, 'Plan menu-item limit reached.');
        }

        $item = Menu::create(array_merge([
            'store_id' => $store->id,
            'category_id' => $categoryId,
            'name' => mb_substr($name, 0, 150),
            'description' => mb_substr(trim((string) ($args['description'] ?? '')), 0, 1000),
            // P1-05 10b: a catalogue price keeps the store currency's own decimals (KWD fils were cut to 2).
            'price' => Money::round((float) ($args['price'] ?? 0), Currencies::exponent(store_currency((int) $store->id))),
            'active' => '1',
        ], $this->goodsWriteFields($store, $args)));
        log_activity('menu.create', $item, ['item_id' => $item->id, 'via' => 'mcp'], $store->id);

        return $this->menuWriteResult($item);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function updateMenuItem(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $item = Menu::where('store_id', $store->id)->find((int) ($args['item_id'] ?? 0));
        if (! $item) {
            throw new ToolError(-32004, 'Item not found.');
        }

        if (array_key_exists('name', $args) && trim((string) $args['name']) !== '') {
            $item->name = mb_substr(trim((string) $args['name']), 0, 150);
        }
        if (array_key_exists('description', $args)) {
            $item->description = mb_substr(trim((string) $args['description']), 0, 1000);
        }
        if (array_key_exists('price', $args)) {
            $item->price = Money::round((float) $args['price'], Currencies::exponent($item->minorMoneyCurrency()));
        }
        foreach ($this->goodsWriteFields($store, $args) as $k => $v) {
            $item->{$k} = $v;
        }
        $item->save();
        log_activity('menu.update', $item, ['item_id' => $item->id, 'via' => 'mcp'], $store->id);

        return $this->menuWriteResult($item);
    }

    /**
     * rev/mv-surface-api P1: the goods/OTC write fields a non-food store may set
     * on an item via MCP, cast + bounded like MenuAdminService's rules. Empty for
     * a food store (it has no such fields), so add/update_menu_item stay
     * food-shaped for restaurants. Brand ownership is enforced. The agent-facing
     * `sku` maps to the item's `plu` (barcode) column.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function goodsWriteFields(Store $store, array $args): array
    {
        if ($store->isFoodVertical()) {
            return [];
        }

        $out = [];
        if (array_key_exists('brand_id', $args) && $args['brand_id'] !== null && $args['brand_id'] !== '') {
            MenuAdminService::assertBrandOwned((int) $store->id, (int) $args['brand_id']);
            $out['brand_id'] = (int) $args['brand_id'];
        }
        if (array_key_exists('weight', $args) && is_numeric($args['weight'])) {
            $out['weight'] = (float) $args['weight'];
        }
        if (array_key_exists('pricing_unit', $args) && $args['pricing_unit'] !== null) {
            $out['pricing_unit'] = mb_substr((string) $args['pricing_unit'], 0, 20);
        }
        if (array_key_exists('sku', $args) && $args['sku'] !== null) {
            $out['plu'] = mb_substr((string) $args['sku'], 0, 64);
        }
        if (array_key_exists('track_inventory', $args)) {
            $out['track_inventory'] = (bool) $args['track_inventory'];
        }
        if (array_key_exists('stock_qty', $args) && is_numeric($args['stock_qty'])) {
            $out['stock_qty'] = max(0, (int) $args['stock_qty']);
        }
        if (array_key_exists('age_restricted', $args)) {
            $out['age_restricted'] = (bool) $args['age_restricted'];
        }
        if (array_key_exists('batch_no', $args) && $args['batch_no'] !== null) {
            $out['batch_no'] = mb_substr((string) $args['batch_no'], 0, 64);
        }
        if (array_key_exists('expiry_date', $args) && $args['expiry_date'] !== null && $args['expiry_date'] !== '') {
            $out['expiry_date'] = (string) $args['expiry_date'];
        }

        return $out;
    }

    /**
     * The add/update_menu_item result — id/name/price plus the stock level when
     * the item tracks inventory, so a goods write echoes what it set.
     *
     * @return array<string,mixed>
     */
    private function menuWriteResult(Menu $item): array
    {
        $res = ['id' => $item->id, 'name' => $item->name, 'price' => (float) $item->price];
        if ($item->track_inventory) {
            $res['stock_qty'] = $item->stock_qty !== null ? (int) $item->stock_qty : null;
        }

        return $res;
    }

    // --- Category tools (P1-2) --------------------------------------------

    /**
     * Validate tool args against Laravel rules; a failure is a -32602 error.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $rules
     * @return array<int|string, mixed>
     */
    private function validated(array $args, array $rules): array
    {
        $v = Validator::make($args, $rules);
        if ($v->fails()) {
            throw new ToolError(-32602, $v->errors()->first());
        }

        return $v->validated();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listCategories(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);

        return MenuCategory::where('store_id', $store->id)->orderBy('position')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'position' => (int) $c->position,
                'active' => (bool) $c->active,
                'items_count' => Menu::where('store_id', $store->id)->where('category_id', $c->id)->count(),
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function addCategory(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $data = $this->validated($args, MenuAdminService::categoryRules(true));

        // F1-2: the same plan-limit check add_menu_item has always had. The
        // service enforces it too; doing it here turns the refusal into a proper
        // MCP error instead of a generic tool failure.
        try {
            MenuAdminService::assertWithinLimit($store->id, 'category');
        } catch (PlanLimitReachedException $e) {
            throw new ToolError(-32005, 'Plan category limit reached.');
        }

        $cat = MenuAdminService::createCategory($store->id, $data);
        log_activity('menu.category.create', $cat, ['category_id' => $cat->id, 'via' => 'mcp'], $store->id);

        return ['id' => $cat->id, 'name' => $cat->name];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function updateCategory(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $cat = MenuCategory::where('store_id', $store->id)->find((int) ($args['category_id'] ?? 0));
        if (! $cat) {
            throw new ToolError(-32004, 'Category not found or not yours.');
        }
        $data = $this->validated($args, MenuAdminService::categoryRules(false));
        // store_id / category_id are not category columns — never persist them.
        unset($data['store_id'], $data['category_id']);
        $cat = MenuAdminService::updateCategory($cat, $data);
        log_activity('menu.category.update', $cat, ['category_id' => $cat->id, 'via' => 'mcp'], $store->id);

        return ['id' => $cat->id, 'name' => $cat->name];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function deleteCategory(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $cat = MenuCategory::where('store_id', $store->id)->find((int) ($args['category_id'] ?? 0));
        if (! $cat) {
            throw new ToolError(-32004, 'Category not found or not yours.');
        }
        $mode = in_array($args['mode'] ?? null, ['cascade', 'reparent'], true) ? $args['mode'] : 'refuse';
        $result = MenuAdminService::deleteCategory($cat, $mode);
        if (! $result['ok']) {
            if ($result['reason'] === 'has_subcategories') {
                throw new ToolError(-32006, "Category has {$result['subcategories']} subcategories — pass mode 'cascade' to delete them too, or 'reparent' to move them to the top level.");
            }
            throw new ToolError(-32006, 'Category still has items — move or delete them before deleting the category.');
        }
        log_activity('menu.category.delete', $store, ['category_id' => (int) $args['category_id'], 'via' => 'mcp'], $store->id);

        return ['deleted' => true, 'id' => (int) $args['category_id']];
    }

    // --- Item tools (P1-3) ------------------------------------------------

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function deleteMenuItem(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $item = Menu::where('store_id', $store->id)->find((int) ($args['item_id'] ?? 0));
        if (! $item) {
            throw new ToolError(-32004, 'Item not found or not yours.');
        }
        $id = $item->id;
        $item->delete();
        log_activity('menu.delete', $store, ['item_id' => $id, 'via' => 'mcp'], $store->id);

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function setItemAvailability(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $item = Menu::where('store_id', $store->id)->find((int) ($args['item_id'] ?? 0));
        if (! $item) {
            throw new ToolError(-32004, 'Item not found or not yours.');
        }
        if (! array_key_exists('available', $args)) {
            throw new ToolError(-32602, 'available (boolean) is required.');
        }
        $available = filter_var($args['available'], FILTER_VALIDATE_BOOLEAN);
        // Reuse the shared 86 toggle; sold_out is the inverse of availability.
        $item = MenuAdminService::toggleSoldOut($item, ! $available);
        log_activity('menu.availability', $item, ['item_id' => $item->id, 'is_sold_out' => (bool) $item->is_sold_out, 'via' => 'mcp'], $store->id);

        return ['id' => $item->id, 'available' => ! (bool) $item->is_sold_out, 'is_sold_out' => (bool) $item->is_sold_out];
    }

    // --- Customer + analytics tools (P1-5) --------------------------------

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listCustomers(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $limit = min(100, max(1, (int) ($args['limit'] ?? 30)));
        $search = trim((string) ($args['search'] ?? ''));

        return Customer::where('store_id', $store->id)
            ->when($search !== '', fn ($q) => $q->whereSearch($search)) // P1-17: Scout database search
            ->orderByRaw('last_order_at IS NULL')->orderByDesc('last_order_at')->limit($limit)->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'email' => $c->email,
                'orders_count' => (int) $c->orders_count,
                'total_spent' => (float) $c->total_spent,
                'last_order_at' => optional($c->last_order_at)->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function getCustomer(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $c = Customer::where('store_id', $store->id)->find((int) ($args['customer_id'] ?? 0));
        if (! $c) {
            throw new ToolError(-32004, 'Customer not found or not yours.');
        }

        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'orders_count' => (int) $c->orders_count,
            'total_spent' => (float) $c->total_spent,
            'last_order_at' => optional($c->last_order_at)->toIso8601String(),
            'tags' => $c->tags,
            'created_at' => optional($c->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function salesReport(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);

        // Reuse the REST analytics service — one revenue source, no re-summing.
        return SalesAnalyticsService::summary($store->id, $args['from'] ?? null, $args['to'] ?? null);
    }

    // --- Coupon tools (P1-9, decision A) ----------------------------------

    /** BL-036 C7: the owner's first store — its timezone reads a coupon's date-only expiry (as the owner form does). */
    private function couponStoreId(int $userId): ?int
    {
        return Store::where('user_id', $userId)->orderBy('id')->value('id');
    }

    private function couponTz(int $userId): string
    {
        return StoreClock::tz($this->couponStoreId($userId));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function couponArray(Coupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'type' => $c->coupon_type,
            'value' => (float) $c->percentage,
            'limit' => (int) $c->limit,
            'used' => (int) $c->used,
            'min_order_amount' => $c->min_order_amount !== null ? (float) $c->min_order_amount : null,
            'expiry_at' => StoreClock::localDate($this->couponStoreId((int) $c->user_id), $c->expiry_at), // BL-036 C7: store-local date
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listCoupons(int $userId, array $args): array
    {
        $limit = min(100, max(1, (int) ($args['limit'] ?? 50)));

        return Coupon::where('user_id', $userId)->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($c) => $this->couponArray($c))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function createCoupon(int $userId, array $args): array
    {
        // Pricing follow-ups (PRICING.md §3): creating a coupon needs a plan that includes coupons.
        if (! PlanGate::allows(User::query()->find($userId), PlanGate::COUPONS)) {
            throw new ToolError(ToolError::NOT_ENTITLED, PlanGate::upsell(PlanGate::COUPONS));
        }

        $data = $this->validated($args, [
            'code' => ['required', 'string', 'max:20'],
            'coupon_type' => ['required', 'in:percentage,fixed'],
            // A percent 0 < x <= 100 (3 decimals) or an amount > 0 in the owner's currency decimals.
            'value' => ['required', ...Coupon::valueRules((string) ($args['coupon_type'] ?? ''), (new Coupon(['user_id' => $userId]))->defaultMinorMoneyCurrency())],
            'limit' => ['nullable', 'integer', 'min:1'],
            'expiry_at' => Coupon::expiryDateRules($this->couponTz($userId)), // BL-036 C7
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $code = strtoupper(trim($data['code']));
        if (Coupon::codeTaken($code, $userId)) { // P6-215: per owner
            throw new ToolError(-32602, 'Coupon code already exists.');
        }

        $c = Coupon::create([
            'code' => $code,
            'coupon_type' => $data['coupon_type'],
            'percentage' => $data['value'],
            'limit' => $data['limit'] ?? 9999,
            'used' => 0,
            'expiry_at' => Coupon::expiryEndOfDay($data['expiry_at'] ?? null, $this->couponTz($userId)),
            'min_order_amount' => $data['min_order_amount'] ?? null,
            'user_id' => $userId,
            'issuer_scope' => 'owner',
            'applies_to' => 'food_order',
        ]);
        log_activity('coupon.create', $c, ['coupon_id' => $c->id, 'via' => 'mcp']);

        return $this->couponArray($c);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function updateCoupon(int $userId, array $args): array
    {
        $c = Coupon::where('user_id', $userId)->find((int) ($args['coupon_id'] ?? 0));
        if (! $c) {
            throw new ToolError(-32004, 'Coupon not found or not yours.');
        }
        $data = $this->validated($args, [
            'coupon_id' => ['required', 'integer'],
            'code' => ['sometimes', 'string', 'max:20'],
            'coupon_type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', ...Coupon::valueRules((string) ($args['coupon_type'] ?? $c->coupon_type), $c->defaultMinorMoneyCurrency())],
            'limit' => ['sometimes', 'integer', 'min:1'],
            'expiry_at' => ['sometimes', ...Coupon::expiryDateRules($this->couponTz($userId), StoreClock::localDate($this->couponStoreId($userId), $c->expiry_at))], // BL-036 C7
            'min_order_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $update = [];
        if (isset($data['code'])) {
            $code = strtoupper(trim($data['code']));
            if (Coupon::codeTaken($code, $userId, null, (int) $c->id)) {
                throw new ToolError(-32602, 'Coupon code already exists.');
            }
            $update['code'] = $code;
        }
        if (isset($data['coupon_type'])) {
            $update['coupon_type'] = $data['coupon_type'];
        }
        if (array_key_exists('value', $data)) {
            $update['percentage'] = $data['value'];
        }
        if (array_key_exists('limit', $data)) {
            $update['limit'] = $data['limit'];
        }
        if (array_key_exists('expiry_at', $data)) {
            // BL-036 re-review 5: the stored date sent back unchanged keeps the stored moment (no re-stamp), like the forms.
            $update['expiry_at'] = ($data['expiry_at'] !== null && $data['expiry_at'] === StoreClock::localDate($this->couponStoreId($userId), $c->expiry_at))
                ? $c->expiry_at
                : Coupon::expiryEndOfDay($data['expiry_at'], $this->couponTz($userId));
        }
        if (array_key_exists('min_order_amount', $data)) {
            $update['min_order_amount'] = $data['min_order_amount'];
        }
        $c->update($update);
        log_activity('coupon.update', $c, ['coupon_id' => $c->id, 'via' => 'mcp']);

        return $this->couponArray($c->fresh());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function deleteCoupon(int $userId, array $args): array
    {
        $c = Coupon::where('user_id', $userId)->find((int) ($args['coupon_id'] ?? 0));
        if (! $c) {
            throw new ToolError(-32004, 'Coupon not found or not yours.');
        }
        $id = $c->id;
        $c->delete();
        log_activity('coupon.delete', null, ['coupon_id' => $id, 'via' => 'mcp']);

        return ['deleted' => true, 'id' => $id];
    }

    // --- Settings tools (P1-6) --------------------------------------------

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function getRestaurantSettings(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);

        return array_merge([
            'id' => $store->id,
            'name' => $store->title,
            'address' => $store->address,
            'phone' => $store->phone,
            'business_hours' => $store->timing,
            'currency' => store_options($store->id, 'currency_code'),
        ], $this->verticalSummary($store->store_type ?? null), [
            'ordering' => [
                'online_ordering_enabled' => whatsapp_ordering_enabled($store->id),
                'delivery_charge' => (float) store_options($store->id, 'restaurant_delivery_charge', 0),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function updateBusinessHours(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $data = $this->validated($args, ['business_hours' => 'required|string|max:255']);

        $store->timing = $data['business_hours'];
        $store->save();
        log_activity('store.hours', $store, ['via' => 'mcp'], $store->id);

        return ['id' => $store->id, 'business_hours' => $store->timing];
    }

    // --- Vertical-scoped catalog/fulfillment tools (fix 1b) -----------------
    // Every handler below is READ-only and gated by the 'multi_vertical'
    // `feature` on its registry entry (checked in callTool() before the
    // handler ever runs), so this section is a no-op for any token whose
    // owner does not hold the Multi-Vertical Stores entitlement.

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listBrands(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);

        return Brand::where('store_id', $store->id)->orderBy('position')->orderBy('name')->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'active' => (bool) $b->active,
                'position' => (int) $b->position,
                'items_count' => Menu::where('store_id', $store->id)->where('brand_id', $b->id)->count(),
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listVariants(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $item = Menu::where('store_id', $store->id)->find((int) ($args['item_id'] ?? 0));
        if (! $item) {
            throw new ToolError(-32004, 'Item not found or not yours.');
        }

        return MenuVariant::where('menu_id', $item->id)->orderBy('position')->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'options' => $v->axes_label,
                'stock_qty' => $v->stock_qty,
                'price' => (float) $v->price,
            ])->values()->all();
    }

    /**
     * rev/mv-surface-api: add_variant — create a variant on an owned goods item.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function addVariant(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $item = Menu::where('store_id', $store->id)->find((int) ($args['item_id'] ?? 0));
        if (! $item) {
            throw new ToolError(-32004, 'Item not found or not yours.');
        }
        $options = $args['options'] ?? null;
        if (! is_array($options) && ! is_object($options)) {
            throw new ToolError(-32602, 'options (the axis map, e.g. {"Size":"M"}) is required.');
        }

        $v = MenuVariant::create([
            'menu_id' => $item->id,
            'options' => (array) $options,
            'price' => Money::round((float) ($args['price'] ?? 0), Currencies::exponent(store_currency((int) $store->id))),
            'sku' => isset($args['sku']) ? mb_substr((string) $args['sku'], 0, 64) : null,
            'barcode' => isset($args['barcode']) ? mb_substr((string) $args['barcode'], 0, 64) : null,
            'stock_qty' => isset($args['stock_qty']) ? max(0, (int) $args['stock_qty']) : null,
            'active' => 1,
        ]);
        log_activity('menu.variant_created', $item, ['variant_id' => $v->id, 'via' => 'mcp'], $store->id);

        return $this->variantRow($v->fresh());
    }

    /**
     * update_variant — edit an owned variant's price/sku/barcode/stock/active.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function updateVariant(int $userId, array $args): array
    {
        $v = $this->ownedVariant($userId, $args);
        if (array_key_exists('price', $args)) {
            $v->price = Money::round((float) $args['price'], Currencies::exponent($v->minorMoneyCurrency()));
        }
        if (array_key_exists('sku', $args)) {
            $v->sku = $args['sku'] !== null ? mb_substr((string) $args['sku'], 0, 64) : null;
        }
        if (array_key_exists('barcode', $args)) {
            $v->barcode = $args['barcode'] !== null ? mb_substr((string) $args['barcode'], 0, 64) : null;
        }
        if (array_key_exists('stock_qty', $args)) {
            $v->stock_qty = $args['stock_qty'] !== null ? max(0, (int) $args['stock_qty']) : null;
        }
        if (array_key_exists('active', $args)) {
            $v->active = (bool) $args['active'];
        }
        $v->save();

        return $this->variantRow($v->fresh());
    }

    /**
     * delete_variant — remove an owned variant.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function deleteVariant(int $userId, array $args): array
    {
        $v = $this->ownedVariant($userId, $args);
        $id = $v->id;
        $v->delete();

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * Resolve a variant by id, scoped to the token owner's store (a variant is
     * reached only through its parent Menu item → restaurant → owner).
     *
     * @param  array<string, mixed>  $args
     */
    private function ownedVariant(int $userId, array $args): MenuVariant
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $v = MenuVariant::find((int) ($args['variant_id'] ?? 0));
        if (! $v || ! Menu::where('store_id', $store->id)->where('id', $v->menu_id)->exists()) {
            throw new ToolError(-32004, 'Variant not found or not yours.');
        }

        return $v;
    }

    /** @return array<string, mixed> */
    private function variantRow(MenuVariant $v): array
    {
        return [
            'id' => $v->id,
            'sku' => $v->sku,
            'barcode' => $v->barcode,
            'options' => $v->axes_label,
            'stock_qty' => $v->stock_qty,
            'price' => (float) $v->price,
            'active' => (bool) $v->active,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listShipments(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $limit = min(100, max(1, (int) ($args['limit'] ?? 30)));

        return Shipment::whereHas('order', fn ($q) => $q->where('store_id', $store->id))
            ->when($args['order_id'] ?? null, fn ($q, $id) => $q->where('order_id', (int) $id))
            ->when($args['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($s) => [
                'order_id' => $s->order_id,
                'status' => $s->status,
                'carrier' => $s->carrier,
                'tracking_number' => $s->tracking_number,
                'shipped_at' => optional($s->shipped_at)->toIso8601String(),
                'delivered_at' => optional($s->delivered_at)->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listFitments(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $item = Menu::where('store_id', $store->id)->find((int) ($args['item_id'] ?? 0));
        if (! $item) {
            throw new ToolError(-32004, 'Item not found or not yours.');
        }

        return VehicleFitment::where('menu_id', $item->id)->orderBy('year')->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'year' => $f->year,
                'make' => $f->make,
                'model' => $f->model,
                'submodel' => $f->submodel,
                'engine' => $f->engine,
                'label' => $f->label,
                'notes' => $f->notes,
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int|string, mixed>
     */
    private function listExpiringItems(int $userId, array $args): array
    {
        $store = $this->ownedRestaurant($userId, $args['store_id'] ?? 0);
        $days = min(365, max(1, (int) ($args['days'] ?? 30)));

        $row = fn (Menu $m) => [
            'id' => $m->id,
            'name' => $m->getRawOriginal('name'),
            'batch_no' => $m->batch_no,
            'expiry_date' => optional($m->expiry_date)->toDateString(),
            'age_restricted' => (bool) $m->age_restricted,
        ];

        return [
            'expired' => Menu::where('store_id', $store->id)->expired()
                ->orderBy('expiry_date')->get()->map($row)->values()->all(),
            'expiring_soon' => Menu::where('store_id', $store->id)->expiringWithin($days)
                ->orderBy('expiry_date')->get()->map($row)->values()->all(),
        ];
    }
}
