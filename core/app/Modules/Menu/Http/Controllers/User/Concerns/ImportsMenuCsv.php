<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User\Concerns;

use App\Services\Backup\DatasetStreamWriter;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * M15: extracted from StoreController (mechanical split, no behaviour change),
 * following the H11 precedent already used by ManagesMenu/ManagesOrders.
 *
 * Menu CSV export + import. Routes are unchanged — core/routes/web.php binds
 * them by METHOD NAME inside Route::controller('StoreController'), so a trait
 * method resolves identically to an inline one.
 */
trait ImportsMenuCsv
{
    // R2-1 (B6): the import parse+persist engine lives in a shared core so the
    // reseller store console reuses it byte-for-byte (see ImportsMenuCsvCore).
    use ImportsMenuCsvCore;

    /**
     * #10: Export menu items as CSV
     */
    public function menuExportCsv(Request $request, $store)
    {
        $store = Store::where('user_id', auth()->id())
            ->where(function ($q) use ($store) {
                $q->idOrSlug($store);
            })->firstOrFail();

        $items = Menu::where('store_id', $store->id)
            ->orderBy('category_id')
            ->orderBy('position')
            ->get();

        $langCodes = [];
        $menuLangs = store_options($store->id, 'menu_languages');
        $defaultLang = store_options($store->id, 'default_language');
        if ($menuLangs && $defaultLang) {
            $langCodes = collect($menuLangs)->reject(fn ($c) => $c === $defaultLang)->values()->all();
        }

        $filename = 'menu-'.$store->slug.'-'.date('Y-m-d').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $columns = [
            'id', 'category_id', 'name', 'description', 'price', 'type',
            'active', 'is_vegan', 'is_halal', 'is_gluten_free', 'is_popular',
            'is_sold_out', 'is_daily_special', 'spice_level',
            'available_from', 'available_to', 'available_days',
            'calories', 'protein', 'carbs', 'fat', 'allergies',
        ];

        $translationCols = [];
        foreach ($langCodes as $code) {
            $translationCols[] = 'name_'.$code;
            $translationCols[] = 'description_'.$code;
        }

        $callback = function () use ($items, $columns, $translationCols, $langCodes) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, array_merge($columns, $translationCols), escape: '\\');
            foreach ($items as $item) {
                $row = [];
                foreach ($columns as $col) {
                    if ($col === 'name' || $col === 'description') {
                        $row[] = $item->getRawOriginal($col) ?? '';
                    } else {
                        $row[] = $item->{$col} ?? '';
                    }
                }
                foreach ($langCodes as $code) {
                    $row[] = $item->translations->{$code}->title ?? '';
                    $row[] = $item->translations->{$code}->description ?? '';
                }
                fputcsv($handle, DatasetStreamWriter::csvRow($row), escape: '\\');
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * #10: Import menu items from CSV
     */
    public function menuImportCsv(Request $request, $store)
    {
        $store = Store::where('user_id', auth()->id())
            ->where(function ($q) use ($store) {
                $q->idOrSlug($store);
            })->firstOrFail();

        // R2-1 (B6): the parse+persist loop now lives in ImportsMenuCsvCore so the
        // reseller store console shares it verbatim. Response shape + the csv.import
        // activity log are unchanged for the owner path.
        $result = $this->importMenuCsvInto($request, $store);

        if (! $result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']]);
        }

        log_activity('csv.import', null, [
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ], $store->id);

        return response()->json($result);
    }
}
