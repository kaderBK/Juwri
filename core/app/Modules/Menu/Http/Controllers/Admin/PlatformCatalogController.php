<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Menu\Models\GlobalMenuCategory;
use Juwri\Modules\Menu\Models\GlobalMenuExtra;
use Illuminate\Http\Request;

/**
 * WI-G4 (superadmin side): curate the platform catalog. Only reachable behind
 * the `admin` middleware. Mirrors the AddonProduct / Allergy CRUD pattern.
 */
class PlatformCatalogController extends Controller
{
    public function index()
    {
        return view('admin.platform-catalog.index', [
            'categories' => GlobalMenuCategory::orderBy('position')->get(),
            'extras'     => GlobalMenuExtra::orderBy('position')->get(),
        ]);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:190',
            'parent_id' => 'nullable|integer|exists:global_menu_categories,id',
            'position'  => 'nullable|integer',
        ]);
        GlobalMenuCategory::create($data + ['active' => true]);

        return back()->with('success', ___('Category added.'));
    }

    public function storeExtra(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:190',
            'price' => 'nullable|numeric|min:0',
        ]);
        GlobalMenuExtra::create($data + ['active' => true]);

        return back()->with('success', ___('Add-on added.'));
    }

    public function destroyCategory(int $id)
    {
        GlobalMenuCategory::where('parent_id', $id)->update(['parent_id' => null]);
        GlobalMenuCategory::findOrFail($id)->delete();

        return back()->with('success', ___('Deleted.'));
    }

    public function destroyExtra(int $id)
    {
        GlobalMenuExtra::findOrFail($id)->delete();

        return back()->with('success', ___('Deleted.'));
    }
}
