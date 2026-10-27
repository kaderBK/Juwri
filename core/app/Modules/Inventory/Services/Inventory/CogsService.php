<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Services\Inventory;

use App\Models\Ingredient;
use App\Support\StoreClock;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\Supplier;

/**
 * SYSTEMS-RUN SUP-P2: COGS + variance, reorder suggestions, supplier spend.
 * Theoretical COGS = recipe cost × items sold (when recipes exist); actual =
 * opening stock + purchases − closing stock.
 */
class CogsService
{
    /** Purchases value received in the window (actual costs). */
    public function purchases(int $storeId, ?string $from = null, ?string $to = null): float
    {
        $exponent = Currencies::exponent(store_currency($storeId));
        $total = 0.0;
        PurchaseOrder::where('store_id', $storeId)
            ->tap(fn ($q) => StoreClock::whereLocalDateBetween($q, 'received_at', $storeId, $from, $to))
            ->with('items')->get()
            ->each(function ($po) use (&$total) {
                foreach ($po->items as $item) {
                    $total += (float) $item->qty_received * (float) ($item->actual_unit_cost ?? $item->unit_cost);
                }
            });

        return Money::round($total, $exponent);
    }

    /**
     * Actual COGS = opening + purchases − closing (stock values passed in, since
     * StockCount snapshots are period-bounded). P1-05: rounded HALF_UP to $currency's exponent (2 when unknown).
     */
    public function actualCogs(float $opening, float $purchases, float $closing, ?string $currency = null): float
    {
        return Money::round($opening + $purchases - $closing, self::exponent($currency));
    }

    /**
     * @return array{actual: float, theoretical: float, variance: float, variance_pct: float|null}
     */
    public function variance(float $actual, float $theoretical, ?string $currency = null): array
    {
        $diff = Money::round($actual - $theoretical, self::exponent($currency));
        $pct = $theoretical > 0 ? round($diff / $theoretical * 100, 1) : null;

        return ['actual' => $actual, 'theoretical' => $theoretical, 'variance' => $diff, 'variance_pct' => $pct];
    }

    /** Ingredients below par → suggested reorder quantities grouped by supplier. */
    public function reorderSuggestions(int $storeId): array
    {
        $below = Ingredient::where('store_id', $storeId)
            ->where('active', true)
            ->whereColumn('current_stock', '<', 'par_level')
            ->get();

        $grouped = [];
        foreach ($below as $ing) {
            $sid = $ing->supplier_id ?: 0;
            $grouped[$sid] ??= ['supplier_id' => $ing->supplier_id, 'supplier' => optional(Supplier::find($ing->supplier_id))->name, 'lines' => []];
            $grouped[$sid]['lines'][] = [
                'ingredient_id' => $ing->id,
                'name' => $ing->name,
                'suggest_qty' => round((float) $ing->par_level - (float) $ing->current_stock, 2),
                'unit_cost' => (float) $ing->cost_per_unit,
            ];
        }

        return array_values($grouped);
    }

    /** Spend per supplier in a window. */
    public function supplierSpend(int $storeId, ?string $from = null, ?string $to = null): array
    {
        $exponent = Currencies::exponent(store_currency($storeId));
        $spend = [];
        PurchaseOrder::where('store_id', $storeId)
            ->tap(fn ($q) => StoreClock::whereLocalDateBetween($q, 'received_at', $storeId, $from, $to))
            ->with('items', 'supplier')->get()
            ->each(function ($po) use (&$spend, $exponent) {
                $val = 0.0;
                foreach ($po->items as $item) {
                    $val += (float) $item->qty_received * (float) ($item->actual_unit_cost ?? $item->unit_cost);
                }
                $sid = $po->supplier_id ?: 0;
                $spend[$sid]['supplier'] = optional($po->supplier)->name;
                $spend[$sid]['total'] = Money::round(($spend[$sid]['total'] ?? 0) + $val, $exponent);
            });

        return array_values($spend);
    }

    /** A known currency's exponent; 2 (the old fixed precision) when the caller passes none. */
    private static function exponent(?string $currency): int
    {
        return $currency === null || $currency === '' ? Currencies::DEFAULT_EXPONENT : Currencies::exponent($currency);
    }
}
