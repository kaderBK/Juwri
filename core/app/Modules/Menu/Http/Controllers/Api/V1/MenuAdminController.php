<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\Api\V1;

use App\Models\MenuCategory;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuAdminService;

/**
 * Owner-token menu management (merchant app): categories and items CRUD plus a
 * dedicated sold-out toggle. Persistence lives in {@see MenuAdminService}, shared
 * with the staff app. Image uploads are out of scope for JSON — pass a hosted URL.
 */
class MenuAdminController extends Controller
{
    use OwnerScoped;

    // ── Categories ───────────────────────────────────────────────────────────

    public function storeCategory(Request $request, int $storeId)
    {
        $this->ownedRestaurant($request, $storeId);
        $data = $request->validate(MenuAdminService::categoryRules(true));
        $category = MenuAdminService::createCategory($storeId, $data);

        return response()->json(['id' => $category->id, 'name' => $category->name], 201);
    }

    public function updateCategory(Request $request, int $storeId, int $categoryId)
    {
        $this->ownedRestaurant($request, $storeId);
        $category = MenuCategory::where('store_id', $storeId)->findOrFail($categoryId);
        $category = MenuAdminService::updateCategory($category, $request->validate(MenuAdminService::categoryRules(false)));

        return response()->json(['id' => $category->id, 'name' => $category->name]);
    }

    public function deleteCategory(Request $request, int $storeId, int $categoryId)
    {
        $this->ownedRestaurant($request, $storeId);
        $category = MenuCategory::where('store_id', $storeId)->findOrFail($categoryId);

        // Refuse to orphan items OR subcategories — mirror the MCP delete_category
        // behavior. ?mode=cascade deletes the subcategories too; ?mode=reparent
        // promotes them to top-level.
        $mode = in_array($request->query('mode'), ['cascade', 'reparent'], true) ? $request->query('mode') : 'refuse';
        $result = MenuAdminService::deleteCategory($category, $mode);
        if (! $result['ok']) {
            if ($result['reason'] === 'has_subcategories') {
                return response()->json([
                    'error' => "Category has {$result['subcategories']} subcategories — pass mode=cascade to delete them too, or mode=reparent to move them to the top level.",
                    'subcategories' => $result['subcategories'],
                ], 409);
            }

            return response()->json(['error' => 'Category has items — move or delete them first.'], 409);
        }

        return response()->json(['deleted' => true]);
    }

    // ── Items ────────────────────────────────────────────────────────────────

    public function storeItem(Request $request, int $storeId)
    {
        $this->ownedRestaurant($request, $storeId);
        $item = MenuAdminService::createItem($storeId, $request->validate(MenuAdminService::itemRules(true)));

        return response()->json(MenuAdminService::itemArray($item), 201);
    }

    public function updateItem(Request $request, int $storeId, int $itemId)
    {
        $this->ownedRestaurant($request, $storeId);
        $item = Menu::where('store_id', $storeId)->findOrFail($itemId);
        $item = MenuAdminService::updateItem($item, $request->validate(MenuAdminService::itemRules(false)));

        return response()->json(MenuAdminService::itemArray($item));
    }

    public function deleteItem(Request $request, int $storeId, int $itemId)
    {
        $this->ownedRestaurant($request, $storeId);
        Menu::where('store_id', $storeId)->findOrFail($itemId)->delete();

        return response()->json(['deleted' => true]);
    }

    /** PATCH /stores/{id}/menu/items/{itemId}/sold-out — quick 86 toggle. */
    public function toggleSoldOut(Request $request, int $storeId, int $itemId)
    {
        $this->ownedRestaurant($request, $storeId);
        $item = Menu::where('store_id', $storeId)->findOrFail($itemId);
        $force = $request->has('is_sold_out') ? $request->boolean('is_sold_out') : null;
        $item = MenuAdminService::toggleSoldOut($item, $force);

        return response()->json(['id' => $item->id, 'is_sold_out' => (bool) $item->is_sold_out]);
    }
}
