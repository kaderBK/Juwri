<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Fitment;

use App\Models\VehicleFitment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Verticals\Models\FitmentImport;

/**
 * P3-08b (D12): a store's fitment CSV — year / make / model (and a VIN) → product rows, for the whole catalogue in one
 * file (the per-part import of FitmentController stays).
 *
 * Columns (header row, any order, case-insensitive, UTF-8 / BOM, comma or semicolon):
 *  - the product: `sku` (also `part_number`, `external_sku`: the item's SKU or one of its variants' SKUs) or `item_id`
 *    (the item's public id). Matched INSIDE this store only; a product of another store reads as "not found".
 *  - the vehicle: `year` (one year, or a range `2015-2018` = one fitment per year), `make`, `model`; optional
 *    `submodel`, `engine`, `notes`, `vin` (a VIN or its first 8-17 characters: the fitment then fits a vehicle whose
 *    VIN starts with it).
 *  - `id`, `store_id`, `account_id`, `menu_id`, `user_id` are never read: ownership always comes from the signed-in
 *    store, so a foreign id in the file changes nothing (the columns are listed as ignored in the preview).
 *
 * A bad row (unknown product, bad year, text too long...) is reported with its row number and skipped; the rest of
 * the file still imports. A fitment already in the store (same product, year, make, model, submodel, engine, VIN) is
 * counted as present, not added twice, so re-importing a file is safe.
 *
 * Limits: {@see self::MAX_BYTES}, {@see self::MAX_ROWS} data rows, {@see self::MAX_COLUMNS} columns, a year range of
 * at most {@see self::MAX_YEAR_SPAN} years and {@see self::STORE_CAP} fitments per store. A file of more than
 * {@see self::INLINE_ROWS} rows is imported by the queue.
 */
final class FitmentImporter
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_ROWS = 50000;

    public const INLINE_ROWS = 1000;

    public const MAX_COLUMNS = 30;

    public const MAX_YEAR_SPAN = 60;

    public const STORE_CAP = 500000;

    public const MAX_ERRORS_KEPT = 200;

    public const SAMPLE_ROWS = 15;

    private const CHUNK = 500;

    /** @var list<string> columns that are never read (the store comes from the signed-in owner) */
    public const IGNORED = ['id', 'store_id', 'account_id', 'menu_id', 'user_id', 'restaurant_id'];

    private const ALIASES = [
        'part_number' => 'sku', 'external_sku' => 'sku', 'part' => 'sku', 'item' => 'item_id', 'product_id' => 'item_id',
        'yr' => 'year', 'brand' => 'make', 'manufacturer' => 'make', 'trim' => 'submodel',
    ];

    /** The header of the sample file owners download. */
    public const TEMPLATE = "sku,year,make,model,submodel,engine,vin,notes\nBRK-1001,2015-2018,Honda,Civic,EX,2.0L,,Front pads\nOIL-5W30,2016,Toyota,Corolla,,1.8L,JTDKB20U,\n";

    /** The upload is checked and stored, never written to the catalogue (that is {@see self::run()}). */
    public function preview(Store $store, ?int $userId, UploadedFile $file): FitmentImport
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new FitmentImportException(___('The file is too large: at most :mb MB.', ['mb' => self::MAX_BYTES / 1048576]));
        }
        // An earlier preview that was never confirmed is dropped with its file.
        FitmentImport::query()->where('store_id', $store->id)->where('status', 'previewed')->get()->each(fn (FitmentImport $old) => $this->discard($old));

        $import = new FitmentImport;
        $import->forceFill([
            'store_id' => $store->id,
            'user_id' => $userId,
            'original_name' => Str::limit(preg_replace('/[^\w .\-()]/u', '_', (string) $file->getClientOriginalName()) ?: 'fitments.csv', 190, ''),
            'status' => 'previewed',
        ])->save();
        $path = 'fitment-imports/'.$store->id.'/'.$import->public_id.'.csv';
        Storage::disk('local')->put($path, (string) file_get_contents((string) $file->getRealPath()));
        $import->path = $path;

        try {
            $scan = $this->scan($store, Storage::disk('local')->path($path), false);
        } catch (FitmentImportException $e) {
            $this->discard($import);
            throw $e;
        }
        $this->record($import, $scan);
        $import->save();

        return $import;
    }

    /** Add the file's new fitments to the store and finish the import. */
    public function run(FitmentImport $import): FitmentImport
    {
        $store = Store::query()->findOrFail($import->store_id);
        $import->forceFill(['status' => 'running', 'started_at' => now(), 'message' => null])->save();

        try {
            if ($import->path === null || ! Storage::disk('local')->exists($import->path)) {
                throw new FitmentImportException(___('The uploaded file is no longer available. Upload it again.'));
            }
            $scan = $this->scan($store, Storage::disk('local')->path($import->path), true);
            $this->record($import, $scan);
            $import->forceFill(['status' => 'done', 'imported_rows' => $scan['imported'], 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $import->forceFill([
                'status' => 'failed',
                'message' => $e instanceof FitmentImportException ? $e->getMessage() : ___('The import stopped because of an error. Nothing more was added; try again.'),
                'finished_at' => now(),
            ]);
            if (! $e instanceof FitmentImportException) {
                report($e);
            }
        } finally {
            if ($import->path !== null) {
                Storage::disk('local')->delete($import->path);
                $import->path = null;
            }
            $import->save();
        }

        return $import;
    }

    public function discard(FitmentImport $import): void
    {
        if ($import->path !== null) {
            Storage::disk('local')->delete($import->path);
        }
        $import->delete();
    }

    /** The failing rows (as far as kept) as a CSV the owner can fix and upload again. */
    public function errorsCsv(FitmentImport $import): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['row', 'problem', 'values'], escape: '\\');
        foreach ((array) $import->errors as $error) {
            fputcsv($out, [self::safe((string) $error['row']), self::safe((string) $error['message']), self::safe((string) ($error['values'] ?? ''))], escape: '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** A cell that a spreadsheet would run as a formula is neutralised. */
    private static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @param  array<string, mixed>  $scan
     */
    private function record(FitmentImport $import, array $scan): void
    {
        $import->forceFill([
            'total_rows' => $scan['rows'],
            'valid_rows' => $scan['new'],
            'duplicate_rows' => $scan['present'],
            'error_rows' => $scan['error_rows'],
            'sample' => $scan['sample'],
            'errors' => $scan['errors'],
            'meta' => ['header' => $scan['header'], 'ignored' => $scan['ignored']],
        ]);
    }

    /**
     * Read the whole file once. Without `$write` nothing is stored (the preview); with it the new fitments are inserted
     * a chunk at a time, each chunk in its own transaction.
     *
     * @return array{rows: int, new: int, present: int, error_rows: int, imported: int, sample: list<array<string, mixed>>, errors: list<array<string, mixed>>, header: list<string>, ignored: list<string>}
     *
     * @throws FitmentImportException
     */
    private function scan(Store $store, string $path, bool $write): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new FitmentImportException(___('The file could not be read.'));
        }
        try {
            $first = fgets($handle);
            if ($first === false || trim($first) === '') {
                throw new FitmentImportException(___('The file is empty.'));
            }
            $first = (string) preg_replace('/^\x{FEFF}/u', '', $first);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $header = str_getcsv($first, $delimiter, escape: '\\');
            if (count($header) > self::MAX_COLUMNS) {
                throw new FitmentImportException(___('The file has too many columns: at most :count.', ['count' => self::MAX_COLUMNS]));
            }
            $names = [];
            $ignored = [];
            foreach ($header as $i => $raw) {
                $name = Str::snake(mb_strtolower(trim((string) $raw)));
                $name = self::ALIASES[$name] ?? $name;
                if (in_array($name, self::IGNORED, true)) {
                    $ignored[] = $name;
                    $name = '';
                }
                $names[$i] = $name;
            }
            $has = fn (string $c) => in_array($c, $names, true);
            if (! $has('sku') && ! $has('item_id')) {
                throw new FitmentImportException(___('The file needs a sku column (or item_id) to say which product each row is for.'));
            }
            foreach (['year', 'make', 'model'] as $required) {
                if (! $has($required)) {
                    throw new FitmentImportException(___('The file needs year, make and model columns.'));
                }
            }

            $products = new FitmentProductIndex($store);
            $cap = (int) config('verticals.fitment_store_cap', self::STORE_CAP);
            $room = max(0, $cap - VehicleFitment::query()->where('store_id', $store->id)->count());
            $scan = ['rows' => 0, 'new' => 0, 'present' => 0, 'error_rows' => 0, 'imported' => 0, 'sample' => [], 'errors' => [], 'header' => array_values(array_filter($names)), 'ignored' => array_values(array_unique($ignored))];
            /** @var list<array{no: int, records: list<array<string, mixed>>, label: string, vehicle: string, values: string}> $pending the rows of the current chunk */
            $pending = [];
            /** @var array<string, true> $seen */
            $seen = [];

            $flush = function () use (&$pending, &$seen, &$scan, &$room, $write, $store): void {
                if ($pending === []) {
                    return;
                }
                $existing = $this->existingKeys($store, array_values(array_unique(array_merge(...array_map(fn ($p) => array_column($p['records'], 'menu_id'), $pending)))));
                $insert = [];
                foreach ($pending as $row) {
                    $added = 0;
                    $capped = false;
                    foreach ($row['records'] as $record) {
                        $key = self::key($record);
                        if (isset($existing[$key]) || isset($seen[$key])) {
                            $scan['present']++;

                            continue;
                        }
                        if ($room <= 0) {
                            $capped = true;

                            continue;
                        }
                        $room--;
                        $seen[$key] = true;
                        $added++;
                        $insert[] = $record;
                    }
                    $scan['new'] += $added;
                    if ($capped) {
                        $this->addError($scan, $row['no'], ___('The store already holds the most fitments allowed (:count).', ['count' => number_format((int) config('verticals.fitment_store_cap', self::STORE_CAP))]), $row['values']);
                    } elseif ($added > 0 && count($scan['sample']) < self::SAMPLE_ROWS) {
                        $scan['sample'][] = ['row' => $row['no'], 'product' => $row['label'], 'vehicle' => $row['vehicle'], 'new' => $added];
                    }
                }
                if ($write && $insert !== []) {
                    $now = now()->utc()->format('Y-m-d H:i:s');
                    DB::transaction(function () use ($insert, $store, $now, &$scan): void {
                        foreach (array_chunk($insert, 200) as $batch) {
                            DB::table('vehicle_fitments')->insert(array_map(fn (array $r) => $r + ['account_id' => $store->account_id, 'store_id' => $store->id, 'created_at' => $now, 'updated_at' => $now], $batch));
                        }
                        $scan['imported'] += count($insert);
                    });
                }
                $pending = [];
            };

            $no = 1;
            while (($cells = fgetcsv($handle, 8192, $delimiter, escape: '\\')) !== false) {
                $no++;
                if ($cells === [null] || count(array_filter($cells, fn ($c) => $c !== null && trim((string) $c) !== '')) === 0) {
                    continue; // a blank line
                }
                $scan['rows']++;
                if ($scan['rows'] > self::MAX_ROWS) {
                    throw new FitmentImportException(___('The file has too many rows: at most :count.', ['count' => number_format(self::MAX_ROWS)]));
                }
                $data = [];
                foreach ($names as $i => $name) {
                    if ($name !== '' && ! isset($data[$name])) {
                        $data[$name] = trim((string) ($cells[$i] ?? ''));
                    }
                }
                $values = implode(' | ', array_map(fn ($v) => Str::limit((string) $v, 40, '…'), array_slice($cells, 0, 8)));
                $parsed = $this->parseRow($data, $products);
                if (is_string($parsed)) {
                    $this->addError($scan, $no, $parsed, $values);

                    continue;
                }
                $pending[] = ['no' => $no, 'records' => $parsed['records'], 'label' => $parsed['label'], 'vehicle' => $parsed['vehicle'], 'values' => $values];
                if (count($pending) >= self::CHUNK) {
                    $flush();
                }
            }
            $flush();
            if ($scan['rows'] === 0) {
                throw new FitmentImportException(___('The file has a header but no rows.'));
            }

            return $scan;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $scan
     */
    private function addError(array &$scan, int $row, string $message, string $values): void
    {
        $scan['error_rows']++;
        if (count($scan['errors']) < self::MAX_ERRORS_KEPT) {
            $scan['errors'][] = ['row' => $row, 'message' => $message, 'values' => $values];
        }
    }

    /**
     * @param  array<string, string>  $data
     * @return array{records: list<array<string, mixed>>, label: string, vehicle: string}|string the problem when the row cannot be used
     */
    private function parseRow(array $data, FitmentProductIndex $products): array|string
    {
        $menuId = null;
        $label = '';
        if (($data['sku'] ?? '') !== '') {
            $found = $products->bySku($data['sku']);
            if ($found === 'many') {
                return ___('The SKU :sku belongs to more than one product.', ['sku' => Str::limit($data['sku'], 40)]);
            }
            $menuId = $found;
            $label = $data['sku'];
        } elseif (($data['item_id'] ?? '') !== '') {
            $menuId = $products->byPublicId($data['item_id']);
            $label = $data['item_id'];
        } else {
            return ___('No product: the sku is empty.');
        }
        if ($menuId === null) {
            return ___('No product of this store has the SKU or id :sku.', ['sku' => Str::limit($label, 40)]);
        }

        $years = self::years((string) ($data['year'] ?? ''));
        if (is_string($years)) {
            return $years;
        }
        $make = (string) preg_replace('/\s+/u', ' ', $data['make'] ?? '');
        $model = (string) preg_replace('/\s+/u', ' ', $data['model'] ?? '');
        if ($make === '' || $model === '') {
            return ___('The make and the model are required.');
        }
        $submodel = (string) preg_replace('/\s+/u', ' ', $data['submodel'] ?? '');
        $engine = (string) preg_replace('/\s+/u', ' ', $data['engine'] ?? '');
        $notes = $data['notes'] ?? '';
        foreach ([[___('Make'), $make], [___('Model'), $model], [___('Submodel'), $submodel], [___('Engine'), $engine]] as [$field, $value]) {
            if (mb_strlen($value) > 100) {
                return ___('The :field is longer than 100 characters.', ['field' => $field]);
            }
        }
        if (mb_strlen($notes) > 2000) {
            return ___('The notes are longer than 2,000 characters.');
        }
        $vin = strtoupper((string) preg_replace('/[\s\-]/', '', $data['vin'] ?? ''));
        if ($vin !== '' && preg_match('/^[A-HJ-NPR-Z0-9]{8,17}$/', $vin) !== 1) {
            return ___('The VIN must be 8 to 17 letters and digits (no I, O or Q).');
        }

        $records = [];
        foreach ($years as $year) {
            $records[] = [
                'menu_id' => $menuId,
                'year' => $year,
                'make' => $make,
                'model' => $model,
                'submodel' => $submodel !== '' ? $submodel : null,
                'engine' => $engine !== '' ? $engine : null,
                'vin_prefix' => $vin !== '' ? $vin : null,
                'notes' => $notes !== '' ? $notes : null,
            ];
        }
        $span = count($years) > 1 ? $years[0].'-'.$years[count($years) - 1] : (string) $years[0];

        return ['records' => $records, 'label' => $label, 'vehicle' => trim($span.' '.$make.' '.$model)];
    }

    /** @return list<int>|string the years, or the problem */
    private static function years(string $raw): array|string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})$/', $raw, $m) === 1) {
            $from = $to = (int) $m[1];
        } elseif (preg_match('/^(\d{4})\s*[-–to]+\s*(\d{4})$/u', $raw, $m) === 1) {
            [$from, $to] = [(int) $m[1], (int) $m[2]];
        } else {
            return ___('The year must be a year (2016) or a range (2015-2018).');
        }
        if ($from < 1900 || $to > 2100 || $to < $from) {
            return ___('The year must be between 1900 and 2100, and a range must run forward.');
        }
        if ($to - $from > self::MAX_YEAR_SPAN) {
            return ___('A year range cannot span more than :count years.', ['count' => self::MAX_YEAR_SPAN]);
        }

        return range($from, $to);
    }

    /**
     * @param  list<int>  $menuIds
     * @return array<string, true>
     */
    private function existingKeys(Store $store, array $menuIds): array
    {
        $keys = [];
        foreach (array_chunk($menuIds, 500) as $ids) {
            foreach (VehicleFitment::query()->where('store_id', $store->id)->whereIn('menu_id', $ids)->get(['menu_id', 'year', 'make', 'model', 'submodel', 'engine', 'vin_prefix']) as $row) {
                $keys[self::key($row->toArray())] = true;
            }
        }

        return $keys;
    }

    /** @param  array<string, mixed>  $r */
    private static function key(array $r): string
    {
        return implode("\x1f", [(int) $r['menu_id'], (int) $r['year'], mb_strtolower((string) $r['make']), mb_strtolower((string) $r['model']), mb_strtolower((string) ($r['submodel'] ?? '')), mb_strtolower((string) ($r['engine'] ?? '')), strtoupper((string) ($r['vin_prefix'] ?? ''))]);
    }
}
