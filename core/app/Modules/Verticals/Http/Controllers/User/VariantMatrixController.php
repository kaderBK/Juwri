<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Services\Entitlements;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Services\Catalog\VariantMatrixGenerator;

/**
 * rev/multi-vertical Lane A2 — the dashboard "variant grid" editor (WORK-ITEMS A2).
 *
 * `generate` runs the cartesian axes generator (a T-shirt with S/M/L × 3
 * colors → 9 rows); `bulkUpdate` saves the whole grid's price/sku/stock/
 * barcode/active cells in one submit (the "editable inline" UI requirement);
 * `destroy` removes one cell. All three redirect back to the retail item edit
 * page (A4), which renders {@see MenuVariant::getAxesLabelAttribute()}
 * for each row.
 */
class VariantMatrixController extends Controller
{
    /** rev/multi-vertical Lane V (V4): same multi_vertical manage-surface gate as RetailItemController. */
    private function authorizeItem(Request $request, Store $store, Menu $product): void
    {
        abort_unless((int) $store->user_id === (int) $request->user()->id, 404);
        abort_unless((int) $product->store_id === (int) $store->id, 404);
        abort_unless(app(VerticalRegistry::class)->itemForm($store->store_type) === 'retail', 404);
        abort_unless(Entitlements::allows($request->user(), 'multi_vertical'), 403);
    }

    public function generate(Request $request, Store $store, Menu $product, VariantMatrixGenerator $generator): RedirectResponse
    {
        $this->authorizeItem($request, $store, $product);

        $axisNames = (array) $request->input('axis_name', []);
        $axisValues = (array) $request->input('axis_values', []);

        $axes = [];
        foreach ($axisNames as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $values = array_map('trim', explode(',', (string) ($axisValues[$i] ?? '')));
            $axes[$name] = $values;
        }

        if (! $axes) {
            app_alert_error(___('Enter at least one axis (e.g. Size: S, M, L).'));

            return back();
        }

        $result = $generator->generate($product, $axes);

        app_alert_success(___(':created cell(s) created, :skipped already existed.', [
            'created' => count($result['created']),
            'skipped' => $result['skipped'],
        ]));

        return redirect()->route('retail.products.edit', [$store, $product]);
    }

    /** Save every variant row's price/sku/stock/barcode/active in one submit. */
    public function bulkUpdate(Request $request, Store $store, Menu $product): RedirectResponse
    {
        $this->authorizeItem($request, $store, $product);

        $rows = (array) $request->input('variants', []);
        foreach ($rows as $variantId => $row) {
            $variant = MenuVariant::where('menu_id', $product->id)->find((int) $variantId);
            if (! $variant) {
                continue;
            }

            $price = is_numeric($row['price'] ?? null) ? (float) $row['price'] : (float) $variant->price;
            // P3-08: the struck-through "was" price (minor units), kept only above the selling price.
            $currency = strtoupper(store_currency((int) $store->id));
            $compareAt = array_key_exists('compare_at', $row)
                ? (is_numeric($row['compare_at']) && (float) $row['compare_at'] > 0 ? Money::minorFromMajor((float) $row['compare_at'], $currency) : null)
                : $variant->compare_at_minor;
            $variant->update([
                'compare_at_minor' => $compareAt !== null && $compareAt > Money::minorFromMajor($price, $currency) ? $compareAt : null,
                'price' => $price,
                'sku' => isset($row['sku']) ? trim((string) $row['sku']) ?: null : $variant->sku,
                'stock_qty' => isset($row['stock_qty']) && $row['stock_qty'] !== '' ? (int) $row['stock_qty'] : null,
                'barcode' => isset($row['barcode']) ? trim((string) $row['barcode']) ?: null : $variant->barcode,
                'active' => isset($row['active']),
            ]);
        }

        app_alert_success(___('Saved Successfully'));

        return redirect()->route('retail.products.edit', [$store, $product]);
    }

    public function destroy(Request $request, Store $store, Menu $product, MenuVariant $variant): RedirectResponse
    {
        $this->authorizeItem($request, $store, $product);
        abort_unless((int) $variant->menu_id === (int) $product->id, 404);

        $variant->delete();
        app_alert_success(___('Deleted Successfully'));

        return redirect()->route('retail.products.edit', [$store, $product]);
    }
}
