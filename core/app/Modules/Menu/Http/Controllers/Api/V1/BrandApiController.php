<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Menu\Models\Brand;

/**
 * rev/mv-surface-api P2 (mcp-fusion): read-only owner-token access to a
 * store's brands (retail/cosmetic/clothing/carparts/pharmacy — DECISIONS
 * A1/A3). The route carries `feature:multi_vertical` (same alias + subject
 * rule as the existing `feature:crm_suite`/`feature:driver_app` owner-token
 * gates in apiv1.php — EnsureFeatureEnabled resolves the subject from
 * `_api_user_id`, i.e. the token owner) and the same `menu:read` ability as
 * the MCP server's `list_brands` tool (OwnerTools::listBrands) — same
 * data, same field vocabulary (id/name/slug/active/position/items_count), so
 * a partner reads identical brand rows whether they call this REST endpoint
 * or the MCP tool. Scoped to the acting owner's store via OwnerScoped,
 * matching every other Api/V1 controller in this namespace — a store with no
 * brands (a restaurant, or a goods store that hasn't created one yet) just
 * returns an empty list rather than needing an extra vertical-shape check.
 */
class BrandApiController extends Controller
{
    use CapsPerPage, OwnerScoped;

    /** GET /stores/{id}/brands */
    public function index(Request $request, int $storeId): JsonResponse
    {
        $store = $this->ownedRestaurant($request, $storeId);

        $brands = Brand::where('store_id', $store->id)
            ->orderBy('position')
            ->orderBy('id')
            ->paginate($this->perPage($request, 50));

        return response()->json($brands->through(fn (Brand $b) => $this->row($b)));
    }

    /** GET /stores/{id}/brands/{brandId} */
    public function show(Request $request, int $storeId, int $brandId): JsonResponse
    {
        $store = $this->ownedRestaurant($request, $storeId);
        $brand = Brand::where('store_id', $store->id)->findOrFail($brandId);

        return response()->json($this->row($brand));
    }

    /** @return array<string, mixed> */
    private function row(Brand $b): array
    {
        return [
            'id' => $b->id,
            // REST-only identity fields, additive to the MCP list_brands shape below.
            'store_id' => $b->store_id,
            'logo' => $b->logo,
            // Same field names as OwnerTools::listBrands() from here down.
            'name' => $b->name,
            'slug' => $b->slug,
            'active' => (bool) $b->active,
            'position' => (int) $b->position,
            'items_count' => $b->items()->count(),
        ];
    }
}
