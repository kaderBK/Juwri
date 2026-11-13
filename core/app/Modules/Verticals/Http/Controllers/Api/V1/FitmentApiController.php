<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Models\VehicleFitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;

/**
 * rev/mv-surface-api P2 (mcp-fusion): read-only owner-token access to
 * vehicle Year/Make/Model compatibility rows for car-parts items (DECISIONS
 * A8). Gated by `menu:read` + `feature:multi_vertical` at the route — the
 * SAME pairing as the MCP server's `list_fitments` tool
 * (OwnerTools::listFitments), and this controller's row() below
 * deliberately reuses its field names so a partner reads identical fitment
 * data whether they call this REST endpoint or the MCP tool. This index
 * additionally supports item_id/year/make/model filters (the MCP tool is
 * per-item only, via its required `item_id` argument).
 *
 * Unlike MenuVariant, VehicleFitment carries its own `store_id`
 * (denormalized so YMM lookups don't need a join through menu — see the
 * model's own docblock), so it is scoped directly rather than via its item.
 */
class FitmentApiController extends Controller
{
    use CapsPerPage, OwnerScoped;

    /** GET /stores/{id}/fitments — optional item_id/year/make/model filters. */
    public function index(Request $request, int $storeId): JsonResponse
    {
        $this->ownedRestaurant($request, $storeId);

        $fitments = VehicleFitment::where('store_id', $storeId)
            ->when($request->query('item_id'), fn ($q, $v) => $q->where('menu_id', (int) $v))
            ->when($request->query('year'), fn ($q, $v) => $q->where('year', (int) $v))
            ->when($request->query('make'), fn ($q, $v) => $q->whereLike('make', "%{$v}%"))
            ->when($request->query('model'), fn ($q, $v) => $q->whereLike('model', "%{$v}%"))
            ->orderBy('year')
            ->paginate($this->perPage($request, 50));

        return response()->json($fitments->through(fn (VehicleFitment $f) => $this->row($f)));
    }

    /** GET /stores/{id}/fitments/{fitmentId} */
    public function show(Request $request, int $storeId, int $fitmentId): JsonResponse
    {
        $this->ownedRestaurant($request, $storeId);
        $fitment = VehicleFitment::where('store_id', $storeId)->findOrFail($fitmentId);

        return response()->json($this->row($fitment));
    }

    /** @return array<string, mixed> */
    private function row(VehicleFitment $f): array
    {
        return [
            'id' => $f->id,
            'menu_id' => $f->menu_id,
            'year' => $f->year,
            'make' => $f->make,
            'model' => $f->model,
            'submodel' => $f->submodel,
            'engine' => $f->engine,
            'notes' => $f->notes,
            'label' => $f->label,
        ];
    }
}
