<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\Api\V1;

use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Ordering\Models\MealPlan;
use Juwri\Modules\Ordering\Services\MealPlanService;

/**
 * theme-pack-blade F1: customer-facing meal-plan subscriptions. Authenticated by
 * {@see \Juwri\Modules\Customers\Http\Middleware\CustomerTokenAuth} (same as the standalone customer
 * app), so every action is scoped to the token's customer + their restaurant.
 * Generation happens off-request in the `meals:generate-due` command.
 */
class MealPlanController extends Controller
{
    private function customer(): Customer
    {
        return app('api.customer');
    }

    /** Enforce the per-restaurant enable flag on every write. */
    private function assertEnabled(int $storeId): void
    {
        abort_unless(MealPlanService::enabled($storeId), 403, 'Meal plans are not enabled for this restaurant.');
    }

    private function present(MealPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'cadence' => $plan->cadence,
            'days' => $plan->days,
            'start_date' => optional($plan->start_date)->toDateString(),
            'next_run' => optional($plan->next_run)->toDateString(),
            'status' => $plan->status,
            'items' => $plan->items,
        ];
    }

    /** GET /meal-plan — the authenticated customer's plans. */
    public function index()
    {
        $c = $this->customer();
        $plans = MealPlan::where('customer_id', $c->id)
            ->orderByDesc('id')->get()
            ->map(fn ($p) => $this->present($p));

        return response()->json([
            'enabled' => MealPlanService::enabled($c->store_id),
            'allowed_cadences' => MealPlanService::allowedCadences($c->store_id),
            'data' => $plans,
        ]);
    }

    /** POST /meal-plan — create a plan for the authenticated customer. */
    public function store(Request $request)
    {
        $c = $this->customer();
        $this->assertEnabled($c->store_id);

        $allowed = MealPlanService::allowedCadences($c->store_id);

        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'cadence' => ['required', Rule::in($allowed)],
            'days' => 'nullable|array',
            'days.*' => 'integer|min:0|max:6',
            'start_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.variation' => 'nullable|integer',
            'items.*.extras' => 'nullable|array',
            'items.*.extras.*' => 'integer',
            'items.*.note' => 'nullable|string|max:255',
        ]);

        $start = Carbon::parse($data['start_date'])->startOfDay();

        $plan = new MealPlan;
        $plan->customer_id = $c->id;
        $plan->store_id = $c->store_id;
        $plan->name = $data['name'] ?? null;
        $plan->cadence = $data['cadence'];
        $plan->days = $data['cadence'] === 'custom' ? array_values($data['days'] ?? []) : null;
        $plan->start_date = $start;
        // First run fires on the start date itself (it is the first due next_run).
        $plan->next_run = $start;
        $plan->status = 'active';
        $plan->items = array_values($data['items']);
        $plan->save();

        return response()->json($this->present($plan), 201);
    }

    /** Resolve a plan owned by the authenticated customer or 404. */
    private function ownPlan(int $id): MealPlan
    {
        $c = $this->customer();

        return MealPlan::where('customer_id', $c->id)->findOrFail($id);
    }

    /** POST /meal-plan/{id}/pause — stop generation, keep the schedule. */
    public function pause(int $id)
    {
        $plan = $this->ownPlan($id);
        if ($plan->status === 'active') {
            $plan->status = 'paused';
            $plan->save();
        }

        return response()->json($this->present($plan));
    }

    /** POST /meal-plan/{id}/resume — reactivate a paused plan. */
    public function resume(int $id)
    {
        $plan = $this->ownPlan($id);
        if ($plan->status === 'paused') {
            $plan->status = 'active';
            // If the stored next_run is in the past, roll it to the next future run.
            if ($plan->next_run && $plan->next_run->lt(Carbon::today())) {
                $plan->next_run = $plan->nextRunAfter(Carbon::today());
            }
            $plan->save();
        }

        return response()->json($this->present($plan));
    }

    /** POST /meal-plan/{id}/skip — advance next_run without generating an order. */
    public function skip(int $id)
    {
        $plan = $this->ownPlan($id);
        if ($plan->status === 'active' && $plan->next_run) {
            $plan->advanceNextRun();
        }

        return response()->json($this->present($plan));
    }

    /** POST /meal-plan/{id}/cancel — cancel and remove all future runs. */
    public function cancel(int $id)
    {
        $plan = $this->ownPlan($id);
        $plan->status = 'cancelled';
        $plan->next_run = null; // no future runs
        $plan->save();

        return response()->json($this->present($plan));
    }
}
