<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\Admin;

use App\Services\Storefront\StorefrontMenuCache;
use App\Support\SearchTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

/**
 * M2 — superadmin Menu Cache panel. The storefront menu payload is keyed by a
 * per-store token held in the cache; when that token is missing the store's next
 * storefront request re-assembles the menu from the database. This panel makes that
 * state visible and lets an operator force a rebuild for one store.
 */
class MenuCacheAdminController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->get('q'));

        $stores = Store::query()
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => SearchTerm::apply($w, ['title', 'slug'], $q)))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // peek(), never version(): rendering this page must not mint tokens for
        // every store it lists — the panel would then always report "healthy".
        $tokens = [];
        foreach ($stores as $store) {
            $tokens[$store->id] = StorefrontMenuCache::peek((int) $store->id);
        }

        return view('admin.menu-cache.index', [
            'stores' => $stores,
            'tokens' => $tokens,
            'q' => $q,
            'cacheDriver' => (string) config('cache.default'),
            'ttl' => (int) config('perf.menu_cache_ttl', 300),
            'etagOn' => (bool) config('perf.menu_etag', true),
            'liveTokens' => count(array_filter($tokens, fn ($t) => $t !== null)),
        ]);
    }

    public function bust(Request $request, int $storeId): RedirectResponse
    {
        abort_unless(Store::whereKey($storeId)->exists(), 404);

        StorefrontMenuCache::bust($storeId);

        return back()->with('success', ___('Menu cache rebuilt for this store.'));
    }
}
