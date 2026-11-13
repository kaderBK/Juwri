<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

/**
 * google-suite Lane B (B3): backend save path for the per-store `menu_seo_jsonld`
 * toggle — the owner opt-in that adds the schema.org MenuItem tree (dish names +
 * offers) to the storefront JSON-LD. DEFAULT OFF (exposing the menu structure in
 * public structured data is the owner's call); flipping the platform-wide default
 * is a product decision, recorded in the ledger as owner-gated.
 *
 * This is a self-contained endpoint owned entirely by Lane B — it does NOT touch
 * StructuredIntegrationsController (Lane D's file). The B3 toggle-field partial
 * (`_partials/jsonld-toggle-field.blade.php`) posts here; Lane D only @includeIf's
 * that partial onto the integrations page (see the partial's spec header).
 *
 * Ownership mirrors StructuredIntegrationsController::ownRestaurant — a store is
 * editable only by its own owner. store_options booleans are STRINGS ('1'/'0').
 */
class MenuJsonLdToggleController extends Controller
{
    private function ownRestaurant(int $storeId): Store
    {
        return Store::where('id', $storeId)->where('user_id', auth()->id())->firstOrFail();
    }

    /** Current toggle state for an owned store (programmatic read). */
    public function show(int $storeId): JsonResponse
    {
        $store = $this->ownRestaurant($storeId);

        return response()->json([
            'menu_seo_jsonld' => in_array(
                store_options($store->id, 'menu_seo_jsonld'),
                [true, 1, '1', 'true', 'on'],
                true
            ),
        ]);
    }

    /** Persist the toggle. A checkbox posts `menu_seo_jsonld=1` when on, absent when off. */
    public function save(Request $request, int $storeId): JsonResponse|RedirectResponse
    {
        $store = $this->ownRestaurant($storeId);

        // Boolean-ish accept ('1'/'true'/'on'/true → on; absent/'0' → off), stored
        // as the house STRING '1'/'0' the render side (jsonld-menu.blade.php) reads.
        $on = $request->boolean('menu_seo_jsonld');
        StoreOption::updateStoreOption($store->id, 'menu_seo_jsonld', $on ? '1' : '0');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'menu_seo_jsonld' => $on]);
        }

        return back()->with('status', __('Saved'));
    }
}
