<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use App\Services\Entitlements;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Brand;

/**
 * rev/multi-vertical Lane A3 — per-store Brand CRUD (WORK-ITEMS A3).
 *
 * Brands are scoped to ONE store (see Brand model docblock) — there is no
 * cross-store brand catalog. Reachable from the retail dashboard nav (see the
 * "Brands" link added to the store card / catalog page) and consumed as the
 * item-form brand picker (A4).
 */
class BrandController extends Controller
{
    /** rev/multi-vertical Lane V (V4): same multi_vertical manage-surface gate as RetailItemController. */
    private function authorizeStore(Request $request, Store $store): void
    {
        abort_unless((int) $store->user_id === (int) $request->user()->id, 404);
        abort_unless(app(VerticalRegistry::class)->itemForm($store->store_type) === 'retail', 404);
        abort_unless(Entitlements::allows($request->user(), 'multi_vertical'), 403);
    }

    public function index(Request $request, Store $store, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);

        $brands = Brand::where('store_id', $store->id)
            ->withCount('items')
            ->orderBy('position')->orderBy('name')
            ->get();

        return view('owner.catalog.retail.brands', [
            'store' => $store,
            'brands' => $brands,
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
        ])->validate();

        Brand::create([
            'store_id' => $store->id,
            'name' => $v['name'],
            'slug' => Str::slug($v['name']),
            'active' => true,
        ]);

        app_alert_success(___('Saved Successfully'));

        return back();
    }

    public function update(Request $request, Store $store, Brand $brand): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $brand->store_id === (int) $store->id, 404);

        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ])->validate();

        $brand->update([
            'name' => $v['name'],
            'slug' => Str::slug($v['name']),
            'active' => $request->has('active') ? 1 : 0,
        ]);

        app_alert_success(___('Saved Successfully'));

        return back();
    }

    public function destroy(Request $request, Store $store, Brand $brand): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $brand->store_id === (int) $store->id, 404);

        // Unlink rather than cascade-delete items — a brand going away must
        // never take a catalog's products with it (menu.brand_id nullOnDelete
        // already guarantees this at the DB level; this just keeps the model
        // event path in step so any brand cache/observer sees it too).
        $brand->items()->update(['brand_id' => null]);
        $brand->delete();

        app_alert_success(___('Deleted Successfully'));

        return back();
    }
}
