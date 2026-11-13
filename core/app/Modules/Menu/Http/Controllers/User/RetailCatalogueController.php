<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;
use Juwri\Modules\Menu\Models\SizeGuide;
use Juwri\Modules\Menu\Services\Retail\VariantMatrix;

/**
 * P3-08 — the owner's retail catalogue tools:
 *
 *  - size guides (the size-guide content type): a measurement table per store, attached to items;
 *  - an item's variant grid (colour × size on `menu_variants`, extended — not replaced): how each option group is
 *    drawn (swatch / size / chips), a colour option's swatch, generating the missing cells, and per cell the price,
 *    compare-at price, stock, low-stock threshold, SKU, GTIN and whether it is sold.
 *
 * Tenant scoping: the dashboard actor's current store (`store_id` query, checked against the actor's stores); every
 * row is looked up inside that store (a foreign id is a 404). Scalar route ids, no model binding.
 */
class RetailCatalogueController extends Controller
{
    use ResolvesCurrentStore;

    public function sizeGuides(Request $request): View
    {
        $store = $this->currentRestaurant($request);

        return view('owner.retail.size-guides', [
            'rid' => (int) $store->id,
            'guides' => SizeGuide::query()->where('store_id', $store->id)->orderBy('position')->orderBy('id')->get(),
            'stores' => Store::query()->whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function saveSizeGuide(Request $request, ?int $guide = null): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $row = $guide !== null ? SizeGuide::query()->where('store_id', $store->id)->findOrFail($guide) : new SizeGuide(['store_id' => $store->id]);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'in:cm,in'],
            'columns' => ['required', 'string', 'max:1000'],
            'rows' => ['required', 'string', 'max:20000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable', 'boolean'],
        ]);
        $columns = $this->cells($data['columns']);
        $rows = [];
        foreach (preg_split('/\R/', $data['rows']) ?: [] as $line) {
            $cells = $this->cells($line);
            if ($cells !== []) {
                $rows[] = array_slice(array_pad($cells, count($columns), ''), 0, count($columns));
            }
        }
        if ($columns === [] || $rows === [] || count($columns) > 12 || count($rows) > 60) {
            return back()->withErrors(['rows' => ___('Enter up to 12 column names and up to 60 rows, cells separated by commas.')])->withInput();
        }
        $row->fill([
            'title' => $data['title'],
            'unit' => $data['unit'],
            'columns' => $columns,
            'rows' => $rows,
            'note' => $data['note'] ?? null,
            'active' => $request->boolean('active', true),
        ])->save();

        return back()->with('success', ___('Size guide saved.'));
    }

    public function deleteSizeGuide(Request $request, int $guide): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        DB::transaction(function () use ($store, $guide) {
            $row = SizeGuide::query()->where('store_id', $store->id)->findOrFail($guide);
            Menu::query()->where('store_id', $store->id)->where('size_guide_id', $row->id)->update(['size_guide_id' => null]);
            $row->delete();
        });

        return back()->with('success', ___('Size guide deleted.'));
    }

    public function variants(Request $request, int $menu): View
    {
        $store = $this->currentRestaurant($request);
        $item = Menu::query()->where('store_id', $store->id)->findOrFail($menu);

        return view('owner.retail.variants', [
            'rid' => (int) $store->id,
            'item' => $item,
            'currency' => strtoupper(store_currency((int) $store->id)),
            'groups' => $item->resolved_variant_options,
            'cells' => VariantMatrix::cells($item),
            'guides' => SizeGuide::query()->where('store_id', $store->id)->orderBy('position')->get(['id', 'title']),
        ]);
    }

    public function saveGroups(Request $request, int $menu): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $item = Menu::query()->where('store_id', $store->id)->findOrFail($menu);
        $data = $request->validate([
            'display' => ['nullable', 'array'],
            'display.*' => ['nullable', 'in:swatch,size,chips'],
            'swatch' => ['nullable', 'array'],
            'swatch.*' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'size_guide_id' => ['nullable', 'integer'],
        ]);
        $groupIds = $item->resolved_variant_options->pluck('id')->map(fn ($id) => (int) $id)->all();
        DB::transaction(function () use ($data, $groupIds, $item, $store) {
            foreach ((array) ($data['display'] ?? []) as $groupId => $display) {
                if (in_array((int) $groupId, $groupIds, true)) {
                    ModifierGroup::query()->where('store_id', $store->id)->whereKey((int) $groupId)->update(['display' => $display ?: null]);
                }
            }
            foreach ((array) ($data['swatch'] ?? []) as $modifierId => $swatch) {
                Modifier::query()->whereKey((int) $modifierId)->whereIn('modifier_group_id', $groupIds)
                    ->update(['swatch' => $swatch ? strtoupper($swatch) : null]);
            }
            $guideId = isset($data['size_guide_id']) && $data['size_guide_id'] !== '' ? (int) $data['size_guide_id'] : null;
            if ($guideId !== null && ! SizeGuide::query()->where('store_id', $store->id)->whereKey($guideId)->exists()) {
                $guideId = null;
            }
            $item->forceFill(['size_guide_id' => $guideId])->save();
        });

        return back()->with('success', ___('Saved.'));
    }

    public function generate(Request $request, int $menu): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $item = Menu::query()->where('store_id', $store->id)->findOrFail($menu);
        $data = $request->validate(['groups' => ['required', 'array', 'min:1', 'max:3'], 'groups.*' => ['integer']]);
        try {
            $created = VariantMatrix::generate($item, array_map('intval', $data['groups']));
        } catch (\InvalidArgumentException) {
            return back()->with('error', ___('Choose the item\'s own option groups; a grid can have at most 500 cells.'));
        }

        return back()->with('success', ___(':count variants added.', ['count' => count($created)]));
    }

    public function saveCells(Request $request, int $menu): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $item = Menu::query()->where('store_id', $store->id)->findOrFail($menu);
        $data = $request->validate([
            'cells' => ['required', 'array', 'max:500'],
            'cells.*.price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'cells.*.compare_at' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'cells.*.stock_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'cells.*.low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'cells.*.sku' => ['nullable', 'string', 'max:64'],
            'cells.*.gtin' => ['nullable', 'regex:/^(\d{8}|\d{12}|\d{13}|\d{14})$/'],
            'cells.*.active' => ['nullable', 'boolean'],
        ]);
        $currency = strtoupper(store_currency((int) $store->id));
        DB::transaction(function () use ($data, $item, $currency) {
            $cells = MenuVariant::query()->where('menu_id', $item->id)->get()->keyBy('id');
            foreach ($data['cells'] as $id => $values) {
                $cell = $cells->get((int) $id);
                if (! $cell instanceof MenuVariant) {
                    continue;
                }
                $compareAt = isset($values['compare_at']) && $values['compare_at'] !== '' ? Money::minorFromMajor((float) $values['compare_at'], $currency) : null;
                $price = (float) $values['price'];
                $cell->fill([
                    'price' => $price,
                    // a "was" price is only shown above the selling price
                    'compare_at_minor' => $compareAt !== null && $compareAt > Money::minorFromMajor($price, $currency) ? $compareAt : null,
                    'stock_qty' => isset($values['stock_qty']) && $values['stock_qty'] !== '' ? (int) $values['stock_qty'] : null,
                    'low_stock_threshold' => isset($values['low_stock_threshold']) && $values['low_stock_threshold'] !== '' ? (int) $values['low_stock_threshold'] : null,
                    'sku' => $values['sku'] ?? null,
                    'gtin' => $values['gtin'] ?? null,
                    'active' => (bool) ($values['active'] ?? false),
                ])->save();
            }
        });

        return back()->with('success', ___('Variants saved.'));
    }

    /** @return list<string> */
    private function cells(string $line): array
    {
        return array_values(array_filter(array_map(fn ($c) => mb_substr(trim($c), 0, 40), explode(',', $line)), fn ($c) => $c !== ''));
    }
}
