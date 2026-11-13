<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Http\Controllers\User;

use App\Models\Ingredient;
use App\Models\RecipeItem;
use App\Models\StoreOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

class RecipeController extends Controller
{
    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids(); // P6-224: a staff session is bound to its own store
    }

    /** Resolve a menu item that belongs to one of the owner's restaurants, or 404. */
    private function ownedMenu(int $menuId): Menu
    {
        return Menu::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($menuId);
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        $menus = collect();
        if ($storeId) {
            $menus = Menu::where('store_id', (int) $storeId)
                ->with('recipeItems.ingredient')
                ->orderBy('name')
                ->get();
        }

        // D10: recipe-depletion auto-86 toggle state for the current store.
        $autoRecipe86 = $storeId
            ? ((string) StoreOption::getStoreOption((int) $storeId, 'recipe_auto_86', '0') === '1')
            : false;

        return view('owner.inventory.recipes', compact('stores', 'storeId', 'menus', 'autoRecipe86'));
    }

    /** D10: toggle recipe-depletion auto-86 for a store the owner owns. */
    public function setAuto86(Request $request): RedirectResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer']]);
        if (! in_array((int) $data['store_id'], $this->ownerRestaurantIds(), true)) {
            abort(403);
        }
        StoreOption::updateStoreOption((int) $data['store_id'], 'recipe_auto_86', $request->boolean('recipe_auto_86') ? '1' : '0');

        return back()->with('success', ___('Saved.'));
    }

    public function edit(int $menu)
    {
        $item = $this->ownedMenu($menu);
        $item->load('recipeItems.ingredient');

        $ingredients = Ingredient::where('store_id', $item->store_id)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return view('owner.inventory.recipe-edit', [
            'menu' => $item,
            'ingredients' => $ingredients,
        ]);
    }

    public function store(Request $request, int $menu)
    {
        $item = $this->ownedMenu($menu);

        $data = $request->validate([
            'ingredient_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0'],
        ]);

        // Ingredient must belong to the same restaurant.
        $ingredient = Ingredient::where('store_id', $item->store_id)
            ->findOrFail($data['ingredient_id']);

        RecipeItem::updateOrCreate(
            ['menu_id' => $item->id, 'ingredient_id' => $ingredient->id],
            ['quantity' => $data['quantity']]
        );

        return back()->with('success', ___('Recipe updated.'));
    }

    public function destroy(int $menu, int $ingredient)
    {
        $item = $this->ownedMenu($menu);
        RecipeItem::where('menu_id', $item->id)->where('ingredient_id', $ingredient)->delete();

        return back()->with('success', ___('Ingredient removed from recipe.'));
    }
}
