<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Modules\Menu\Models\BranchMenu;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Core\Models\Store;
use Illuminate\Http\Request;

class BranchMenuController extends Controller
{
    private function ownerRestaurantIds(): array
    {
        return Store::where('user_id', auth()->id())->pluck('id')->toArray();
    }

    private function ownedBranch(int $branchId): Branch
    {
        return Branch::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($branchId);
    }

    /** Per-branch menu grid: availability toggle + price override for each item. */
    public function edit(int $branch)
    {
        $model = $this->ownedBranch($branch);

        $menus = Menu::where('store_id', $model->store_id)
            ->with(['branchSettings' => fn ($q) => $q->where('branch_id', $model->id)])
            ->orderBy('name')
            ->get();

        return view('owner.branches.menu', [
            'branch' => $model,
            'menus'  => $menus,
        ]);
    }

    /** Save one item's availability + price override at this branch. */
    public function update(Request $request, int $branch)
    {
        $model = $this->ownedBranch($branch);

        $data = $request->validate([
            'menu_id'   => ['required', 'integer'],
            'available' => ['nullable', 'boolean'],
            'price'     => ['nullable', 'numeric', 'min:0'],
        ]);

        // Item must belong to the branch's restaurant.
        $menu = Menu::where('store_id', $model->store_id)->findOrFail($data['menu_id']);

        BranchMenu::updateOrCreate(
            ['branch_id' => $model->id, 'menu_id' => $menu->id],
            [
                'available' => $request->boolean('available'),
                'price'     => ($data['price'] === null || $data['price'] === '') ? null : $data['price'],
            ]
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('Saved.'));
    }
}
