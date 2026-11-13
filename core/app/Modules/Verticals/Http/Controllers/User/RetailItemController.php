<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Exceptions\PlanLimitReachedException;
use App\Models\MenuCategory;
use App\Services\Backup\DatasetStreamWriter;
use App\Services\Entitlements;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Http\Controllers\User\Concerns\ImportsMenuCsvCore;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuAdminService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * rev/multi-vertical Lane A4/A5/A7 — the retail item form + catalog list.
 *
 * A NEW dashboard surface, deliberately kept out of ManagesMenu/StoreController
 * (the shared, food-framed item form the pack's hard rules forbid touching).
 * It serves every vertical whose VerticalRegistry `item_form` capability is
 * 'retail' (retail/cosmetic/clothing/carparts/pharmacy — see config/verticals.php)
 * and has NO food fields at all (no veg/nonveg, allergens, spice, nutrition,
 * course/prep) — G3, done by construction rather than by hiding inputs.
 *
 * Persistence goes through {@see MenuAdminService}, the same neutral
 * create/update engine the REST API and staff app already share, so plan
 * limits, category-ownership checks and price-change auditing are identical
 * to every other Menu-writing surface — nothing here reimplements them.
 */
class RetailItemController extends Controller
{
    use ImportsMenuCsvCore;

    /**
     * Tenancy (G2) + vertical-shape guard shared by every action here.
     *
     * rev/multi-vertical Lane V (V4) — the `multi_vertical` entitlement gates
     * ongoing MANAGEMENT of a non-restaurant catalog the same way F0-6
     * already gates CREATING the store: a store keeps existing if the
     * entitlement later lapses, but management of this premium surface stops
     * immediately. A restaurant never reaches this controller (the item-form
     * check below 404s it first), so this is a no-op for every legacy store.
     */
    private function authorizeStore(Request $request, Store $store): void
    {
        abort_unless((int) $store->user_id === (int) $request->user()->id, 404);
        abort_unless(app(VerticalRegistry::class)->itemForm($store->store_type) === 'retail', 404);
        abort_unless(Entitlements::allows($request->user(), 'multi_vertical'), 403);
    }

    public function index(Request $request, Store $store, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);

        $brandId = $request->integer('brand_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;

        $items = Menu::where('store_id', $store->id)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with(['brand', 'category', 'variants'])
            ->orderBy('category_id')
            ->orderBy('position')
            ->paginate(25)
            ->withQueryString();

        $categories = MenuCategory::where('store_id', $store->id)
            ->orderBy('parent')->orderBy('position')->get();
        $brands = Brand::where('store_id', $store->id)->orderBy('name')->get();

        return view('owner.catalog.retail.index', [
            'store' => $store,
            'items' => $items,
            'categories' => $categories,
            'brands' => $brands,
            'vocab' => $vocab->map($store->store_type),
            'filterBrandId' => $brandId,
            'filterCategoryId' => $categoryId,
        ]);
    }

    public function create(Request $request, Store $store, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);

        return view('owner.catalog.retail.create', [
            'store' => $store,
            'categories' => MenuCategory::where('store_id', $store->id)->orderBy('name')->get(),
            'brands' => Brand::where('store_id', $store->id)->orderBy('name')->get(),
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        $data = $this->validated($request, $store);

        try {
            $item = MenuAdminService::createItem((int) $store->id, $data);
        } catch (PlanLimitReachedException $e) {
            app_alert_error(___($e->getMessage()));

            return back()->withInput();
        }

        log_activity('menu.create', $item, ['name' => $item->getRawOriginal('name')], $store->id);
        app_alert_success(___('Saved Successfully'));

        return redirect()->route('retail.products.edit', [$store, $item]);
    }

    public function edit(Request $request, Store $store, Menu $product, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        return view('owner.catalog.retail.edit', [
            'store' => $store,
            'item' => $product,
            'categories' => MenuCategory::where('store_id', $store->id)->orderBy('name')->get(),
            'brands' => Brand::where('store_id', $store->id)->orderBy('name')->get(),
            'variants' => $product->variants()->orderBy('position')->get(),
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    public function update(Request $request, Store $store, Menu $product): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $data = $this->validated($request, $store, creating: false, product: $product);

        // Found by the e2eA retail journey: the last unit sells a tracked product out (is_sold_out) but typing new
        // stock in this form left the flag on, so the restocked product stayed off sale. Stock that ran out and is
        // now above zero lifts the flag; a manual sold-out on a product that still had stock (the owner's choice) stays.
        $ranOut = $product->track_inventory && $product->stock_qty !== null && (int) $product->stock_qty <= 0 && $product->is_sold_out;
        if ($ranOut && isset($data['stock_qty']) && (int) $data['stock_qty'] > 0) {
            $data['is_sold_out'] = false;
        }

        MenuAdminService::updateItem($product, $data);

        app_alert_success(___('Saved Successfully'));

        return redirect()->route('retail.products.edit', [$store, $product]);
    }

    public function destroy(Request $request, Store $store, Menu $product): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $product->delete();
        app_alert_success(___('Deleted Successfully'));

        return redirect()->route('retail.products.index', $store);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Store $store, bool $creating = true, ?Menu $product = null): array
    {
        $rules = [
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'dimensions_length' => ['nullable', 'numeric', 'min:0'],
            'dimensions_width' => ['nullable', 'numeric', 'min:0'],
            'dimensions_height' => ['nullable', 'numeric', 'min:0'],
            'dimensions_unit' => ['nullable', 'string', 'max:10'],
            'attr_key' => ['nullable', 'array'],
            'attr_key.*' => ['nullable', 'string', 'max:100'],
            'attr_value' => ['nullable', 'array'],
            'attr_value.*' => ['nullable', 'string', 'max:1000'],
            'image_file' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'track_inventory' => ['nullable', 'boolean'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];

        $validator = Validator::make($request->all(), $rules);
        $validator->validate();
        $v = $validator->validated();

        if (! empty($v['brand_id'])) {
            abort_unless(Brand::where('id', $v['brand_id'])->where('store_id', $store->id)->exists(), 422, 'Invalid brand.');
        }

        $attributes = null;
        $keys = (array) $request->input('attr_key', []);
        $values = (array) $request->input('attr_value', []);
        if ($keys) {
            $submitted = [];
            foreach ($keys as $i => $k) {
                $k = trim((string) $k);
                if ($k === '') {
                    continue;
                }
                $submitted[$k] = $values[$i] ?? null;
            }

            // rev/multi-vertical Lane V (integration fix 2) — MERGE the
            // submitted custom attr_key[]/attr_value[] rows into whatever
            // `menu.attributes` already holds, rather than replacing the
            // whole JSON column. This form only ever shows/edits the
            // GENERIC custom-attribute rows; it knows nothing about
            // vertical-specific keys another surface wrote into the SAME
            // shared `menu.attributes` seam (A1) — e.g. Lane E's
            // oem_numbers/cross_refs (car-parts) — which must survive an
            // edit made here (see PROGRESS.md's Lane E integration note).
            $existing = ($product && is_array($product->attributes)) ? $product->attributes : [];
            $attributes = array_merge($existing, $submitted) ?: null;
        }

        $dimensions = null;
        if ($request->filled('dimensions_length') || $request->filled('dimensions_width') || $request->filled('dimensions_height')) {
            $dimensions = [
                'length' => $request->filled('dimensions_length') ? (float) $request->input('dimensions_length') : null,
                'width' => $request->filled('dimensions_width') ? (float) $request->input('dimensions_width') : null,
                'height' => $request->filled('dimensions_height') ? (float) $request->input('dimensions_height') : null,
                'unit' => $request->input('dimensions_unit') ?: 'cm',
            ];
        }

        // Only a field the request actually TOUCHED lands in $data, so a partial
        // update (PUT with just a price change) never clobbers name/category/etc.
        // back to null. track_inventory/active are checkboxes — Laravel never
        // sends an unchecked one, so they're always explicitly set from `has()`.
        $data = array_filter([
            'category_id' => $v['category_id'] ?? null,
            'brand_id' => $v['brand_id'] ?? null,
            'name' => $v['name'] ?? null,
            'description' => isset($v['description']) ? strip_tags($v['description'], '<b><strong><em><i><br><p><ul><ol><li><span>') : null,
            'price' => $v['price'] ?? null,
            'cost' => $v['cost'] ?? null,
            'barcode' => $v['barcode'] ?? null,
            'weight' => $v['weight'] ?? null,
            'stock_qty' => $request->filled('stock_qty') ? (int) $request->input('stock_qty') : null,
        ], fn ($value) => $value !== null);

        $data['track_inventory'] = $request->has('track_inventory') ? 1 : 0;
        $data['active'] = $request->has('active') ? 1 : 0;

        if ($dimensions !== null) {
            $data['dimensions'] = $dimensions;
        }
        if ($attributes !== null) {
            $data['attributes'] = $attributes;
        }

        if ($request->hasFile('image_file')) {
            $data['image'] = image_upload($request->file('image_file'), 'storage/menu/', '500x500');
        }

        return $data;
    }

    /** A7: export this store's retail catalog (brand/attributes/variants included) as CSV. */
    public function exportCsv(Request $request, Store $store): StreamedResponse
    {
        $this->authorizeStore($request, $store);

        $items = Menu::where('store_id', $store->id)
            ->with(['brand', 'variants'])
            ->orderBy('category_id')->orderBy('position')->get();

        $filename = 'catalog-'.$store->slug.'-'.date('Y-m-d').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $columns = ['id', 'category_id', 'name', 'description', 'price', 'cost', 'active', 'brand', 'barcode', 'weight', 'dimensions', 'attributes', 'variants'];

        $callback = function () use ($items, $columns) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, $columns, escape: '\\');
            foreach ($items as $item) {
                $variants = $item->variants->map(fn ($v) => [
                    'options' => is_object($v->options) ? get_object_vars($v->options) : ($v->options ?? []),
                    'price' => $v->price,
                    'sku' => $v->sku,
                    'stock_qty' => $v->stock_qty,
                    'barcode' => $v->barcode,
                ])->values()->all();

                fputcsv($handle, DatasetStreamWriter::csvRow([
                    $item->id,
                    $item->category_id,
                    $item->getRawOriginal('name'),
                    $item->getRawOriginal('description'),
                    $item->price,
                    $item->cost,
                    $item->getRawOriginal('active'),
                    $item->brand->name ?? '',
                    $item->barcode ?? '',
                    $item->weight ?? '',
                    $item->dimensions ? json_encode($item->dimensions) : '',
                    $item->attributes ? json_encode($item->attributes) : '',
                    $variants ? json_encode($variants) : '',
                ]),
                    escape: '\\');
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /** A7: import a catalog CSV (same shape exportCsv writes) via the shared ImportsMenuCsvCore engine. */
    public function importCsv(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        $result = $this->importMenuCsvInto($request, $store);

        if (! $result['success']) {
            app_alert_error($result['message']);

            return back();
        }

        log_activity('csv.import', null, [
            'imported' => $result['imported'], 'updated' => $result['updated'], 'skipped' => $result['skipped'],
        ], $store->id);

        app_alert_success($result['message']);

        return redirect()->route('retail.products.index', $store);
    }
}
