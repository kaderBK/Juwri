<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Services\Inventory;

use App\Models\Ingredient;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Inventory\Models\PurchaseOrder;

/**
 * SYSTEMS-RUN SUP-P1: receive stock against a PO. Records qty received + actual
 * price per line, applies the stock delta ONCE per line (idempotent), flags
 * qty/price discrepancies, and advances PO status open→partial→received.
 */
class ReceivingService
{
    /**
     * @param  array<int,array{item_id:int, qty_received:float, actual_unit_cost?:float}>  $lines
     * @return array{discrepancies: array, status: string}
     */
    public function receive(PurchaseOrder $po, array $lines): array
    {
        $byId = collect($lines)->keyBy('item_id');
        $discrepancies = [];
        $priceAlerts = [];
        $purchaseValue = 0.0;

        // P1-05: unit costs HALF_UP to the order currency's exponent (PHP-version independent).
        $exponent = Currencies::exponent($po->minorMoneyCurrency());
        DB::transaction(function () use ($po, $byId, &$discrepancies, &$priceAlerts, &$purchaseValue, $exponent) {
            foreach ($po->items as $item) {
                if (! $byId->has($item->id)) {
                    continue;
                }
                $row = $byId->get($item->id);
                $qty = round((float) ($row['qty_received'] ?? 0), 2);
                $actual = isset($row['actual_unit_cost']) ? Money::round((float) $row['actual_unit_cost'], $exponent) : (float) $item->unit_cost;

                // Apply only the incremental stock delta since last receive (idempotent).
                $alreadyApplied = (float) $item->qty_received;
                $delta = round($qty - $alreadyApplied, 2);

                $item->update(['qty_received' => $qty, 'actual_unit_cost' => $actual, 'stock_applied' => true]);

                if (abs($delta) > 0.001 && $item->ingredient_id) {
                    $ingredient = Ingredient::find($item->ingredient_id);
                    if ($ingredient) {
                        // SUP-P2: price-change alert vs last cost.
                        $lastCost = (float) $ingredient->cost_per_unit;
                        $threshold = (float) (store_options($po->store_id, 'price_change_alert_pct', 15) ?: 15) / 100;
                        if ($lastCost > 0 && $actual > $lastCost * (1 + $threshold)) {
                            $priceAlerts[] = ['ingredient_id' => $ingredient->id, 'old' => $lastCost, 'new' => $actual];
                        }
                        // SUP-P2: weighted-average cost maintained on receive.
                        $oldStock = (float) $ingredient->current_stock;
                        $newStock = $oldStock + $delta;
                        $wac = $newStock > 0
                            ? Money::round((($oldStock * $lastCost) + ($delta * $actual)) / $newStock, 4)
                            : $actual;

                        /* Move the stock through the ledger, not by writing
                           current_stock directly. Line receiving used to update
                           the balance in place and record nothing, so
                           stock_movements and current_stock drifted apart
                           permanently — every stock audit, variance report and
                           COGS reconciliation was short by whatever came in
                           through this path. recordMovement() applies the delta
                           AND writes the row, so the balance is only ever moved
                           in one way. */
                        InventoryService::recordMovement(
                            (int) $ingredient->id,
                            'purchase',
                            $delta,
                            [
                                'branch_id' => $po->branch_id, // P6-224: a branch's delivery stocks that branch
                                'unit_cost' => $actual,
                                'reference_type' => 'purchase_order',
                                'reference_id' => $po->id,
                                'reason' => 'PO #'.$po->id.' received (line)',
                            ]
                        );
                        // Weighted-average cost is this service's own concern.
                        $ingredient->refresh()->update(['cost_per_unit' => $wac]);
                        $purchaseValue += $delta * $actual;
                    }
                }

                // Discrepancy detection.
                if (abs($qty - (float) $item->quantity) > 0.001) {
                    $discrepancies[] = ['item_id' => $item->id, 'type' => 'qty', 'ordered' => (float) $item->quantity, 'received' => $qty];
                }
                if (abs($actual - (float) $item->unit_cost) > 0.001) {
                    $discrepancies[] = ['item_id' => $item->id, 'type' => 'price', 'expected' => (float) $item->unit_cost, 'actual' => $actual];
                }
            }

            $po->update(['status' => $this->deriveStatus($po->fresh('items')), 'received_at' => now()]);
        });

        // SUP-P2: GL posting — DR inventory / CR accounts payable (reporting only).
        if ($purchaseValue > 0.001) {
            try {
                app(GeneralLedger::class)->postInventoryReceipt((int) $po->store_id, (int) $po->id, Money::round($purchaseValue, Currencies::exponent(store_currency((int) $po->store_id))));
            } catch (\Throwable $e) {
            }
        }

        return ['discrepancies' => $discrepancies, 'price_alerts' => $priceAlerts, 'status' => $po->fresh()->status];
    }

    private function deriveStatus(PurchaseOrder $po): string
    {
        $items = $po->items;
        if ($items->isEmpty()) {
            return $po->status;
        }
        $fullyReceived = $items->every(fn ($i) => (float) $i->qty_received >= (float) $i->quantity - 0.001);
        $anyReceived = $items->contains(fn ($i) => (float) $i->qty_received > 0.001);

        return $fullyReceived ? 'received' : ($anyReceived ? 'partial' : 'open');
    }
}
