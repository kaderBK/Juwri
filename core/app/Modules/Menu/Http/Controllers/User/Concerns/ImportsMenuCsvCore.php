<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User\Concerns;

use App\Models\MenuCategory;
use App\Services\Backup\DatasetStreamWriter;
use App\Services\Entitlements;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

/**
 * reseller-store R2-1 (B6): the ONE menu-CSV import engine, shared by the owner
 * dashboard (ImportsMenuCsv::menuImportCsv) and the reseller store console
 * (Reseller\StoreMenuController::import), so both round-trip everything the
 * export writes — id, category (numeric id OR by name), type, every dietary flag,
 * spice, availability window and per-language translation columns.
 *
 * It was extracted verbatim from ImportsMenuCsv::menuImportCsv (M15 precedent),
 * with two deliberate seam changes that keep the OWNER path behaviour-identical:
 *
 *   1. The store is a PARAMETER ($store) instead of being resolved from auth(), so
 *      the reseller can drive it against a customer-owned store. In the owner path
 *      $store is still `Post::where('user_id', auth()->id())`, i.e. the same store.
 *   2. Plan limits resolve against $store's OWNER (effective_limit(..., $store->user))
 *      rather than the implicit auth() user. In the owner path the store owner IS
 *      the auth user, so the resolved limit is identical; in the reseller path it
 *      correctly enforces the CUSTOMER store's plan, not the reseller's.
 *
 * It also fixes a latent translations double-encode: `translations` is an
 * `object`-cast column, so a pre-encoded json_encode() string was stored as a
 * JSON-of-a-string and read back as a scalar (breaking `->{$code}->title`, and
 * fataling the clone-merge on re-import). The raw object is assigned instead and
 * the cast encodes it once — the same correct value the export reads back.
 *
 * Counts + a human message are returned; side-effects that differ per surface
 * (owner log_activity vs reseller auditStore + cache bust) stay in each caller.
 *
 * rev/multi-vertical Lane A7: five OPTIONAL columns — `brand`, `barcode`,
 * `weight`, `dimensions` (JSON object), `attributes` (JSON object) and
 * `variants` (JSON array of {options,price,sku,stock_qty,barcode}) — round-trip
 * a retail catalog (RetailItemController::exportCsv writes exactly these).
 * Every one is applied ONLY when its column exists in the uploaded file's
 * header, so a legacy food CSV or the reseller console's own export (neither
 * of which ever had these columns) is completely unaffected.
 */
trait ImportsMenuCsvCore
{
    /**
     * Parse the uploaded CSV into $store's menu and create/update rows.
     *
     * @return array{success: bool, message: string, imported: int, updated: int, skipped: int}
     */
    protected function importMenuCsvInto(Request $request, Store $store): array
    {
        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $file = $request->file('csv_file');
        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false || $contents === '') {
            return ['success' => false, 'message' => ___('Invalid CSV file.'), 'imported' => 0, 'updated' => 0, 'skipped' => 0];
        }

        // (a) Normalise the raw bytes to UTF-8: strip a BOM, and when the file is not
        // valid UTF-8 convert it from Windows-1256 (Arabic Excel) with a Windows-1252
        // fallback — legacy CSVs otherwise arrive as mojibake and never match a column.
        $contents = $this->csvConvertToUtf8($contents);

        // Parse on a php://temp stream so the delimiter can be sniffed from the header
        // line first, then fgetcsv reads the (re-encoded) bytes straight from memory.
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $headerLine = fgets($handle);
        if ($headerLine === false || trim($headerLine) === '') {
            fclose($handle);

            return ['success' => false, 'message' => ___('Invalid CSV file.'), 'imported' => 0, 'updated' => 0, 'skipped' => 0];
        }
        $delimiter = $this->sniffCsvDelimiter($headerLine);

        rewind($handle);
        $header = fgetcsv($handle, null, $delimiter, escape: '\\');
        if (! $header) {
            fclose($handle);

            return ['success' => false, 'message' => ___('Invalid CSV file.'), 'imported' => 0, 'updated' => 0, 'skipped' => 0];
        }

        // (c) Normalise headers (trim, lower-case, spaces/dashes -> underscore), then map
        // aliases — never renaming a column whose canonical name is already present, so a
        // catalog CSV carrying both `price` and `cost` keeps `cost` as its own column.
        $normalized = array_map([$this, 'normalizeCsvHeaderKey'], $header);
        $header = array_map(fn ($key) => $this->applyCsvHeaderAlias($key, $normalized), $normalized);

        // Detect translation columns (name_ar, description_fr, etc.) after normalising.
        $translationLangs = [];
        foreach ($header as $col) {
            if (preg_match('/^name_([a-z]{2,3})$/', $col, $m)) {
                $translationLangs[] = $m[1];
            }
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        // R2-1: limits resolve against the STORE OWNER, not auth() — the reseller
        // enforces the customer store's plan (owner path: owner === auth, unchanged).
        $owner = $store->user;
        $limit = effective_limit('menu', $owner);
        $total = Menu::where('store_id', $store->id)->count();
        // Categories auto-created from the `category` name column must respect the
        // plan category limit too — the item limit alone let a CSV mint categories
        // past the cap.
        $catLimit = effective_limit('category', $owner);
        $catCount = MenuCategory::where('store_id', $store->id)->count();

        $headerCount = count($header);
        $rowNum = 1; // header is row 1
        while (($row = fgetcsv($handle, null, $delimiter, escape: '\\')) !== false) {
            $rowNum++;
            // Skip blank lines (fgetcsv yields [null] for an empty line).
            if (count(array_filter($row, fn ($c) => $c !== null && $c !== '')) === 0) {
                continue;
            }
            // Reconcile ragged rows against the header so array_combine cannot throw
            // (a differing cell count is a 500 in PHP 8): pad short rows, drop extras.
            if (count($row) < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif (count($row) > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }
            // Undo the export's formula-neutralizing quote so export -> re-import round-trips.
            $row = array_map([DatasetStreamWriter::class, 'csvUncell'], $row);
            $data = array_combine($header, $row);

            // Resolve the category. Prefer a numeric category_id, but also accept a
            // human-friendly `category` NAME column (created on demand) so operators can
            // hand-write a file without looking up ids.
            $catId = (int) ($data['category_id'] ?? 0);
            if (! $catId && trim($data['category'] ?? '') !== '') {
                $catName = trim($data['category']);
                $cat = MenuCategory::where('store_id', $store->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($catName)])->first();
                if (! $cat) {
                    if (! Entitlements::isUnlimitedSentinel($catLimit) && $catCount >= (int) $catLimit) {
                        // At the category cap: import the item uncategorised rather
                        // than minting a category past the plan limit. Reported once.
                        $errors[] = ___('Category limit reached — new categories were not created.');
                    } else {
                        $cat = MenuCategory::create([
                            'store_id' => $store->id,
                            'name' => mb_substr($catName, 0, 255),
                        ]);
                        $catCount++;
                    }
                }
                $catId = $cat->id ?? 0;
            }

            // Validate a supplied numeric category belongs to this restaurant. A
            // foreign / unknown category is never trusted from the file — skip it.
            if ($catId && ! MenuCategory::where('id', $catId)->where('store_id', $store->id)->exists()) {
                $errors[] = ___('Row :row: category :id not found.', ['row' => $rowNum, 'id' => $catId]);
                $skipped++;

                continue;
            }

            $name = trim($data['name'] ?? '');
            // P6-221 + P6-272: a non-numeric price must not become a free item; locale prices
            // (Arabic-Indic digits, decimal comma, thousands separators) go through parseCsvPrice.
            $rawPrice = $data['price'] ?? null;
            $price = 0.0;
            if ($rawPrice !== null && trim((string) $rawPrice) !== '') {
                $parsed = $this->parseCsvPrice($rawPrice);
                if ($parsed === null) {
                    $errors[] = ___('Row :row: skipped — the price ":price" is not a number.', ['row' => $rowNum, 'price' => mb_substr((string) $rawPrice, 0, 40)]);
                    $skipped++;

                    continue;
                }
                $price = $parsed;
            }
            $badTime = null;
            foreach (['available_from', 'available_to'] as $timeCol) {
                $tv = trim((string) ($data[$timeCol] ?? ''));
                if ($tv !== '' && ! preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $tv)) {
                    $badTime = $tv;
                }
            }
            if ($badTime !== null) {
                $errors[] = ___('Row :row: skipped — ":time" is not a valid time of day.', ['row' => $rowNum, 'time' => mb_substr($badTime, 0, 40)]);
                $skipped++;

                continue;
            }
            if (! $name || $price < 0) {
                $errors[] = ___('Row :row: skipped — a name and a non-negative price are required.', ['row' => $rowNum]);
                $skipped++;

                continue;
            }

            // Build translations object from name_{code}/description_{code} columns
            $translations = null;
            if ($translationLangs) {
                $translations = new \stdClass;
                foreach ($translationLangs as $code) {
                    $tName = trim($data['name_'.$code] ?? '');
                    $tDesc = trim($data['description_'.$code] ?? '');
                    if ($tName !== '' || $tDesc !== '') {
                        $translations->{$code} = (object) [
                            'title' => $tName,
                            'description' => $tDesc,
                        ];
                    }
                }
                if (! get_object_vars($translations)) {
                    $translations = null;
                }
            }

            $fields = [
                'store_id' => $store->id,
                'category_id' => $catId ?: null,
                'name' => mb_substr($name, 0, 255),
                'description' => ! empty($data['description']) ? strip_tags($data['description'], '<b><strong><em><i><br><p><ul><ol><li><span>') : null,
                'price' => $price,
                'type' => in_array($data['type'] ?? '', ['veg', 'nonveg']) ? $data['type'] : 'veg',
                'active' => ($data['active'] ?? '1') == '1' ? '1' : '0',
                'is_vegan' => ! empty($data['is_vegan']) ? 1 : 0,
                'is_halal' => ! empty($data['is_halal']) ? 1 : 0,
                'is_gluten_free' => ! empty($data['is_gluten_free']) ? 1 : 0,
                'is_popular' => ! empty($data['is_popular']) ? 1 : 0,
                'is_sold_out' => ! empty($data['is_sold_out']) ? 1 : 0,
                'is_daily_special' => ! empty($data['is_daily_special']) ? 1 : 0,
                'spice_level' => (int) ($data['spice_level'] ?? 0),
                'available_from' => ! empty($data['available_from']) ? $data['available_from'] : null,
                'available_to' => ! empty($data['available_to']) ? $data['available_to'] : null,
                'available_days' => ! empty($data['available_days']) ? $data['available_days'] : null,
                'calories' => is_numeric($data['calories'] ?? '') ? (int) $data['calories'] : null,
                'protein' => is_numeric($data['protein'] ?? '') ? (float) $data['protein'] : null,
                'carbs' => is_numeric($data['carbs'] ?? '') ? (float) $data['carbs'] : null,
                'fat' => is_numeric($data['fat'] ?? '') ? (float) $data['fat'] : null,
                'allergies' => ! empty($data['allergies']) ? $data['allergies'] : null,
            ];

            // rev/multi-vertical Lane A7: optional retail columns — present only
            // in a catalog exported by RetailItemController::exportCsv (or a
            // hand-written file that opts in). Every key is set ONLY when the
            // column exists in this file's header, so a legacy food CSV (or the
            // reseller console's own export, which never had these columns)
            // round-trips through this exact same engine unaffected.
            if (array_key_exists('brand', $data) && trim((string) $data['brand']) !== '') {
                $brandName = trim((string) $data['brand']);
                $brand = Brand::where('store_id', $store->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($brandName)])->first();
                if (! $brand) {
                    $brand = Brand::create(['store_id' => $store->id, 'name' => mb_substr($brandName, 0, 255), 'active' => true]);
                }
                $fields['brand_id'] = $brand->id;
            }
            if (array_key_exists('barcode', $data)) {
                $fields['barcode'] = trim((string) $data['barcode']) !== '' ? trim((string) $data['barcode']) : null;
            }
            if (array_key_exists('weight', $data) && is_numeric($data['weight'])) {
                $fields['weight'] = (float) $data['weight'];
            }
            if (array_key_exists('dimensions', $data) && trim((string) $data['dimensions']) !== '') {
                $decoded = json_decode((string) $data['dimensions'], true);
                if (is_array($decoded)) {
                    $fields['dimensions'] = $decoded;
                }
            }
            if (array_key_exists('attributes', $data) && trim((string) $data['attributes']) !== '') {
                $decoded = json_decode((string) $data['attributes'], true);
                if (is_array($decoded)) {
                    $fields['attributes'] = $decoded;
                }
            }
            // Variants are synced AFTER the row is written (needs the menu id) —
            // stash the decoded spec here and apply it below via syncVariantsFromCsv().
            $variantSpecs = null;
            if (array_key_exists('variants', $data) && trim((string) $data['variants']) !== '') {
                $decodedVariants = json_decode((string) $data['variants'], true);
                if (is_array($decodedVariants)) {
                    $variantSpecs = $decodedVariants;
                }
            }

            if ($translations) {
                // `translations` is an object-cast column: assign the RAW object so the
                // cast encodes it exactly once (a pre-encoded string double-encodes and
                // reads back as a scalar — see the class docblock).
                $fields['translations'] = $translations;
            }

            // Update existing item if id present and belongs to this restaurant
            $existingId = (int) ($data['id'] ?? 0);
            if ($existingId) {
                $existing = Menu::where('id', $existingId)->where('store_id', $store->id)->first();
                if ($existing) {
                    if ($translations) {
                        // Merge onto whatever the row already carries. Legacy rows may
                        // hold a double-encoded STRING — normalise to an object first so
                        // the clone below never fatals on a scalar.
                        // Read raw so a legacy double-encoded row (stored as a
                        // JSON string, not an object) is normalised before the clone.
                        $existingTrans = $existing->getAttributeValue('translations');
                        if (is_string($existingTrans)) {
                            $existingTrans = json_decode($existingTrans);
                        }
                        $merged = is_object($existingTrans) ? clone $existingTrans : new \stdClass;
                        foreach (get_object_vars($translations) as $code => $trans) {
                            $merged->{$code} = $trans;
                        }
                        $fields['translations'] = $merged;
                    }
                    unset($fields['store_id']);
                    $existing->update($fields);
                    if ($variantSpecs !== null) {
                        $this->syncVariantsFromCsv($existing, $variantSpecs);
                    }
                    $updated++;

                    continue;
                }
            }

            if (! Entitlements::isUnlimitedSentinel($limit) && $total >= (int) $limit) { // P6-1
                $errors[] = ___('Menu item limit reached.');
                break;
            }

            $fields['image'] = 'default.png';
            $created = Menu::create($fields);
            if ($variantSpecs !== null) {
                $this->syncVariantsFromCsv($created, $variantSpecs);
            }
            $imported++;
            $total++;
        }
        fclose($handle);

        $msg = ___(':count items imported successfully.', ['count' => $imported]);
        if ($updated) {
            $msg .= ' '.___(':count items updated.', ['count' => $updated]);
        }
        if ($skipped) {
            $msg .= ' '.___(':count rows skipped.', ['count' => $skipped]);
        }
        // Show up to the first 10 distinct row reasons so the operator can fix the file
        // without the message ballooning on a badly-formed export.
        $reasons = array_values(array_unique($errors));
        if ($reasons) {
            $shown = array_slice($reasons, 0, 10);
            $msg .= ' '.implode(' ', $shown);
            if (count($reasons) > count($shown)) {
                $msg .= ' '.___('(+:count more)', ['count' => count($reasons) - count($shown)]);
            }
        }

        return [
            'success' => true,
            'message' => $msg,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /** @var array<string, string> header aliases applied after normalising (see normalizeCsvHeaderKey). */
    private const CSV_HEADER_ALIASES = [
        'item_name' => 'name', 'itemname' => 'name', 'product' => 'name', 'product_name' => 'name', 'title' => 'name',
        'cost' => 'price', 'amount' => 'price', 'item_price' => 'price', 'unit_price' => 'price',
        'category_name' => 'category', 'section' => 'category',
        'desc' => 'description',
    ];

    /**
     * Convert raw CSV bytes to UTF-8: strip a UTF-8 BOM, and when the bytes are not
     * valid UTF-8 re-encode from Windows-1256 (falling back to Windows-1252).
     */
    protected function csvConvertToUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        // mbstring has no Arabic codepage on PHP 8.4; iconv does (CP1256), then CP1252 as a last resort.
        foreach (['CP1256', 'CP1252'] as $from) {
            $converted = @iconv($from, 'UTF-8//IGNORE', $contents);
            if ($converted !== false && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return $contents;
    }

    /**
     * Sniff the CSV delimiter among comma, semicolon and tab by counting each on the
     * header line outside quoted fields; the most frequent wins (comma by default).
     */
    protected function sniffCsvDelimiter(string $headerLine): string
    {
        $best = ',';
        $bestCount = -1;

        foreach ([',', ';', "\t"] as $candidate) {
            $count = $this->countCsvDelimiterOutsideQuotes($headerLine, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Count $delimiter occurrences on a line that sit outside double-quoted fields
     * (so a comma inside a quoted name is not mistaken for a column separator).
     */
    protected function countCsvDelimiterOutsideQuotes(string $line, string $delimiter): int
    {
        $count = 0;
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                if ($i + 1 < $length && $line[$i + 1] === '"') {
                    $i++;

                    continue;
                }
                $inQuotes = ! $inQuotes;
            } elseif ($char === $delimiter && ! $inQuotes) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Normalise a single header cell: trim, lower-case, spaces/dashes to underscore.
     */
    protected function normalizeCsvHeaderKey(?string $raw): string
    {
        $key = mb_strtolower(trim((string) $raw));
        $key = (string) preg_replace('/[\s\-]+/u', '_', $key);

        return trim($key, '_');
    }

    /**
     * Map a normalised header key through CSV_HEADER_ALIASES, but leave it as-is when
     * the alias target is already a column of this file (so `cost` next to `price`
     * stays the distinct cost column instead of clobbering the price).
     *
     * @param  list<string>  $all
     */
    protected function applyCsvHeaderAlias(string $key, array $all): string
    {
        $target = self::CSV_HEADER_ALIASES[$key] ?? null;
        if ($target !== null && in_array($target, $all, true)) {
            return $key;
        }

        return $target ?? $key;
    }

    /**
     * Locale-tolerant price parser for the CSV. Returns a float or null when the cell
     * carries no usable number. Handles "1,500" (thousands), "12,50" (decimal comma),
     * "1.500,25" / "1,500.25" and Arabic-Indic digits; currency letters/symbols/spaces
     * are ignored. A lone separator with exactly three trailing digits is grouping
     * ("25.000"); the rightmost separator is otherwise the decimal point.
     */
    protected function parseCsvPrice(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }

        // Arabic-Indic (U+0660–U+0669) and Persian (U+06F0–U+06F9) digits plus the
        // Arabic decimal (U+066B) and thousands (U+066C) separators to ASCII, then
        // drop whitespace (NBSP included) — currency letters/symbols are discarded by
        // the number-run match below, so "د.إ 10" reads as 10, not ".10".
        $clean = strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => ',',
        ]);
        $clean = (string) preg_replace('/\s+/u', '', $clean);

        // Take the first number run ("SR 15" → 15, "1.500,25" → 1.500,25).
        if (! preg_match('/-?\d[\d.,]*/', $clean, $m)) {
            return null;
        }
        $number = $m[0];

        $lastDot = strrpos($number, '.');
        $lastComma = strrpos($number, ',');
        $decimalPos = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);

        if ($decimalPos === -1) {
            return (float) $number;
        }

        $separators = substr_count($number, '.') + substr_count($number, ',');
        $digitsAfter = strlen($number) - $decimalPos - 1;

        // A lone separator with exactly three trailing digits is grouping ("1,500").
        if ($separators === 1 && $digitsAfter === 3) {
            return (float) str_replace([',', '.'], '', $number);
        }

        $integer = str_replace([',', '.'], '', substr($number, 0, $decimalPos));
        $fraction = substr($number, $decimalPos + 1);

        return (float) ($integer.'.'.$fraction);
    }

    /**
     * rev/multi-vertical Lane A7 — apply the decoded `variants` CSV cell onto
     * $menu's variant rows. Matched by SKU when the spec carries one (stable
     * across a re-import even if options text changes), else by its options
     * combination (mirrors VariantMatrixGenerator's signature so an export→
     * import round-trip never duplicates a cell). Unmatched specs are created.
     *
     * @param  list<array{options?: array<string,string>, price?: mixed, sku?: ?string, stock_qty?: mixed, barcode?: ?string}>  $specs
     */
    private function syncVariantsFromCsv(Menu $menu, array $specs): void
    {
        $existing = MenuVariant::where('menu_id', $menu->id)->get();

        foreach ($specs as $spec) {
            $options = (array) ($spec['options'] ?? []);
            $sku = isset($spec['sku']) && $spec['sku'] !== '' ? (string) $spec['sku'] : null;

            $match = $sku ? $existing->first(fn (MenuVariant $v) => $v->sku === $sku) : null;
            if (! $match && $options) {
                $match = $existing->first(function (MenuVariant $v) use ($options) {
                    $current = (array) (is_object($v->options) ? get_object_vars($v->options) : ($v->options ?? []));

                    return $current == $options;
                });
            }

            $attrs = [
                'menu_id' => $menu->id,
                'options' => (object) $options,
                'price' => is_numeric($spec['price'] ?? null) ? (float) $spec['price'] : ($match->price ?? 0),
                'sku' => $sku,
                'stock_qty' => isset($spec['stock_qty']) && $spec['stock_qty'] !== '' ? (int) $spec['stock_qty'] : null,
                'barcode' => isset($spec['barcode']) && $spec['barcode'] !== '' ? (string) $spec['barcode'] : null,
                'active' => true,
            ];

            if ($match) {
                $match->update($attrs);
            } else {
                $created = MenuVariant::create($attrs);
                $existing->push($created);
            }
        }
    }
}
