<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Models\VehicleFitment;
use App\Services\Entitlements;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * rev/multi-vertical Lane E3/E4/E5 — vehicle fitment management + CSV import
 * + OEM/cross-reference numbers for car-parts items (DECISIONS A8).
 *
 * A NEW dashboard surface (its own controller + views), never edited into
 * Lane A's RetailItemController/retail item form — those stay frozen (the
 * pack's hard rule). Every action here re-checks the vertical's `fitment`
 * capability itself (VerticalRegistry->has(..., 'fitment')), so a
 * retail/cosmetic/clothing/pharmacy store 404s on every route here exactly
 * like a restaurant 404s on RetailItemController's routes today.
 */
class FitmentController extends Controller
{
    /**
     * Tenancy (G2) + vertical-capability guard shared by every action.
     *
     * rev/multi-vertical Lane V (V4): same multi_vertical manage-surface gate
     * as RetailItemController — car-parts is a non-restaurant vertical too.
     */
    private function authorizeStore(Request $request, Store $store): void
    {
        abort_unless((int) $store->user_id === (int) $request->user()->id, 404);
        abort_unless(app(VerticalRegistry::class)->has($store->store_type, 'fitment'), 404);
        abort_unless(Entitlements::allows($request->user(), 'multi_vertical'), 403);
    }

    private function authorizeItem(Request $request, Store $store, Menu $product): void
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $product->store_id === (int) $store->id, 404);
    }

    /** GET stores/{store}/fitments — landing list of this store's parts + fitment counts. */
    public function landing(Request $request, Store $store, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);

        $items = Menu::where('store_id', $store->id)
            ->withCount('fitments')
            ->orderBy('name')
            ->paginate(25);

        return view('owner.catalog.fitments.landing', [
            'store' => $store,
            'items' => $items,
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    /** GET stores/{store}/fitments/{product} — per-item fitments + OEM manager. */
    public function show(Request $request, Store $store, Menu $product, VerticalVocab $vocab): View
    {
        $this->authorizeItem($request, $store, $product);

        $attributes = (array) ($product->attributes ?? []);

        return view('owner.catalog.fitments.show', [
            'store' => $store,
            'item' => $product,
            'fitments' => $product->fitments()->get(),
            'oemNumbers' => (array) ($attributes['oem_numbers'] ?? []),
            'crossRefs' => (array) ($attributes['cross_refs'] ?? []),
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    /** POST .../fitments/{product} — add one Y/M/M(/submodel/engine) row. */
    public function store(Request $request, Store $store, Menu $product): RedirectResponse
    {
        $this->authorizeItem($request, $store, $product);

        $v = Validator::make($request->all(), [
            'year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'submodel' => ['nullable', 'string', 'max:100'],
            'engine' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        VehicleFitment::create([
            'menu_id' => $product->id,
            'store_id' => $store->id,
            'year' => $v['year'],
            'make' => trim($v['make']),
            'model' => trim($v['model']),
            'submodel' => $v['submodel'] ?? null,
            'engine' => $v['engine'] ?? null,
            'notes' => $v['notes'] ?? null,
        ]);

        app_alert_success(___('Fitment added.'));

        return redirect()->route('retail.fitments.show', [$store, $product]);
    }

    /** POST .../fitments/{product}/bulk — add one row per year in a range ("bulk add by model range"). */
    public function bulkStore(Request $request, Store $store, Menu $product): RedirectResponse
    {
        $this->authorizeItem($request, $store, $product);

        $v = Validator::make($request->all(), [
            'year_from' => ['required', 'integer', 'min:1900', 'max:2100'],
            'year_to' => ['required', 'integer', 'min:1900', 'max:2100', 'gte:year_from'],
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'submodel' => ['nullable', 'string', 'max:100'],
            'engine' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        if (($v['year_to'] - $v['year_from']) > 60) {
            app_alert_error(___('A model range cannot span more than 60 years.'));

            return back();
        }

        $make = trim($v['make']);
        $model = trim($v['model']);
        $submodel = $v['submodel'] ?? null;
        $engine = $v['engine'] ?? null;

        $existing = VehicleFitment::where('menu_id', $product->id)
            ->where('make', $make)->where('model', $model)
            ->where('submodel', $submodel)->where('engine', $engine)
            ->pluck('year')->all();

        $created = 0;
        for ($year = $v['year_from']; $year <= $v['year_to']; $year++) {
            if (in_array($year, $existing, true)) {
                continue; // already covered — bulk-add is idempotent on re-submit
            }
            VehicleFitment::create([
                'menu_id' => $product->id,
                'store_id' => $store->id,
                'year' => $year,
                'make' => $make,
                'model' => $model,
                'submodel' => $submodel,
                'engine' => $engine,
                'notes' => $v['notes'] ?? null,
            ]);
            $created++;
        }

        app_alert_success(___(':count fitment row(s) added (:skipped already existed).', [
            'count' => $created,
            'skipped' => ($v['year_to'] - $v['year_from'] + 1) - $created,
        ]));

        return redirect()->route('retail.fitments.show', [$store, $product]);
    }

    /** DELETE .../fitments/{product}/{fitment} */
    public function destroy(Request $request, Store $store, Menu $product, VehicleFitment $fitment): RedirectResponse
    {
        $this->authorizeItem($request, $store, $product);
        abort_unless((int) $fitment->menu_id === (int) $product->id, 404);

        $fitment->delete();
        app_alert_success(___('Fitment removed.'));

        return redirect()->route('retail.fitments.show', [$store, $product]);
    }

    /**
     * POST .../fitments/{product}/import-csv — E4: flat fitment sheet for ONE
     * part (default per O-ACES; full ACES/PIES ingest deferred). Header row
     * required: year,make,model[,submodel,engine,notes]. Mirrors
     * ImportsMenuCsvCore's parsing shape (BOM strip, header-driven columns,
     * per-row validation, collected error summary) but fitment rows are their
     * own table/file — this is a dedicated parser, not a fork of that trait.
     */
    public function importCsv(Request $request, Store $store, Menu $product): RedirectResponse
    {
        $this->authorizeItem($request, $store, $product);

        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');
        $header = $handle ? fgetcsv($handle, escape: '\\') : false;

        if (! $header) {
            app_alert_error(___('Invalid CSV file.'));

            return back();
        }

        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);

        if (! in_array('year', $header, true) || ! in_array('make', $header, true) || ! in_array('model', $header, true)) {
            app_alert_error(___('The CSV must have year, make and model columns.'));
            fclose($handle);

            return back();
        }

        $headerCount = count($header);
        $imported = 0;
        $skipped = 0;
        $rowNum = 1;
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn ($c) => $c !== null && $c !== '')) === 0) {
                continue;
            }
            if (count($row) < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif (count($row) > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }
            $data = array_combine($header, $row);

            $year = (int) ($data['year'] ?? 0);
            $make = trim((string) ($data['make'] ?? ''));
            $model = trim((string) ($data['model'] ?? ''));

            if ($year < 1900 || $year > 2100 || $make === '' || $model === '') {
                $skipped++;

                continue;
            }

            VehicleFitment::create([
                'menu_id' => $product->id,
                'store_id' => $store->id,
                'year' => $year,
                'make' => $make,
                'model' => $model,
                'submodel' => ! empty($data['submodel']) ? trim((string) $data['submodel']) : null,
                'engine' => ! empty($data['engine']) ? trim((string) $data['engine']) : null,
                'notes' => ! empty($data['notes']) ? trim((string) $data['notes']) : null,
            ]);
            $imported++;
        }
        fclose($handle);

        $msg = ___(':count fitment row(s) imported.', ['count' => $imported]);
        if ($skipped) {
            $msg .= ' '.___(':count row(s) skipped (missing/invalid year, make or model).', ['count' => $skipped]);
        }
        app_alert_success($msg);

        return redirect()->route('retail.fitments.show', [$store, $product]);
    }

    /**
     * POST .../fitments/{product}/oem — E5: oem_numbers[]/cross_refs[] live in
     * the SHARED `menu.attributes` JSON column (Lane A's seam — consumed, not
     * forked: every other key already in `attributes` is preserved as-is).
     */
    public function updateOem(Request $request, Store $store, Menu $product): RedirectResponse
    {
        $this->authorizeItem($request, $store, $product);

        $v = Validator::make($request->all(), [
            'oem_numbers' => ['nullable', 'string', 'max:4000'],
            'cross_refs' => ['nullable', 'string', 'max:4000'],
        ])->validate();

        $toList = static function (?string $raw): array {
            if (! $raw) {
                return [];
            }
            $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
            $parts = array_map('trim', $parts);
            $parts = array_filter($parts, fn ($p) => $p !== '');

            return array_values(array_unique($parts));
        };

        $attributes = (array) ($product->attributes ?? []);
        $attributes['oem_numbers'] = $toList($v['oem_numbers'] ?? null);
        $attributes['cross_refs'] = $toList($v['cross_refs'] ?? null);

        $product->update(['attributes' => $attributes]);

        app_alert_success(___('Saved Successfully'));

        return redirect()->route('retail.fitments.show', [$store, $product]);
    }
}
