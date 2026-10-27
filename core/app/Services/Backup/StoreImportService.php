<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Backup;

use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Integrations\Models\StoreImport;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use ZipArchive;

/**
 * Restore/import counterpart to StoreBackupService (WI-E1). Reads a backup ZIP
 * an owner uploaded and, on explicit confirm, upserts its SAFE self-contained
 * datasets into a TARGET store the owner owns.
 *
 * Safety guarantees:
 *  - Additive / UPSERT by natural key — never destructive, never overwrites
 *    another store's rows. Every write uses the TARGET store's ids; foreign keys
 *    (category parent, item→category, extra→item) are re-mapped, never copied.
 *  - Ownership is enforced at the controller; this service only ever writes to
 *    the target store_id / owner user_id it is handed.
 *  - Zip-bomb guarded (entry count + uncompressed size + ratio); no path
 *    traversal on extract; secret settings are never imported.
 *  - Orders / customers / financial datasets are intentionally NOT imported in
 *    v1 (they carry money/PII and cross-entity references); they are listed as
 *    skipped in the plan so the owner sees the boundary.
 */
class StoreImportService
{
    /** Datasets imported in v1 (export file => importer method). */
    public const SAFE = [
        'menu_categories' => 'importCategories',
        'menu_items' => 'importItems',
        'menu_extras' => 'importExtras',
        'tables' => 'importTables',
        'coupons' => 'importCoupons',
        'settings' => 'importSettings',
    ];

    /** Datasets present in a backup that v1 deliberately does NOT import. */
    public const DEFERRED = [
        'orders', 'order_items', 'customers', 'loyalty_accounts', 'loyalty_ledger',
        'gift_cards', 'reservations', 'hotel_stays', 'meal_plans', 'catering_requests',
        'expenses', 'gl_journals', 'gl_journal_lines', 'payroll_runs', 'payroll_items',
    ];

    // Zip-bomb / upload guards.
    public const MAX_ENTRIES = 20000;

    public const MAX_UNCOMPRESSED = 1073741824; // 1 GiB

    public const MAX_RATIO = 200;               // uncompressed:compressed

    public const MAX_ENTRY_BYTES = 134217728;   // 128 MiB — hard read ceiling per entry

    public const MEDIA_RESTORE_CAP = 524288000;  // 500 MiB total restored to disk

    /** Only these extensions may be written back to disk on import (no svg/html). */
    public const MEDIA_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'pdf', 'mp3', 'm4a', 'ogg', 'wav', 'aac', 'oga'];

    /** Remap state for the current apply run: old id => new id. */
    private array $categoryMap = [];

    private array $itemMap = [];

    /**
     * Open + validate the archive, read its manifest, guard against zip bombs.
     * Returns [manifest, crossStore]. Throws on any rejection.
     */
    public function validate(StoreImport $import, Store $target): array
    {
        $abs = storage_path('app/'.$import->path);
        abort_unless(is_file($abs), 422, 'Uploaded archive not found.');

        $zip = new ZipArchive;
        if ($zip->open($abs) !== true) {
            throw new \RuntimeException('The uploaded file is not a valid ZIP archive.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new \RuntimeException('Archive has too many entries.');
            }
            $uncompressed = 0;
            $compressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                if (str_contains($stat['name'], '..')) {
                    throw new \RuntimeException('Archive contains an unsafe path.');
                }
                $uncompressed += (int) $stat['size'];
                $compressed += (int) $stat['comp_size'];
                if ($uncompressed > self::MAX_UNCOMPRESSED) {
                    throw new \RuntimeException('Archive is too large when uncompressed.');
                }
            }
            if ($compressed > 0 && $uncompressed / max(1, $compressed) > self::MAX_RATIO) {
                throw new \RuntimeException('Archive compression ratio looks like a zip bomb.');
            }

            $manifestRaw = $zip->getFromName('manifest.json');
            if ($manifestRaw === false) {
                throw new \RuntimeException('Archive has no manifest — it is not a store backup.');
            }
            $manifest = json_decode($manifestRaw, true);
            if (! is_array($manifest)) {
                throw new \RuntimeException('Archive manifest is unreadable.');
            }

            $crossStore = (int) ($manifest['store_id'] ?? 0) !== (int) $target->id;

            return [$manifest, $crossStore];
        } finally {
            $zip->close();
        }
    }

    /**
     * Dry-run: count what WOULD be created / updated / skipped per dataset, and
     * list the datasets present-but-deferred. Writes nothing.
     */
    public function plan(StoreImport $import, Store $target): array
    {
        $abs = storage_path('app/'.$import->path);
        $zip = new ZipArchive;
        abort_unless($zip->open($abs) === true, 422, 'Archive unreadable.');

        $plan = [];
        try {
            foreach (self::SAFE as $file => $_method) {
                $rows = $this->readRows($zip, $file);
                if ($rows === null) {
                    continue; // dataset not present in this archive
                }
                $plan[$file] = [
                    'present' => true,
                    'rows' => count($rows),
                    'action' => 'upsert',
                ];
            }
            foreach (self::DEFERRED as $file) {
                // Presence only — never decode a big deferred file (orders,
                // order_items, customers) in the synchronous preview.
                if ($this->hasEntry($zip, $file)) {
                    $plan[$file] = ['present' => true, 'rows' => null, 'action' => 'skipped (not imported in v1)'];
                }
            }
        } finally {
            $zip->close();
        }

        return $plan;
    }

    /**
     * Apply the import: upsert the safe datasets into the target store inside a
     * transaction. Returns a per-dataset summary of created/updated counts.
     */
    public function apply(StoreImport $import, Store $target): array
    {
        $abs = storage_path('app/'.$import->path);
        $zip = new ZipArchive;
        abort_unless($zip->open($abs) === true, 422, 'Archive unreadable.');

        $this->categoryMap = [];
        $this->itemMap = [];
        $summary = [];

        // HPMS E14(e): honour the owner's confirm-time dataset picker. NULL = every
        // safe dataset (back-compat); a set restricts the restore to the ticked ones.
        $only = is_array($import->only_datasets) ? $import->only_datasets : null;
        $wants = fn (string $dataset) => $only === null || in_array($dataset, $only, true);

        try {
            DB::transaction(function () use ($zip, $target, &$summary, $wants) {
                foreach (self::SAFE as $file => $method) {
                    if (! $wants($file)) {
                        continue; // owner deselected this dataset at confirm
                    }
                    $rows = $this->readRows($zip, $file);
                    if ($rows === null) {
                        continue;
                    }
                    $summary[$file] = $this->{$method}($rows, $target);
                }
            });

            // Media restore is best-effort and outside the DB transaction.
            if ($wants('media')) {
                $summary['media'] = $this->restoreMedia($zip, $target);
            }
        } finally {
            $zip->close();
        }

        return $summary;
    }

    /**
     * Read + decode a dataset file's JSON rows, or null if absent. The read is
     * hard-capped at MAX_ENTRY_BYTES via getFromName's length parameter so a
     * lying zip-bomb entry is truncated (and then fails to decode) instead of
     * being inflated whole into memory (WI-E1 robustness).
     */
    private function readRows(ZipArchive $zip, string $file): ?array
    {
        if ($zip->locateName($file.'.json') === false) {
            return null;
        }
        $raw = $zip->getFromName($file.'.json', self::MAX_ENTRY_BYTES);
        if ($raw === false) {
            return null;
        }
        $rows = json_decode($raw, true);

        return is_array($rows) ? $rows : [];
    }

    /** Cheap presence check that never decodes the entry. */
    private function hasEntry(ZipArchive $zip, string $file): bool
    {
        return $zip->locateName($file.'.json') !== false;
    }

    private function importCategories(array $rows, Store $target): array
    {
        $created = 0;
        $updated = 0;
        // First pass: upsert by name, build old->new id map.
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $cat = MenuCategory::firstOrNew(['store_id' => $target->id, 'name' => $name]);
            $exists = $cat->exists;
            $cat->fill(['position' => $row['position'] ?? $cat->position, 'active' => $row['active'] ?? ($cat->active ?? '1')]);
            if (isset($row['translations'])) {
                $cat->translations = $row['translations'];
            }
            $cat->save();
            $this->categoryMap[(int) ($row['id'] ?? 0)] = $cat->id;
            $exists ? $updated++ : $created++;
        }
        // Second pass: re-map parent within the imported set.
        foreach ($rows as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            $oldParent = (int) ($row['parent'] ?? 0);
            if ($oldParent && isset($this->categoryMap[$oldId], $this->categoryMap[$oldParent])) {
                MenuCategory::where('id', $this->categoryMap[$oldId])
                    ->update(['parent' => $this->categoryMap[$oldParent]]);
            }
        }

        return compact('created', 'updated');
    }

    private function importItems(array $rows, Store $target): array
    {
        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $categoryId = $this->categoryMap[(int) ($row['category_id'] ?? 0)] ?? null;
            $item = Menu::firstOrNew([
                'store_id' => $target->id, 'name' => $name, 'category_id' => $categoryId,
            ]);
            $exists = $item->exists;
            $item->fill([
                'price' => $row['price'] ?? $item->price,
                'position' => $row['position'] ?? $item->position,
                'active' => $row['active'] ?? ($item->active ?? '1'),
                'image' => $row['image'] ?? $item->image,
            ]);
            if (isset($row['translations'])) {
                $item->translations = $row['translations'];
            }
            $item->save();
            $this->itemMap[(int) ($row['id'] ?? 0)] = $item->id;
            $exists ? $updated++ : $created++;
        }

        return compact('created', 'updated');
    }

    private function importExtras(array $rows, Store $target): array
    {
        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            $menuId = $this->itemMap[(int) ($row['menu_id'] ?? 0)] ?? null;
            if ($title === '' || ! $menuId) {
                continue; // extras hang off a menu item we imported
            }
            $extra = MenuExtra::firstOrNew([
                'store_id' => $target->id, 'menu_id' => $menuId, 'title' => $title,
            ]);
            $exists = $extra->exists;
            $extra->fill(['price' => $row['price'] ?? $extra->price, 'position' => $row['position'] ?? $extra->position]);
            $extra->save();
            $exists ? $updated++ : $created++;
        }

        return compact('created', 'updated');
    }

    private function importTables(array $rows, Store $target): array
    {
        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $no = $row['table_no'] ?? null;
            if ($no === null) {
                continue;
            }
            $table = StoreTable::firstOrNew(['store_id' => $target->id, 'table_no' => $no]);
            $exists = $table->exists;
            $table->save();
            $exists ? $updated++ : $created++;
        }

        return compact('created', 'updated');
    }

    private function importCoupons(array $rows, Store $target): array
    {
        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            // Attach to the TARGET owner; drop the source branch binding (owner-wide).
            $coupon = Coupon::firstOrNew(['user_id' => $target->user_id, 'code' => $code]);
            $exists = $coupon->exists;
            // The value means what its type says (a percent, or an amount): carry the type with it — a fixed
            // coupon used to come back as a percentage one. A backup without `percentage` keeps the target's.
            $coupon->fill([
                'coupon_type' => $row['coupon_type'] ?? $coupon->coupon_type ?? 'percentage',
                'percentage' => $row['percentage'] ?? $coupon->percentage,
                'limit' => $row['limit'] ?? $coupon->limit,
                'branch_id' => null,
            ]);
            $coupon->save();
            $exists ? $updated++ : $created++;
        }

        return compact('created', 'updated');
    }

    private function importSettings(array $rows, Store $target): array
    {
        $created = 0;
        $updated = 0;
        $secretFlip = array_flip(GatewaySecretVault::secretKeys());
        $row = $rows[0] ?? [];
        foreach ($row as $name => $value) {
            if (isset($secretFlip[$name]) || $this->looksSecret((string) $name)) {
                continue; // never import a credential
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            // P1-07: gateway settings live in store_payment_gateways (credentials already skipped above).
            if (StorePaymentGateways::handles((string) $name)) {
                $exists = StorePaymentGateways::get((int) $target->id, (string) $name) !== null;
                StorePaymentGateways::put((int) $target->id, (string) $name, $value);
                $exists ? $updated++ : $created++;

                continue;
            }
            $exists = StoreSettings::has((int) $target->id, (string) $name);
            StoreSettings::set((int) $target->id, (string) $name, $value);
            StoreOption::flushOptionMemo();
            $exists ? $updated++ : $created++;
        }

        return compact('created', 'updated');
    }

    private function looksSecret(string $name): bool
    {
        $patterns = ['password', 'secret', 'token', 'api_key', 'apikey', '_key', 'private',
            'credential', 'cvv', 'card_number', 'iban', 'salt', 'hash', 'signature',
            'access_token', 'refresh_token', 'client_secret'];

        return array_any($patterns, fn ($pat) => str_contains(strtolower($name), $pat));
    }

    /**
     * Best-effort media restore, HARDENED (WI-E1 security):
     *  - only allowlisted media extensions are written (no .html/.svg/.php/etc.),
     *    so an uploaded ZIP can't plant a web-served script (stored XSS);
     *  - files land under a per-TARGET-store subfolder, never the shared media
     *    root, so an import can't overwrite another store's live asset;
     *  - an existing file is never clobbered (skip if present);
     *  - each copy is length-capped and the total is bounded, so a lying entry
     *    can't fill the disk.
     */
    private function restoreMedia(ZipArchive $zip, Store $target): array
    {
        $restored = 0;
        $skipped = 0;
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || ! str_starts_with($name, 'media/') || str_ends_with($name, '/') || str_contains($name, '..')) {
                continue;
            }

            $basename = basename($name);
            $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            if (! in_array($ext, self::MEDIA_EXT, true)) {
                $skipped++;

                continue; // never write a non-media file back to the web tree
            }

            $category = explode('/', $name)[1] ?? 'menu';
            $dest = match ($category) {
                'restaurant' => 'restaurant',
                default => 'menu',
            };
            // Per-target-store subfolder — cannot clobber another store's assets.
            $dir = storage_path('app/public/'.$dest.'/'.$target->id);
            $path = $dir.'/'.$basename;
            if (is_file($path)) {
                $skipped++;

                continue; // never overwrite an existing file
            }
            if ($total >= self::MEDIA_RESTORE_CAP) {
                $skipped++;

                continue;
            }

            $stream = $zip->getStream($name);
            if (! $stream) {
                continue;
            }
            @mkdir($dir, 0755, true);
            $out = @fopen($path, 'wb');
            if ($out) {
                $copied = stream_copy_to_stream($stream, $out, self::MEDIA_RESTORE_CAP - $total);
                fclose($out);
                $total += (int) $copied;
                $restored++;
            }
            fclose($stream);
        }

        return ['restored' => $restored, 'skipped' => $skipped, 'bytes' => $total];
    }
}
