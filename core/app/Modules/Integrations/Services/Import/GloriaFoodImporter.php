<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Import;

use App\Models\ImportBatch;
use App\Models\MenuCategory;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuAdminService;

/**
 * T7 GloriaFood menu importer. Parses the restaurant's menu export (CSV — the
 * dependency-free, CI-safe format; xlsx support is a lib-gated follow-up) into our menu
 * model. Always preview before commit; commit is idempotent per file hash so a re-upload
 * never duplicates. Header matching is flexible (GloriaFood column names vary by export).
 */
class GloriaFoodImporter
{
    /** Accepted header aliases → canonical field. */
    private const HEADERS = [
        'category' => ['category', 'category name', 'group', 'section'],
        'name' => ['item', 'name', 'item name', 'product', 'product name', 'dish'],
        'description' => ['description', 'desc', 'details'],
        'price' => ['price', 'amount', 'cost'],
    ];

    /** Parse CSV content → ['rows'=>[{category,name,description,price}], 'errors'=>[]]. */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // strip BOM
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (count($lines) < 2) {
            return ['rows' => [], 'errors' => [__('The file has no data rows.')]];
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), escape: '\\'));
        $map = $this->mapColumns($header);
        if (! isset($map['name']) || ! isset($map['price'])) {
            return ['rows' => [], 'errors' => [__('Could not find Name and Price columns.')]];
        }

        $rows = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, escape: '\\');
            $name = trim((string) ($cells[$map['name']] ?? ''));
            if ($name === '') {
                continue;
            }
            $price = $this->parsePrice($cells[$map['price']] ?? '0');
            $rows[] = [
                'category' => trim((string) ($cells[$map['category']] ?? __('Menu'))) ?: __('Menu'),
                'name' => $name,
                'description' => isset($map['description']) ? trim((string) ($cells[$map['description']] ?? '')) : '',
                'price' => $price,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** Dry-run preview: what would be created, without writing anything. */
    public function preview(int $storeId, string $csv): array
    {
        $parsed = $this->parse($csv);
        $rows = $parsed['rows'];

        $existingCats = MenuCategory::where('store_id', $storeId)
            ->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id])->all();
        $existingItems = Menu::where('store_id', $storeId)
            ->pluck('name')->map(fn ($n) => strtolower($n))->flip()->all();

        $newCats = [];
        $newItems = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $catKey = strtolower($r['category']);
            if (! isset($existingCats[$catKey]) && ! isset($newCats[$catKey])) {
                $newCats[$catKey] = $r['category'];
            }
            if (isset($existingItems[strtolower($r['name'])])) {
                $skipped++; // item already exists by name
            } else {
                $newItems++;
            }
        }

        return [
            'errors' => $parsed['errors'],
            'total_rows' => count($rows),
            'categories_created' => count($newCats),
            'items_created' => $newItems,
            'skipped' => $skipped,
            'sample' => array_slice($rows, 0, 10),
        ];
    }

    /**
     * Commit the import. Idempotent per (restaurant, file hash): a re-upload of the same
     * file returns the existing batch without creating anything. Existing items (by name)
     * are skipped, never duplicated.
     */
    public function import(int $storeId, string $csv): ImportBatch
    {
        $hash = hash('sha256', $csv);
        $existing = ImportBatch::where('store_id', $storeId)
            ->where('source', 'gloriafood')->where('kind', 'menu')->where('file_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }

        $rows = $this->parse($csv)['rows'];

        return $this->writeRows($storeId, $rows, $hash);
    }

    /**
     * Parse the public GloriaFood ordering-widget menu JSON into normalized rows.
     * Accepts the widget's embedded structure ({categories:[{name,items:[{name,price,description}]}]})
     * or a flat items array. Best-effort — real widget markup varies; the controller
     * fetches the URL through SsrfGuard before handing the payload here.
     */
    public function parseWidget(array $data): array
    {
        $rows = [];
        $categories = $data['categories'] ?? $data['menu']['categories'] ?? null;
        if (is_array($categories)) {
            foreach ($categories as $cat) {
                $catName = trim((string) ($cat['name'] ?? __('Menu'))) ?: __('Menu');
                foreach ((array) ($cat['items'] ?? $cat['products'] ?? []) as $item) {
                    $name = trim((string) ($item['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $rows[] = [
                        'category' => $catName,
                        'name' => $name,
                        'description' => trim((string) ($item['description'] ?? '')),
                        'price' => $this->parsePrice($item['price'] ?? 0),
                    ];
                }
            }
        }

        return $rows;
    }

    /** Idempotent import from widget-scraped rows. */
    public function importFromWidget(int $storeId, array $widgetJson): ImportBatch
    {
        $rows = $this->parseWidget($widgetJson);
        $hash = hash('sha256', 'widget:'.json_encode($rows));
        $existing = ImportBatch::where('store_id', $storeId)
            ->where('source', 'gloriafood')->where('kind', 'menu')->where('file_hash', $hash)->first();

        return $existing ?: $this->writeRows($storeId, $rows, $hash);
    }

    /** Shared row-writer: create categories/items idempotently, record a batch. */
    private function writeRows(int $storeId, array $rows, string $hash): ImportBatch
    {
        return DB::transaction(function () use ($storeId, $rows, $hash) {
            $cats = MenuCategory::where('store_id', $storeId)
                ->get()->keyBy(fn ($c) => strtolower($c->name));
            $items = Menu::where('store_id', $storeId)
                ->pluck('name')->map(fn ($n) => strtolower($n))->flip();

            $catsCreated = 0;
            $itemsCreated = 0;
            $skipped = 0;
            $pos = (int) MenuCategory::where('store_id', $storeId)->max('position');

            foreach ($rows as $r) {
                $catKey = strtolower($r['category']);
                if (! isset($cats[$catKey])) {
                    $cat = MenuAdminService::createCategory($storeId, ['name' => $r['category'], 'position' => ++$pos]);
                    $cats[$catKey] = $cat;
                    $catsCreated++;
                }
                if (isset($items[strtolower($r['name'])])) {
                    $skipped++;

                    continue;
                }
                MenuAdminService::createItem($storeId, [
                    'category_id' => $cats[$catKey]->id,
                    'name' => $r['name'],
                    'description' => $r['description'] ?: null,
                    'price' => $r['price'],
                    'active' => 1,
                ]);
                $items->put(strtolower($r['name']), true);
                $itemsCreated++;
            }

            return ImportBatch::create([
                'store_id' => $storeId, 'source' => 'gloriafood', 'kind' => 'menu',
                'file_hash' => $hash, 'status' => 'done',
                'categories_created' => $catsCreated, 'items_created' => $itemsCreated, 'skipped' => $skipped,
            ]);
        });
    }

    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $col) {
            foreach (self::HEADERS as $field => $aliases) {
                if (in_array($col, $aliases, true) && ! isset($map[$field])) {
                    $map[$field] = $idx;
                }
            }
        }

        return $map;
    }

    /**
     * Parse a price from an exported menu cell, in whichever convention the
     * source used.
     *
     * Blindly turning every comma into a dot destroyed any price carrying a
     * thousands separator: "1,250.00" became "1.250.00", which casts to 1.25 —
     * a 1,250 dish imported at one two-hundredth of its price, silently, on a
     * bulk import nobody re-reads line by line.
     *
     * Both conventions occur in real exports, so the LAST separator decides:
     *  - "1,250.00" / "1.250,00"  → 1250.00 (grouping + decimals)
     *  - "1250,50"  / "1250.50"   → 1250.50 (decimals only)
     *  - "25,000"   / "25.000"    → 25000   (a lone separator with exactly
     *                                        three digits after it is grouping)
     */
    private function parsePrice($raw): float
    {
        $clean = preg_replace('/[^0-9.,-]/', '', (string) $raw);
        if ($clean === '' || $clean === '-') {
            return 0.0;
        }

        $lastDot = strrpos($clean, '.');
        $lastComma = strrpos($clean, ',');
        $decimalPos = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);

        if ($decimalPos === -1) {
            return (float) $clean;
        }

        $separators = substr_count($clean, '.') + substr_count($clean, ',');
        $digitsAfter = strlen($clean) - $decimalPos - 1;

        // A single separator followed by exactly three digits is grouping
        // ("25,000"), not a decimal point — no currency quotes three decimals
        // this way in an export that also uses grouping.
        if ($separators === 1 && $digitsAfter === 3) {
            return (float) str_replace([',', '.'], '', $clean);
        }

        $integer = str_replace([',', '.'], '', substr($clean, 0, $decimalPos));
        $fraction = substr($clean, $decimalPos + 1);

        return (float) ($integer.'.'.$fraction);
    }
}
