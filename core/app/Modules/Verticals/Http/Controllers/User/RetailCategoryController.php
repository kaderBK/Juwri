<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Exceptions\PlanLimitReachedException;
use App\Models\MenuCategory;
use App\Services\Entitlements;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Services\MenuAdminService;

/**
 * rev/multi-vertical Lane A5 — catalog category management, vocab-relabeled.
 *
 * `menu_categories` is already generic (BRAINSTORM §4) — this is a thin,
 * retail-surface-only wrapper over {@see MenuAdminService}'s category CRUD
 * (the same engine the REST/staff-app paths already share), so it never forks
 * category persistence logic. Nesting is the existing `parent` self-FK, two
 * levels deep, same as the restaurant menu editor.
 */
class RetailCategoryController extends Controller
{
    /** rev/multi-vertical Lane V (V4): same multi_vertical manage-surface gate as RetailItemController. */
    private function authorizeStore(Request $request, Store $store): void
    {
        abort_unless((int) $store->user_id === (int) $request->user()->id, 404);
        // goods stores (retail form) and service stores (laundry): a service store has no other place to create the
        // categories its storefront groups services by — an uncategorised service never shows on the storefront.
        abort_unless(in_array(app(VerticalRegistry::class)->itemForm($store->store_type), ['retail', 'service'], true), 404);
        abort_unless(Entitlements::allows($request->user(), 'multi_vertical'), 403);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:180'],
            'parent' => ['nullable', 'integer'],
        ])->validate();

        if (! empty($v['parent'])) {
            abort_unless(MenuCategory::where('id', $v['parent'])->where('store_id', $store->id)->exists(), 422);
        }

        try {
            MenuAdminService::createCategory((int) $store->id, array_filter($v));
        } catch (PlanLimitReachedException $e) {
            app_alert_error(___($e->getMessage()));

            return back();
        }

        app_alert_success(___('Saved Successfully'));

        return back();
    }

    public function update(Request $request, Store $store, MenuCategory $category): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $category->store_id === (int) $store->id, 404);

        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:180'],
        ])->validate();

        MenuAdminService::updateCategory($category, $v);
        app_alert_success(___('Saved Successfully'));

        return back();
    }

    public function destroy(Request $request, Store $store, MenuCategory $category): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $category->store_id === (int) $store->id, 404);

        $result = MenuAdminService::deleteCategory($category, 'reparent', forceItems: true);
        if (! $result['ok']) {
            app_alert_error(___('Could not delete this category.'));

            return back();
        }

        app_alert_success(___('Deleted Successfully'));

        return back();
    }
}
