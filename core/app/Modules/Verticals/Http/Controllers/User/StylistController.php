<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Verticals\Services\Stylist\StylistProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoints for the maison (clothing) theme's AI Style Search + Visual
 * Search. Delegates to the bound StylistProvider (mock today — matches against
 * the store's own catalog; swap for a real vision/recommender later). Always
 * returns {items:[<menu id>,...]} — the theme maps ids to its own item map and
 * degrades to a curated grid if this returns nothing.
 */
class StylistController extends Controller
{
    private function store(string $slug): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    /** POST {slug}/stylist/search — body: query. */
    public function search(string $slug, Request $request, StylistProvider $provider): JsonResponse
    {
        $store = $this->store($slug);
        $ids = $provider->suggest((string) $request->input('query', ''), (int) $store->id);

        return response()->json(['items' => $ids]);
    }

    /** POST {slug}/visual/search — body: photo (file). */
    public function visual(string $slug, Request $request, StylistProvider $provider): JsonResponse
    {
        $store = $this->store($slug);
        $file = $request->file('photo');
        // The mock only needs a stable seed, not the pixels; a real provider
        // would read the file contents here instead.
        $seed = $file ? ($file->getClientOriginalName().'|'.$file->getSize()) : null;
        $ids = $provider->visualMatch($seed, (int) $store->id);

        return response()->json(['items' => $ids]);
    }
}
