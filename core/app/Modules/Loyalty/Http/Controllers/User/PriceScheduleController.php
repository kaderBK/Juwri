<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\User;

use App\Models\MenuCategory;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Loyalty\Models\PriceScheduleTarget;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;

/**
 * PARITY-RUN T5: owner-facing Menu & Content → Schedules.
 *
 * CRUD over PriceSchedule (happy hours / day-part pricing) plus a live
 * "active now" indicator resolved on the STORE's clock, not the browser's.
 *
 * Tenant scoping is the shared ResolvesCurrentStore guard; the superadmin
 * kill-switch is `feature:menu_schedules` middleware on the route group —
 * legal here because this is a dashboard-only route no anonymous diner can
 * reach. Every customer-facing surface instead asks
 * MenuScheduleService::enabled() (FeatureAccess::storeAllows) in-process.
 */
class PriceScheduleController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $rid = (int) $store->id;

        MenuScheduleService::flush(); // fresh "active now" on every page load

        return view('owner.menu.schedules', [
            'rid' => $rid,
            'store' => $store,
            'schedules' => PriceSchedule::with('targets')
                ->where('store_id', $rid)
                ->orderByDesc('active')->orderByDesc('priority')->orderBy('name')
                ->get(),
            'liveIds' => MenuScheduleService::liveNow($rid)->pluck('id')->all(),
            'categories' => MenuCategory::where('store_id', $rid)
                ->orderBy('position')->get(['id', 'name']),
            'items' => Menu::where('store_id', $rid)
                ->orderBy('position')->get(['id', 'name']),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
            'timezone' => MenuScheduleService::timezoneFor($rid),
            'storeNow' => MenuScheduleService::nowFor($rid)->format('D H:i'),
            'scopes' => PriceSchedule::SCOPES,
            'types' => PriceSchedule::TYPES,
            'orderTypes' => PriceSchedule::ORDER_TYPES,
            'dayCodes' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            /* Store menu languages → per-language "Customer label" inputs.
               Empty (or single-language) stores keep the plain label field only. */
            'menuLanguages' => store_options($rid, 'menu_languages')
                ? Language::whereIn('code', (array) store_options($rid, 'menu_languages'))->get(['code', 'name'])
                : collect(),
            'showUnavailable' => (bool) store_options($rid, 'menu_show_unavailable'),
        ]);
    }

    /**
     * Store-level display choice for items outside their window: hide them
     * (default, and what the order path has always enforced anyway) or grey
     * them out with an "available from HH:MM" note so guests learn the store
     * serves breakfast at all.
     */
    public function options(Request $request)
    {
        $store = $this->currentRestaurant($request);
        StoreOption::updateStoreOption(
            $store->id,
            'menu_show_unavailable',
            $request->boolean('menu_show_unavailable') ? '1' : '0'
        );

        return back()->with('success', ___('Settings saved.'));
    }

    public function store(Request $request)
    {
        $store = $this->currentRestaurant($request);
        [$data, $targets] = $this->validated($request, (int) $store->id);

        DB::transaction(function () use ($store, $data, $targets) {
            $data['store_id'] = $store->id;
            $schedule = PriceSchedule::create($data);
            $this->syncTargets($schedule, $targets);
        });

        return back()->with('success', ___('Schedule saved.'));
    }

    public function update(Request $request, int $id)
    {
        $store = $this->currentRestaurant($request);
        $schedule = $this->ownSchedule($id, (int) $store->id);
        [$data, $targets] = $this->validated($request, (int) $store->id);

        DB::transaction(function () use ($schedule, $data, $targets) {
            $schedule->update($data);
            $this->syncTargets($schedule, $targets);
        });

        return back()->with('success', ___('Schedule saved.'));
    }

    public function toggle(Request $request, int $id)
    {
        $store = $this->currentRestaurant($request);
        $schedule = $this->ownSchedule($id, (int) $store->id);
        $schedule->update(['active' => ! $schedule->active]);

        return back()->with('success', $schedule->active ? ___('Schedule enabled.') : ___('Schedule disabled.'));
    }

    public function destroy(Request $request, int $id)
    {
        $store = $this->currentRestaurant($request);
        $schedule = $this->ownSchedule($id, (int) $store->id);

        DB::transaction(function () use ($schedule) {
            $schedule->targets()->delete();
            $schedule->delete();
        });

        /* Orders already placed keep their snapshotted unit_price and label, so
           deleting a schedule can never re-price history — see the T5-3
           migration docblock. */
        return back()->with('success', ___('Schedule removed.'));
    }

    /* ─────────────────────────── internals ───────────────────────────────── */

    private function ownSchedule(int $id, int $rid): PriceSchedule
    {
        return PriceSchedule::where('store_id', $rid)->findOrFail($id);
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array{0: string, 1: int}>} */
    private function validated(Request $request, int $rid): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'label' => ['nullable', 'string', 'max:60'],
            'label_i18n' => ['nullable', 'array'],
            'label_i18n.*' => ['nullable', 'string', 'max:60'],
            'scope' => ['required', 'in:'.implode(',', PriceSchedule::SCOPES)],
            'adjustment_type' => ['required', 'in:'.implode(',', PriceSchedule::TYPES)],
            'adjustment_value' => ['required', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'days' => ['nullable', 'array'],
            'days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'starts_at' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'ends_at' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'order_types' => ['nullable', 'array'],
            'order_types.*' => ['string', 'in:'.implode(',', PriceSchedule::ORDER_TYPES)],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'active' => ['nullable'],
            'target_ids' => ['nullable', 'array'],
            'target_ids.*' => ['integer'],
        ]);

        // A percentage above 100 would drive the price negative before apply()
        // clamps it to zero — reject it at the door so the owner sees why
        // instead of silently getting free food.
        if ($v['adjustment_type'] === 'percent' && (float) $v['adjustment_value'] > 100) {
            throw ValidationException::withMessages([
                'adjustment_value' => ___('A percentage discount cannot be more than 100%.'),
            ]);
        }

        /* Per-language customer labels: keep only real language-code keys with
           non-empty values; an all-empty set stores NULL so displayLabel()'s
           fallback chain stays untouched. */
        $i18n = [];
        foreach ((array) ($v['label_i18n'] ?? []) as $code => $text) {
            $text = trim((string) $text);
            if ($text !== '' && preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/i', (string) $code)) {
                $i18n[strtolower((string) $code)] = $text;
            }
        }

        $data = [
            'name' => $v['name'],
            'label' => $v['label'] ?? null,
            'label_translations' => $i18n ?: null,
            'scope' => $v['scope'],
            'adjustment_type' => $v['adjustment_type'],
            'adjustment_value' => $v['adjustment_value'],
            'starts_on' => $v['starts_on'] ?? null,
            'ends_on' => $v['ends_on'] ?? null,
            'days' => $this->csv($v['days'] ?? null),
            'starts_at' => $v['starts_at'] ?? null,
            'ends_at' => $v['ends_at'] ?? null,
            'order_types' => $this->csv($v['order_types'] ?? null),
            'priority' => (int) ($v['priority'] ?? 0),
            'active' => $request->boolean('active'),
        ];

        // Targets only mean anything for a narrowed scope; a store-wide rule
        // must not carry stale rows from a scope the owner changed away from.
        $targets = [];
        if ($v['scope'] !== 'store') {
            $type = $v['scope']; // 'item' | 'category'
            $ids = array_values(array_unique(array_map('intval', (array) ($v['target_ids'] ?? []))));
            $ids = $this->ownedTargetIds($type, $ids, $rid);
            $targets = array_map(fn ($id) => [$type, $id], $ids);
        }

        return [$data, $targets];
    }

    /**
     * Drop any target id that does not belong to THIS store. A posted id is
     * attacker-controlled, and a schedule pointing at another tenant's item
     * would be a cross-tenant price leak.
     */
    private function ownedTargetIds(string $type, array $ids, int $rid): array
    {
        if (empty($ids)) {
            return [];
        }

        $query = $type === 'item'
            ? Menu::where('store_id', $rid)
            : MenuCategory::where('store_id', $rid);

        return $query->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function syncTargets(PriceSchedule $schedule, array $targets): void
    {
        $schedule->targets()->delete();
        foreach ($targets as [$type, $id]) {
            PriceScheduleTarget::create([
                'schedule_id' => $schedule->id,
                'target_type' => $type,
                'target_id' => $id,
            ]);
        }
    }

    private function csv(?array $values): ?string
    {
        $values = array_values(array_filter(array_map('trim', (array) $values)));

        return $values ? implode(',', $values) : null;
    }
}
