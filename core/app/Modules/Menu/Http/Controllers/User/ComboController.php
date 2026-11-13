<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Services\Combos\ComboService;

/**
 * PARITY-RUN T6 — owner CRUD for combo meals.
 *
 * A combo is edited as ONE document (combo + its slots + each slot's options),
 * because half a combo is not a valid thing to save: a slot with no options can
 * never be satisfied, and an option pointing at a deleted item would break the
 * order path. `save()` therefore upserts the whole tree inside a transaction.
 *
 * The route group carries `feature:combos` — legal here, because this is a
 * dashboard-only screen. The storefront/order half checks
 * `ComboService::enabled()` in-controller instead: `feature:` middleware on a
 * route an anonymous diner reaches 403s every customer.
 */
class ComboController extends Controller
{
    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids(); // BL-036: a staff session is bound to its own store
    }

    private function assertOwnsRestaurant(int $storeId): void
    {
        if (! in_array($storeId, $this->ownerRestaurantIds(), true)) {
            abort(403);
        }
    }

    private function ownedCombo(int $id): Combo
    {
        $combo = Combo::findOrFail($id);
        $this->assertOwnsRestaurant((int) $combo->store_id);

        return $combo;
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = (int) $request->query('store_id', dashboard_current_store()->id ?? 0);
        if ($storeId && ! in_array($storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);
        if (! $storeId && $stores->count() === 1) {
            $storeId = (int) $stores->first()->id;
        }

        $combos = collect();
        $items = collect();
        $currency = '';
        if ($storeId) {
            $combos = Combo::where('store_id', $storeId)
                ->with(['slots.options.menu', 'slots.options.variant'])
                ->orderBy('position')->orderBy('id')->get();

            // The item picker. Variants ride along so a slot can offer "Cola (Large)".
            $items = Menu::where('store_id', $storeId)
                ->orderBy('name')
                ->get(['id', 'name', 'price_minor', 'currency_code', 'image'])
                ->map(function (Menu $m) {
                    return [
                        'id' => (int) $m->id,
                        'name' => (string) $m->getRawOriginal('name'),
                        'price' => (float) $m->price,
                        'variants' => MenuVariant::where('menu_id', $m->id)
                            ->orderBy('position')->get()
                            ->map(fn ($v) => [
                                'id' => (int) $v->id,
                                // menu_variants has no `name` column — the label is
                                // built from the variant's option keys.
                                'name' => ComboService::variantTitle($v) ?: ('#'.$v->id),
                                'price' => (float) $v->price,
                            ])->values()->all(),
                    ];
                })->values();

            $currency = (string) (store_options($storeId, 'currency_sign') ?: '');
        }

        // What the builder needs to show a live "sum vs bundle" preview client-side.
        $combosPayload = $combos->map(fn (Combo $c) => [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'description' => (string) ($c->description ?? ''),
            'image' => $c->image,
            'price' => (float) $c->price,
            'active' => (bool) $c->active,
            'ala_carte' => $c->alaCarteFloor(),
            'slots' => $c->slots->map(fn (ComboSlot $s) => [
                'id' => (int) $s->id,
                'title' => (string) $s->title,
                'min' => (int) $s->min_select,
                'max' => (int) $s->max_select,
                'options' => $s->options->map(fn (ComboSlotOption $o) => [
                    'id' => (int) $o->id,
                    'menu_id' => (int) $o->menu_id,
                    'variant_id' => $o->variant_id ? (int) $o->variant_id : null,
                    'name' => ComboService::componentName($o),
                    'base_price' => $o->componentPrice(),
                    'upcharge' => (float) $o->upcharge,
                    'active' => (bool) $o->active,
                ])->values()->all(),
            ])->values()->all(),
        ])->values();

        return view('owner.menu.combos', compact(
            'stores', 'storeId', 'combos', 'combosPayload', 'items', 'currency'
        ));
    }

    /**
     * Create or update one combo, slots and options included.
     *
     * Slots/options are replaced wholesale rather than diffed. Historical orders
     * are unaffected because they carry a price + component SNAPSHOT, not a
     * foreign key into this tree — which is the same reason the owner can edit a
     * combo's price without rewriting yesterday's receipts.
     */
    public function save(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'store_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'string', 'max:190'],
            'price' => ['required', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'slots' => ['required', 'array', 'min:1'],
            'slots.*.title' => ['required', 'string', 'max:190'],
            'slots.*.min' => ['nullable', 'integer', 'min:0', 'max:20'],
            'slots.*.max' => ['nullable', 'integer', 'min:0', 'max:20'],
            'slots.*.options' => ['required', 'array', 'min:1'],
            'slots.*.options.*.menu_id' => ['required', 'integer'],
            'slots.*.options.*.variant_id' => ['nullable', 'integer'],
            'slots.*.options.*.upcharge' => ['nullable', 'numeric', 'min:0'],
            'slots.*.options.*.active' => ['nullable', 'boolean'],
        ]);

        $storeId = (int) $data['store_id'];
        $this->assertOwnsRestaurant($storeId);
        // P6-59b: a combo's branch must be one of this store's branches.
        if (! empty($data['branch_id']) && ! Branch::where('store_id', $storeId)->whereKey((int) $data['branch_id'])->exists()) {
            return response()->json(['success' => false, 'message' => ___('Unknown branch.'), 'errors' => ['branch_id' => ___('Unknown branch.')]], 422);
        }

        // A slot whose max is below its min can never be satisfied — refuse it here
        // rather than letting ComboService reject every order forever after.
        foreach ($data['slots'] as $i => $slot) {
            $min = (int) ($slot['min'] ?? 1);
            $max = (int) ($slot['max'] ?? 1);
            if ($max > 0 && $max < $min) {
                return response()->json([
                    'success' => false,
                    'message' => ___('A choice group cannot ask for more picks than it allows.'),
                    'errors' => ["slots.$i.max" => ___('A choice group cannot ask for more picks than it allows.')],
                ], 422);
            }
            if ($min > count($slot['options'])) {
                return response()->json([
                    'success' => false,
                    'message' => ___('A choice group needs at least as many options as it asks for.'),
                    'errors' => ["slots.$i.options" => ___('A choice group needs at least as many options as it asks for.')],
                ], 422);
            }
        }

        // Every referenced item must belong to THIS store — a combo must not be a
        // way to sell another tenant's menu.
        $menuIds = collect($data['slots'])->pluck('options')->flatten(1)->pluck('menu_id')
            ->map(fn ($id) => (int) $id)->unique();
        $ownMenu = Menu::where('store_id', $storeId)->whereIn('id', $menuIds)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        if ($menuIds->diff($ownMenu)->isNotEmpty()) {
            abort(403);
        }

        $combo = DB::transaction(function () use ($data, $storeId, $request) {
            if (! empty($data['id'])) {
                $combo = $this->ownedCombo((int) $data['id']);
            } else {
                $combo = new Combo;
                $combo->store_id = $storeId;
                $combo->position = (int) Combo::where('store_id', $storeId)->max('position') + 1;
            }

            $combo->fill([
                'branch_id' => $data['branch_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'image' => $data['image'] ?? null,
                'price' => $data['price'],
                'active' => $request->boolean('active'),
            ])->save();

            // Replace the tree.
            ComboSlotOption::where('combo_id', $combo->id)->delete();
            ComboSlot::where('combo_id', $combo->id)->delete();

            foreach (array_values($data['slots']) as $sIdx => $slot) {
                $row = ComboSlot::create([
                    'combo_id' => $combo->id,
                    'store_id' => $storeId,
                    'title' => $slot['title'],
                    'min_select' => (int) ($slot['min'] ?? 1),
                    'max_select' => (int) ($slot['max'] ?? 1),
                    'position' => $sIdx,
                ]);

                foreach (array_values($slot['options']) as $oIdx => $opt) {
                    // A variant must belong to the item it is offered on.
                    $variantId = ! empty($opt['variant_id'])
                        ? (int) (MenuVariant::where('menu_id', (int) $opt['menu_id'])->where('id', (int) $opt['variant_id'])->value('id') ?: 0)
                        : 0;

                    ComboSlotOption::create([
                        'combo_slot_id' => $row->id,
                        'combo_id' => $combo->id,
                        'store_id' => $storeId,
                        'menu_id' => (int) $opt['menu_id'],
                        'variant_id' => $variantId ?: null,
                        'upcharge' => (float) ($opt['upcharge'] ?? 0),
                        'active' => ! array_key_exists('active', $opt) || (bool) $opt['active'],
                        'position' => $oIdx,
                    ]);
                }
            }

            return $combo;
        });

        return response()->json([
            'success' => true,
            'message' => ___('Saved Successfully'),
            'id' => (int) $combo->id,
        ]);
    }

    public function destroy(Request $request, $combo)
    {
        $combo = $this->ownedCombo((int) $combo);

        DB::transaction(function () use ($combo) {
            ComboSlotOption::where('combo_id', $combo->id)->delete();
            ComboSlot::where('combo_id', $combo->id)->delete();
            $combo->delete();
        });

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => ___('Deleted Successfully')]);
        }

        return back()->with('success', ___('Deleted Successfully'));
    }

    /** Flip a combo on/off without opening the builder. */
    public function toggle(Request $request, $combo)
    {
        $combo = $this->ownedCombo((int) $combo);
        $combo->update(['active' => ! $combo->active]);

        return response()->json(['success' => true, 'active' => (bool) $combo->active]);
    }

    public function reorder(Request $request)
    {
        $ids = (array) $request->get('position', []);
        $owned = $this->ownerRestaurantIds();
        foreach ($ids as $i => $id) {
            $combo = Combo::find((int) $id);
            if ($combo && in_array((int) $combo->store_id, $owned, true)) {
                $combo->update(['position' => $i]);
            }
        }

        return response()->json(['success' => true]);
    }
}
