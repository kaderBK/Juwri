<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Ingredient;
use App\Models\RecipeItem;
use App\Models\StoreOption;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Inventory\Models\BranchStock;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\StockCount;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Modules\Inventory\Models\WasteLog;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Single writer for the inventory ledger. Every change to an ingredient's stock
 * MUST go through recordMovement() so the immutable stock_movements ledger and the
 * cached ingredients.current_stock never drift apart.
 */
class InventoryService
{
    /**
     * Record one stock movement and update the cached current_stock.
     *
     * @param  string  $type  one of StockMovement::TYPES
     * @param  float  $signedQty  + for stock in, - for stock out
     * @param  array  $opts  unit_cost, reference_type, reference_id, reason, user_id
     */
    public static function recordMovement(int $ingredientId, string $type, float $signedQty, array $opts = []): ?StockMovement
    {
        if (! in_array($type, StockMovement::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid movement type: {$type}");
        }

        // D10: captured inside the tx so the recipe auto-86 hook can run POST-commit
        // (menu writes fire cache-bust + aggregator sync — never inside the stock tx).
        $storeId = 0;
        $balanceAfter = null;

        $movement = DB::transaction(function () use ($ingredientId, $type, $signedQty, $opts, &$storeId, &$balanceAfter) {
            /** @var Ingredient|null $ingredient */
            $ingredient = Ingredient::lockForUpdate()->find($ingredientId);
            if (! $ingredient) {
                return null;
            }
            $storeId = (int) $ingredient->store_id;

            // #76: when a branch is given, the per-branch stock row is the running balance;
            // otherwise the ingredient's shared current_stock is used (default behaviour).
            $branchId = ! empty($opts['branch_id']) ? (int) $opts['branch_id'] : null;
            $branchStock = null;
            if ($branchId) {
                $branchStock = BranchStock::lockForUpdate()->firstOrCreate(
                    ['branch_id' => $branchId, 'ingredient_id' => $ingredient->id],
                    ['current_stock' => 0, 'par_level' => 0]
                );
                $balanceAfter = round((float) $branchStock->current_stock + $signedQty, 3);
            } else {
                $balanceAfter = round((float) $ingredient->current_stock + $signedQty, 3);
            }

            $movement = StockMovement::create([
                'store_id' => $ingredient->store_id,
                'branch_id' => $branchId,
                'ingredient_id' => $ingredient->id,
                'type' => $type,
                'quantity' => $signedQty,
                'unit_cost' => $opts['unit_cost'] ?? null,
                'balance_after' => $balanceAfter,
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id' => $opts['reference_id'] ?? null,
                'reason' => $opts['reason'] ?? null,
                'user_id' => $opts['user_id'] ?? (auth()->id() ?? null),
                'created_at' => now(),
            ]);

            if ($branchStock) {
                $branchStock->current_stock = $balanceAfter;
                $branchStock->save();
            } else {
                $ingredient->current_stock = $balanceAfter;
            }
            // Track latest purchase cost so recipe costing stays current (shared).
            if ($type === 'purchase' && isset($opts['unit_cost'])) {
                $ingredient->cost_per_unit = $opts['unit_cost'];
            }
            $ingredient->save();

            return $movement;
        });

        // D10: recipe-depletion auto-86 (option-gated, default OFF → no behaviour change).
        // Runs post-commit so the menu writes' cache-bust + aggregator sync are safe.
        // Threshold-crossing only: an ingredient that just fell to ≤0 86s its dependent
        // items; one that just came back above 0 un-86s the ones the recipe engine 86'd.
        if ($movement && $balanceAfter !== null && $storeId > 0
            && (string) StoreOption::getStoreOption($storeId, 'recipe_auto_86', '0') === '1') {
            $before = round($balanceAfter - $signedQty, 3);
            if ($before > 0 && $balanceAfter <= 0) {
                Menu::auto86ForDepletedIngredient($ingredientId);
            } elseif ($before <= 0 && $balanceAfter > 0) {
                Menu::clearRecipe86ForIngredient($ingredientId);
            }
        }

        return $movement;
    }

    /**
     * Receive a purchase order: for each line, add stock (+qty) via a `purchase` movement,
     * which also refreshes each ingredient's cost_per_unit. Marks the PO received. Idempotent —
     * a PO already 'received' is skipped; a 'cancelled' PO is refused. Status is checked
     * inside the transaction on a fresh, locked row so two concurrent receives (or a
     * controller holding a stale model) cannot both post stock movements.
     */
    public static function receivePurchaseOrder(PurchaseOrder $po): void
    {
        DB::transaction(function () use ($po) {
            /* Reload with lockForUpdate so the status check + final save happen on the
             * freshest row and concurrent receives serialize. The passed-in $po is
             * treated as a pointer to the id only. */
            $fresh = PurchaseOrder::whereKey($po->id)->lockForUpdate()->first();
            if (! $fresh) {
                return;
            }

            /* Refuse to blanket-receive a PO that has already been received line by
             * line. The PO screen keeps offering "Receive stock" while the status is
             * `partial`, so an owner who recorded the 5 kg that actually turned up
             * against a 10 kg order could then click it and book the 5 kg that never
             * arrived — inventory claiming goods the supplier still owes. Whatever
             * really arrives afterwards belongs on the line screen, which records
             * per-line quantities and discrepancies. */
            if ($fresh->items()->where('qty_received', '>', 0)->exists()) {
                return;
            }

            if ($fresh->status === 'received') {
                return;
            }
            if ($fresh->status === 'cancelled') {
                throw new \RuntimeException(___('This order is cancelled.'));
            }

            /* Receive-in-full, honouring what line receiving already applied.
             *
             * This used to add each line's FULL ordered quantity, guarded only by
             * the PO status, while the line-level screen tracked qty_received per
             * item and applied deltas. Neither consulted the other, so a PO
             * received on one screen and then closed from the other added the same
             * goods to stock twice and the inventory was permanently overstated
             * with no trace of why.
             *
             * Only the OUTSTANDING quantity is applied here, and qty_received is
             * stamped so the line screen agrees. Costing is deliberately unchanged
             * (this path refreshes cost_per_unit to the purchase price, while line
             * receiving maintains a weighted average) — reconciling those two
             * models is a separate decision, not a side effect of closing a
             * double-count. */
            foreach ($fresh->items()->get() as $line) {
                $ordered = (float) $line->quantity;
                $already = (float) ($line->qty_received ?? 0);
                $delta = round($ordered - $already, 3);

                if ($delta > 0.0001 && $line->ingredient_id) {
                    self::recordMovement((int) $line->ingredient_id, 'purchase', $delta, [
                        'branch_id' => $fresh->branch_id, // P6-224: a branch's delivery stocks that branch
                        'unit_cost' => (float) $line->unit_cost,
                        'reference_type' => 'purchase_order',
                        'reference_id' => $fresh->id,
                        'reason' => 'PO #'.$fresh->id.' received',
                    ]);
                }

                // Record what has now arrived, so line receiving sees no outstanding delta.
                if ($ordered > 0) {
                    $line->update(['qty_received' => $ordered, 'stock_applied' => true]);
                }
            }

            // Status + received_at land in the SAME transaction as the stock movements,
            // so a second caller that grabs the lock after this commits sees the fresh
            // 'received' status and exits at the early return above.
            $fresh->status = 'received';
            $fresh->received_at = now();
            $fresh->save();
        });
    }

    /**
     * Close a stock count: for each line with a counted quantity, post a `count` movement equal
     * to the variance (counted − theoretical) so the ledger matches the physical reality.
     * Lines never counted are skipped. Idempotent — a closed count is not re-applied.
     */
    public static function applyStockCount(StockCount $count): void
    {
        if ($count->status === 'closed') {
            return;
        }

        DB::transaction(function () use ($count) {
            foreach ($count->items()->get() as $line) {
                if ($line->counted_qty === null) {
                    continue; // not physically counted — leave stock untouched
                }
                $variance = round((float) $line->counted_qty - (float) $line->theoretical_qty, 3);
                $line->variance = $variance;
                $line->save();

                if ($variance != 0.0) {
                    self::recordMovement((int) $line->ingredient_id, 'count', $variance, [
                        'branch_id' => $count->branch_id, // P6-224: a branch's count corrects that branch
                        'reference_type' => 'stock_count',
                        'reference_id' => $count->id,
                        'reason' => 'Stock count #'.$count->id.' correction',
                    ]);
                }
            }
            $count->status = 'closed';
            $count->counted_at = now();
            $count->save();
        });
    }

    /**
     * Log waste (at a branch when given — P6-224): deduct stock (−qty) via a `waste` movement and record a waste_logs row with
     * the costed value (qty × current unit cost). Returns the WasteLog, or null if the
     * ingredient is missing.
     */
    public static function logWaste(int $ingredientId, float $qty, string $reason, ?string $note = null, ?int $branchId = null): ?WasteLog
    {
        if ($qty <= 0) {
            return null;
        }
        $ingredient = Ingredient::find($ingredientId);
        if (! $ingredient) {
            return null;
        }

        // P1-05: exact qty × unit cost, HALF_UP to the store currency's exponent (the waste row is stamped in it).
        $cost = Money::round((string) Money::decimal($qty)->multipliedBy(Money::decimal((float) $ingredient->cost_per_unit)), Currencies::exponent(store_currency((int) $ingredient->store_id)));

        return DB::transaction(function () use ($ingredient, $qty, $reason, $note, $cost, $branchId) {
            $waste = WasteLog::create([
                'store_id' => $ingredient->store_id,
                'branch_id' => $branchId ?: null, // P6-224
                'ingredient_id' => $ingredient->id,
                'quantity' => $qty,
                'reason' => in_array($reason, WasteLog::REASONS, true) ? $reason : 'other',
                'cost' => $cost,
                'user_id' => auth()->id(),
                'created_at' => now(),
            ]);

            self::recordMovement($ingredient->id, 'waste', -$qty, [
                'branch_id' => $branchId,
                'reference_type' => 'waste_log',
                'reference_id' => $waste->id,
                'reason' => $note ?: ('Waste: '.$waste->reason),
            ]);

            return $waste;
        });
    }

    /**
     * Advanced-mode sale deduction: consume each recipe ingredient for one sold menu item.
     * No-op until the item has a recipe (Phase 2). Never throws into the order flow —
     * caller wraps in try/catch, but we also guard here.
     *
     * @param  int  $orderQty  how many of this item were ordered
     */
    public static function deductForOrderItem(int $menuId, int $orderQty, ?int $orderId = null, ?int $branchId = null): void
    {
        if (! class_exists(RecipeItem::class)) {
            return;
        }

        $recipe = RecipeItem::where('menu_id', $menuId)->get();
        foreach ($recipe as $row) {
            $consume = (float) $row->quantity * $orderQty;
            if ($consume <= 0) {
                continue;
            }
            self::recordMovement((int) $row->ingredient_id, 'sale', -$consume, [
                'branch_id' => $branchId,
                'reference_type' => 'order',
                'reference_id' => $orderId,
                'reason' => 'Sold '.$orderQty.' × menu #'.$menuId,
            ]);
        }
    }

    /**
     * Reverse a recipe-mode deduction — the ingredient-side counterpart of
     * deductForOrderItem, for voids / refunds / cancels / order edits. Without this,
     * every restore path called only Menu::restoreStock (a no-op for recipe items),
     * so advanced-mode ingredient stock was lost permanently on every void. Recomputes
     * from the recipe (as the deduct does) and records a positive 'adjustment'.
     *
     * @param  int  $orderQty  how many of this item are being reversed
     */
    public static function reverseForOrderItem(int $menuId, int $orderQty, ?int $orderId = null, ?int $branchId = null): void
    {
        if (! class_exists(RecipeItem::class)) {
            return;
        }

        $recipe = RecipeItem::where('menu_id', $menuId)->get();
        foreach ($recipe as $row) {
            $restore = (float) $row->quantity * $orderQty;
            if ($restore <= 0) {
                continue;
            }
            self::recordMovement((int) $row->ingredient_id, 'adjustment', $restore, [
                'branch_id' => $branchId,
                'reference_type' => 'order',
                'reference_id' => $orderId,
                'reason' => 'Reversed '.$orderQty.' × menu #'.$menuId,
            ]);
        }
    }
}
