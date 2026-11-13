<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\GlobalMenuCategory;
use Juwri\Modules\Menu\Models\GlobalMenuExtra;
use Juwri\Modules\Menu\Services\PlatformCatalogService;

/**
 * WI-G4 (owner side): browse the platform catalog and ADOPT entries into the
 * current store. Every adopt is ownership-scoped and copy-on-adopt.
 */
class PlatformCatalogController extends Controller
{
    public function __construct(private PlatformCatalogService $service) {}

    private function ownedStore(): Store
    {
        $store = dashboard_current_store();
        abort_unless($store, 404, ___('Pick a store first.'));
        abort_unless((int) $store->user_id === (int) auth()->id(), 403);

        return $store;
    }

    /** Owner-facing catalog page: browse the platform catalog and adopt. */
    public function index()
    {
        $store = $this->ownedStore();

        return view('owner.platform.catalog', [
            'store' => $store,
            'categories' => GlobalMenuCategory::where('active', true)->whereNull('parent_id')
                ->with('children')->orderBy('position')->get(),
            'extras' => GlobalMenuExtra::where('active', true)->orderBy('position')->get(),
        ]);
    }

    public function adoptCategory(int $id)
    {
        $store = $this->ownedStore();
        $global = GlobalMenuCategory::where('active', true)->findOrFail($id);
        $result = $this->service->adoptCategory($global, $store);

        return back()->with('success', ___('Added to your menu.')." ({$result['created']}+{$result['updated']})");
    }

    public function adoptExtra(int $id)
    {
        $store = $this->ownedStore();
        $global = GlobalMenuExtra::where('active', true)->findOrFail($id);
        $this->service->adoptExtra($global, $store);

        return back()->with('success', ___('Added to your menu.'));
    }
}
