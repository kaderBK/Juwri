<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Models\VehicleFitment;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * rev/multi-vertical Lane E2/E5 — public JSON endpoints the `shelf` storefront
 * theme's YMM filter + OEM search widgets call (see the fitment-filter block
 * this lane adds to store_templates/shelf/index.blade.php, gated on the
 * `fitment` capability so every other vertical never renders or calls this).
 *
 * Both actions return `enabled:false` (200, not 404) for a non-carparts store
 * so a stray client-side call from a mis-cached page degrades quietly.
 */
class FitmentPublicController extends Controller
{
    private function store(string $slug): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    /** GET {slug}/parts/fitment-options — the store's whole flat fitment list (E2). */
    public function options(string $slug): JsonResponse
    {
        $store = $this->store($slug);
        if (! app(VerticalRegistry::class)->has($store->store_type, 'fitment')) {
            return response()->json(['enabled' => false, 'fitments' => []]);
        }

        $rows = VehicleFitment::where('store_id', $store->id)
            ->orderBy('year')
            ->get(['menu_id', 'year', 'make', 'model', 'submodel', 'engine'])
            ->map(fn (VehicleFitment $f) => [
                'menu_id' => (int) $f->menu_id,
                'year' => (int) $f->year,
                'make' => (string) $f->make,
                'model' => (string) $f->model,
                'submodel' => $f->submodel,
                'engine' => $f->engine,
            ])->values();

        return response()->json(['enabled' => true, 'fitments' => $rows]);
    }

    /** GET {slug}/parts/oem-search?q= — OEM/cross-reference number lookup (E5). */
    public function oemSearch(string $slug, Request $request): JsonResponse
    {
        $store = $this->store($slug);
        if (! app(VerticalRegistry::class)->has($store->store_type, 'fitment')) {
            return response()->json(['enabled' => false, 'menu_ids' => []]);
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['enabled' => true, 'menu_ids' => []]);
        }

        // Loaded via get()->map (never pluck()) so the `attributes` array cast
        // actually runs — pluck() reads the raw column and bypasses casts
        // ([query-pluck-bypasses-accessors]).
        $ids = Menu::where('store_id', $store->id)
            ->where('active', '1') // menu.active is enum('0','1'); int 1 matches the wrong enum index → no rows ([mysql-enum-active-index-trap])
            ->get(['id', 'attributes'])
            ->filter(function (Menu $item) use ($q) {
                $attrs = (array) ($item->attributes ?? []);
                $haystack = array_merge(
                    (array) ($attrs['oem_numbers'] ?? []),
                    (array) ($attrs['cross_refs'] ?? [])
                );

                return array_any($haystack, fn ($value) => str_contains(mb_strtolower((string) $value), $q));
            })
            ->pluck('id')
            ->values();

        return response()->json(['enabled' => true, 'menu_ids' => $ids]);
    }
}
