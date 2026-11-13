<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Services\Qr;

use App\Models\StoreTable;
use Illuminate\Support\Str;

/**
 * PARITY-RUN T10-5: rotate a table's QR.
 *
 * `store_tables.key` IS the QR credential — `store_public_qr_url()` puts it
 * in `?table=`, and the storefront resolves a table by looking that key up. So
 * rotating the key is what kills a printed code: the old sticker still opens the
 * menu (it is a public menu) but no longer resolves to a table, which is exactly
 * the "this QR is dead" semantic the owner asked for when a code leaks or a
 * table is re-numbered.
 *
 * T3 SEAM (table OTP): a table also carries a rotating one-time verification
 * code. Rotating the QR must rotate that too, or a leaked code outlives the
 * sticker it was printed on. T3 has merged, so this now calls its real API:
 * `Juwri\Modules\Tables\Services\TableSessionService::onTableQrRotated($storeId, $tableKey)`.
 *
 * Note the argument: T3 resolves the table by its CURRENT key, so this must be
 * called AFTER the new key is persisted and must pass the NEW key. Passing the
 * pre-rotation key would silently match no row and leave the old code live —
 * exactly the failure this seam exists to prevent.
 */
class QrRotationService
{
    /**
     * Give one table a fresh key. Returns the new key.
     */
    public function rotateTable(StoreTable $table): string
    {
        $table->key = $this->uniqueKey();
        $table->save();

        $this->rotateTableCode($table);

        return $table->key;
    }

    /**
     * Rotate every table of a store (optionally one branch).
     *
     * @return int how many tables were rotated
     */
    public function rotateAll(int $storeId, ?int $branchId = null): int
    {
        $query = StoreTable::where('store_id', $storeId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $count = 0;
        foreach ($query->get() as $table) {
            $this->rotateTable($table);
            $count++;
        }

        return $count;
    }

    /** A key no other table holds. Same 13-char shape the rest of the app writes. */
    public function uniqueKey(): string
    {
        do {
            $key = Str::random(13);
        } while (StoreTable::where('key', $key)->exists());

        return $key;
    }

    /**
     * T3 seam — best effort, never fatal. A rotation that half-succeeded (new QR
     * printed, old OTP still valid) is worse than a rotation that logged and
     * moved on, so this is deliberately swallowed rather than surfaced.
     */
    private function rotateTableCode(StoreTable $table): void
    {
        $service = '\Juwri\Modules\Tables\Services\TableSessionService';
        if (! class_exists($service) || ! method_exists($service, 'onTableQrRotated')) {
            return;
        }

        try {
            // The table has already been saved with its new key, and T3 resolves
            // by the current key — so pass that, not the pre-rotation one.
            $service::onTableQrRotated((int) $table->store_id, (string) $table->key);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
