<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services;

use App\Models\Order;
use App\Services\OrderApiService;
use Carbon\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Models\MealPlan;

/**
 * theme-pack-blade F1: meal-plan settings + due-run generation. Per-restaurant
 * config lives in `store_options` (that's how per-restaurant flags work here —
 * see Helper::store_options / inventory_mode / kds_mode). Orders are created via
 * the shared {@see OrderApiService} so meal plans reuse the one order-create
 * path — they are UNPAID (money movement is gated; no auto-charge here).
 */
class MealPlanService
{
    /** Per-restaurant master enable flag (store_options row). */
    public static function enabled($storeId): bool
    {
        $v = store_options($storeId, 'meal_plans_enabled');
        if (is_bool($v)) {
            return $v;
        }
        $v = strtolower(trim((string) $v));

        return in_array($v, ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * Allowed cadences for a restaurant (configurable via store_options JSON).
     * Defaults to daily + weekly; always intersected with the model whitelist.
     */
    public static function allowedCadences($storeId): array
    {
        $raw = store_options($storeId, 'meal_plans_cadences');
        // store_options() json-decodes to an object/array; normalise to a list.
        $list = is_object($raw) ? (array) $raw : $raw;
        if (! is_array($list) || empty($list)) {
            $list = ['daily', 'weekly'];
        }
        $list = array_values(array_intersect(
            array_map('strval', $list),
            MealPlan::CADENCES
        ));

        return empty($list) ? ['daily', 'weekly'] : $list;
    }

    /**
     * Generate orders for every active plan whose next_run is due (<= as-of date)
     * on an enabled restaurant, then advance next_run. Disabled restaurants are a
     * no-op (no generation, no advance). Returns the number of orders created.
     */
    public function generateDue(?string $asOf = null): int
    {
        $today = $asOf ? Carbon::parse($asOf)->startOfDay() : Carbon::today();
        $created = 0;

        MealPlan::where('status', 'active')
            ->whereNotNull('next_run')
            ->whereDate('next_run', '<=', $today->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($plans) use (&$created) {
                foreach ($plans as $plan) {
                    if (! self::enabled($plan->store_id)) {
                        continue; // disabled restaurant → skip entirely
                    }
                    $order = $this->spawnOrder($plan);
                    if ($order) {
                        $created++;
                    }
                    // Advance regardless of a single-item failure so a bad plan
                    // does not wedge the scheduler on the same due date forever.
                    $plan->advanceNextRun();
                }
            });

        return $created;
    }

    /** Build one UNPAID order from a plan's items via the shared order builder. */
    public function spawnOrder(MealPlan $plan): ?Order
    {
        $items = array_values(array_filter(
            (array) ($plan->items ?? []),
            fn ($line) => is_array($line) && ! empty($line['item_id'])
        ));
        if (empty($items)) {
            return null;
        }

        $ownerId = (int) Store::where('id', $plan->store_id)->value('user_id');
        $customer = $plan->customer;

        try {
            return OrderApiService::create($ownerId, (int) $plan->store_id, [
                'type' => 'takeaway',
                'source' => 'meal_plan',
                'customer_id' => $plan->customer_id,
                'customer_name' => $customer->name ?? null,
                'phone' => $customer->phone ?? null,
                'note' => trim('Meal plan: '.($plan->name ?? ('#'.$plan->id))),
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            // A plan referencing a since-deleted/sold-out item must not abort the
            // whole sweep; log and move on (next_run still advances).
            \Log::warning('meal-plan generation failed', [
                'meal_plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
