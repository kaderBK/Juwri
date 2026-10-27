<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

/**
 * rev/mv-surface-api P2 (mcp-fusion): read-only owner-token access to an
 * item's product variants — the size×color matrix with its own
 * sku/stock_qty/barcode (DECISIONS A2, migration
 * 2026_09_10_620200_add_stock_to_menu_variants_table). Gated by `menu:read` +
 * `feature:multi_vertical` at the route — the SAME pairing as the MCP
 * server's `list_variants` tool (OwnerTools::listVariants), and this
 * controller's row() below deliberately reuses its field names
 * (sku/barcode/stock_qty/price) so a partner reads identical variant data
 * whether they call this REST endpoint or the MCP tool.
 *
 * MenuVariant has no `store_id` of its own — it is reached only through
 * its parent Menu item (see MenuVariant::menuCacheRestaurantId()), so both
 * actions resolve+scope the item to the owner's store FIRST, then the
 * variant to that item — never a bare MenuVariant::find().
 */
class VariantApiController extends Controller
{
    use CapsPerPage, OwnerScoped;

    /** GET /stores/{id}/menu/items/{itemId}/variants */
    public function index(Request $request, int $storeId, int $itemId): JsonResponse
    {
        $store = $this->ownedRestaurant($request, $storeId);
        $item = Menu::where('store_id', $store->id)->findOrFail($itemId);

        $variants = MenuVariant::where('menu_id', $item->id)
            ->orderBy('position')
            ->orderBy('id')
            ->paginate($this->perPage($request, 50));

        return response()->json($variants->through(fn (MenuVariant $v) => $this->row($v)));
    }

    /** GET /stores/{id}/menu/items/{itemId}/variants/{variantId} */
    public function show(Request $request, int $storeId, int $itemId, int $variantId): JsonResponse
    {
        $store = $this->ownedRestaurant($request, $storeId);
        $item = Menu::where('store_id', $store->id)->findOrFail($itemId);
        $variant = MenuVariant::where('menu_id', $item->id)->findOrFail($variantId);

        return response()->json($this->row($variant));
    }

    /**
     * POST /stores/{id}/menu/items/{itemId}/variants — create a variant
     * (menu:write + feature:multi_vertical). rev/mv-surface-api: the write twin
     * of index/show, so an API/MCP client can populate a goods item's size×color
     * matrix with per-variant sku/stock/barcode — the piece the menu-write PR
     * deliberately deferred. The variant is scoped to the owner's item first;
     * `options` is the axis map ({"Size":"M","Color":"Red"}).
     */
    public function store(Request $request, int $storeId, int $itemId): JsonResponse
    {
        $store = $this->ownedRestaurant($request, $storeId);
        $item = Menu::where('store_id', $store->id)->findOrFail($itemId);

        $data = $request->validate([
            'options' => ['required', 'array', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ]);
        $data['menu_id'] = $item->id;
        $data['active'] = $data['active'] ?? 1;

        $variant = MenuVariant::create($data);

        return response()->json($this->row($variant->fresh()), 201);
    }

    /** PUT /stores/{id}/menu/items/{itemId}/variants/{variantId} — update. */
    public function update(Request $request, int $storeId, int $itemId, int $variantId): JsonResponse
    {
        $store = $this->ownedRestaurant($request, $storeId);
        $item = Menu::where('store_id', $store->id)->findOrFail($itemId);
        $variant = MenuVariant::where('menu_id', $item->id)->findOrFail($variantId);

        $data = $request->validate([
            'options' => ['sometimes', 'array', 'min:1'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer'],
        ]);
        $variant->update($data);

        return response()->json($this->row($variant->fresh()));
    }

    /** DELETE /stores/{id}/menu/items/{itemId}/variants/{variantId}. */
    public function destroy(Request $request, int $storeId, int $itemId, int $variantId): JsonResponse
    {
        $store = $this->ownedRestaurant($request, $storeId);
        $item = Menu::where('store_id', $store->id)->findOrFail($itemId);
        $variant = MenuVariant::where('menu_id', $item->id)->findOrFail($variantId);
        $variant->delete();

        return response()->json(['deleted' => true, 'id' => $variantId]);
    }

    /** @return array<string, mixed> */
    private function row(MenuVariant $v): array
    {
        return [
            'id' => $v->id,
            // REST-only identity/detail fields, additive to the MCP list_variants shape below.
            'menu_id' => $v->menu_id,
            'active' => (bool) $v->active,
            'position' => $v->position,
            'options' => $v->options, // raw axis JSON, e.g. {"Size":"M","Color":"Red"}
            'axes_label' => $v->axes_label, // human-readable, e.g. "Size: M, Color: Red"
            // Same field names as OwnerTools::listVariants() from here down.
            // stock_qty null = not stock-tracked (a legacy pizza-size/modifier variant), never 0.
            'sku' => $v->sku,
            'barcode' => $v->barcode,
            'stock_qty' => $v->stock_qty,
            'price' => (float) $v->price,
        ];
    }
}
