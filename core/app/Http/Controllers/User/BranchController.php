<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User;

use App\Models\StoreOption;
use App\Services\Billing\PlanLimitSync;
use App\Services\Entitlements;
use App\Support\StoreClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\BranchHourException;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\BranchDeletion;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedValidator;

class BranchController extends Controller
{
    private function ownedRestaurant(int $id): Store
    {
        return Store::where('user_id', auth()->id())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $storeIds = owner_store_ids(); // S13
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        // P6-220: bring the branches in line with the plan before showing them (downgrade → newest switched off).
        app(PlanLimitSync::class)->sync(auth()->user());
        $planSuspended = PlanLimitSync::suspendedCount('branch', auth()->user());
        $branchLimit = PlanLimitSync::limit('branch', auth()->user());

        $branches = collect();
        if ($storeId) {
            $branches = Branch::where('store_id', (int) $storeId)->orderBy('position')->get();
            // P6-291: upcoming holidays / special hours for the hours dialogs (one query).
            $upcoming = BranchHourException::whereIn('branch_id', $branches->pluck('id'))
                ->where('date', '>=', now()->subDay()->toDateString())->orderBy('date')->get()->groupBy('branch_id');
            $branches->each(fn ($b) => $b->setRelation('hourExceptions', $upcoming->get($b->id, collect())));
        }

        // The storefront branch picker: ON unless the store never set it (a missing value means the
        // storefront shows the picker — the theme reads the option with a `?? 1` default).
        $branchSwitcher = $storeId ? StoreSettings::bool((int) $storeId, 'allow_multi_branch_switch', true) : true;

        return view('owner.branches.index', compact('stores', 'storeId', 'branches', 'planSuspended', 'branchLimit', 'branchSwitcher'));
    }

    /**
     * One-tap switches on the branch card: Active / Accepting orders.
     *
     * Same rules as update(): switching a branch ON must fit the plan's branch limit
     * (P6-220) and a real change of `active` clears the plan-suspension stamp, so the
     * branch reads as the owner's own choice again.
     */
    public function toggle(Request $request, int $branch): RedirectResponse
    {
        $model = Branch::forOwner(auth()->id())->findOrFail($branch);
        $data = $request->validate(['field' => ['required', 'in:active,accepting_orders']]);

        $value = $request->boolean('value');

        if ($data['field'] === 'active') {
            if ($value && ! $model->active && ! PlanLimitSync::canActivate('branch', (int) $model->store_id, auth()->user(), (int) $model->id)) {
                return back()->with('error', ___('Your plan allows :n active branches. Switch another branch off first, or upgrade your plan.', ['n' => (int) PlanLimitSync::limit('branch', auth()->user())]));
            }
            if ($value !== (bool) $model->active) {
                $model->plan_suspended_at = null;
            }
        }

        $model->{$data['field']} = $value;
        $model->save();

        return back()->with('success', ___('Branch updated.'));
    }

    /**
     * Let storefront customers pick a branch (menu header + checkout). Owner only:
     * the store must belong to the signed-in owner.
     */
    public function switcher(Request $request): RedirectResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer']]);
        $store = $this->ownedRestaurant((int) $data['store_id']);

        StoreOption::updateStoreOption($store->id, 'allow_multi_branch_switch', $request->boolean('allow_multi_branch_switch') ? 1 : 0);

        return back()->with('success', ___('Saved.'));
    }

    /**
     * WA-S3: reject a WhatsApp number the storefront could never dial.
     *
     * $stored grandfathers the value a branch already has. 634 of the 1,722 stored
     * numbers start with a national 0 and would fail this rule, and a merchant
     * editing that branch's opening hours must not be locked out by a number they
     * did not touch this time. Change it and it has to be right.
     */
    private function whatsappRule(?string $stored = null): \Closure
    {
        return function ($attribute, $value, $fail) use ($stored) {
            if ($stored !== null && whatsapp_normalize_number($value) === whatsapp_normalize_number($stored)) {
                return;
            }

            if ($problem = whatsapp_number_problem($value)) {
                $fail($problem);
            }
        };
    }

    /**
     * P5-25: normalise a submitted Google place ID — collapse '' to null (a wiped
     * field means "no place id"), leaving a trimmed nullable string.
     */
    private function normalizePlaceId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * P5-30: a place id is unique per store — a branch never shares its store's
     * place id with a sibling. Same value in another store is a different row.
     */
    private function rejectDuplicatePlaceId(int $storeId, mixed $value, \Closure $fail, ?int $except = null): void
    {
        if ($value === null) {
            return;
        }
        $query = Branch::where('store_id', $storeId)->where('google_place_id', $value);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->exists()) {
            $fail(___('This Google place ID is already used by another branch of your store.'));
        }
    }

    public function store(Request $request)
    {
        $request->merge(['google_place_id' => $this->normalizePlaceId($request->input('google_place_id'))]);

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40', $this->whatsappRule()],
            'timezone' => ['nullable', 'string', 'timezone:all'], // P6-288: IANA only, blank = inherit.
            // P5-30: one place id per store; a new branch is its own Google merchant.
            'google_place_id' => ['nullable', 'string', 'max:255', 'regex:'.RwgFeedValidator::PLACE_ID,
                function ($attribute, $value, $fail) use ($request) {
                    $this->rejectDuplicatePlaceId((int) $request->input('store_id'), $value, $fail);
                },
            ],
        ]);

        $store = $this->ownedRestaurant((int) $data['store_id']);

        // E3: per-plan branch cap (per store). Absent `branch_limit` plan key →
        // unlimited (999 sentinel), i.e. exactly today's ungated behavior.
        $limit = effective_limit('branch', auth()->user());
        if (! Entitlements::isUnlimitedSentinel($limit)
            && Branch::where('store_id', $store->id)->count() >= $limit) {
            return back()->with('error', ___('Limit exceeded, please upgrade your membership.'));
        }

        // Edition limits (Community: up to 3 branches per store) — same rule the Branch model enforces.
        $edition = app(StoreLimitGuard::class)->checkNewBranches((int) $store->id);
        if ($edition->refused()) {
            return back()->withInput()->with('error', $edition->message())->with('store_limit', $edition->toArray() + ['message' => $edition->message()]);
        }

        $next = (int) Branch::where('store_id', $store->id)->max('position');
        try {
            Branch::create([
                'store_id' => $store->id,
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'whatsapp' => $data['whatsapp'] ?? null,
                'google_place_id' => $data['google_place_id'] ?? null,
                'active' => true,
                'position' => $next + 1,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A sibling grabbed this place id between validation and save.
            if (($data['google_place_id'] ?? null) === null) {
                throw $e;
            }

            return back()->withInput()->withErrors(['google_place_id' => ___('This Google place ID is already used by another branch of your store.')]);
        }

        return back()->with('success', ___('Branch added.'));
    }

    public function update(Request $request, int $branch)
    {
        $model = Branch::forOwner(auth()->id())->findOrFail($branch);

        $request->merge(['google_place_id' => $this->normalizePlaceId($request->input('google_place_id'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40', $this->whatsappRule($model->whatsapp)],
            'active' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'string', 'timezone:all'], // P6-288: IANA only.
            'accepting_orders' => ['nullable', 'boolean'],
            'closed_message' => ['nullable', 'string', 'max:500'],
            'order_types' => ['nullable', 'array'], // D6: per-branch order-type overrides
            // P5-30: the branch's own Google place ID — this branch is its own merchant.
            'google_place_id' => ['nullable', 'string', 'max:255', 'regex:'.RwgFeedValidator::PLACE_ID,
                function ($attribute, $value, $fail) use ($model) {
                    $this->rejectDuplicatePlaceId((int) $model->store_id, $value, $fail, $model->id);
                },
            ],
            // P5-31: optional overrides of the store's values; empty = the store's
            'prep_time_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'min_order' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        // P6-220: switching a branch on must fit the plan's branch limit (after a downgrade the owner picks which
        // branches stay on: switch one off, then another on). Switched off by the owner = not the plan's to restore.
        $activate = $request->boolean('active');
        if ($activate && ! $model->active && ! PlanLimitSync::canActivate('branch', (int) $model->store_id, auth()->user(), (int) $model->id)) {
            return back()->withInput()->with('error', ___('Your plan allows :n active branches. Switch another branch off first, or upgrade your plan.', ['n' => (int) PlanLimitSync::limit('branch', auth()->user())]));
        }
        if ($activate !== (bool) $model->active) {
            $model->plan_suspended_at = null;
        }

        try {
            $model->update([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'whatsapp' => $data['whatsapp'] ?? null,
                'active' => $request->boolean('active'),
                'timezone' => $data['timezone'] ?? null,
                'accepting_orders' => $request->boolean('accepting_orders'),
                'closed_message' => $data['closed_message'] ?? null,
                'order_types' => $this->normalizeOrderTypes($data['order_types'] ?? null), // D6
                'google_place_id' => $data['google_place_id'] ?? null,
                'prep_time_minutes' => isset($data['prep_time_minutes']) ? (int) $data['prep_time_minutes'] : null, // P5-31
                'delivery_fee' => isset($data['delivery_fee']) ? (string) $data['delivery_fee'] : null,
                'min_order' => isset($data['min_order']) ? (string) $data['min_order'] : null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A sibling grabbed this place id between validation and save.
            if (($data['google_place_id'] ?? null) === null) {
                throw $e;
            }

            return back()->withInput()->withErrors(['google_place_id' => ___('This Google place ID is already used by another branch of your store.')]);
        }

        return back()->with('success', ___('Branch updated.'));
    }

    /**
     * D6 — normalise the submitted order-type overrides. Each type comes in as
     * '' (inherit), '1' (force on) or '0' (force off). Inherited types are dropped
     * so an absent key always means "use the store flag"; an all-inherit map stores
     * null (no override at all).
     *
     * @param  array<string, mixed>|null  $input
     * @return array<string, bool>|null
     */
    private function normalizeOrderTypes(?array $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $out = [];
        foreach (Branch::ORDER_TYPES as $type) {
            $val = $input[$type] ?? '';
            if ($val === '1' || $val === 1 || $val === true) {
                $out[$type] = true;
            } elseif ($val === '0' || $val === 0 || $val === false) {
                $out[$type] = false;
            }
            // '' / missing → inherit (omit the key)
        }

        return $out ?: null;
    }

    /** Per-branch QR codes — deep-links to the storefront with ?branch_id=. */
    public function qr(Request $request, int $store)
    {
        $store = Store::where('user_id', auth()->id())->findOrFail($store);
        $branches = Branch::where('store_id', $store->id)->orderBy('position')->get();

        $branches->each(function ($b) use ($store) {
            $b->deep_link = reseller_store_url(route('publicView', $store->slug), $store).'?branch_id='.$b->id; // R4b: reseller host
        });

        return view('owner.branches.qr', compact('store', 'branches'));
    }

    /** Save the weekly opening-hours grid for a branch. */
    public function updateHours(Request $request, int $branch)
    {
        $model = Branch::forOwner(auth()->id())->findOrFail($branch);

        // P6-288: validate the submitted grid instead of silently defaulting
        // (missing open/close on an open day used to fall through to 09:00/22:00).
        $rules = [
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];
        foreach (Branch::DAYS as $day) {
            // `string` already rejects arrays, so 'hours[mon][open][]=x' is rejected.
            $rules["hours.$day.open"] = ['nullable', 'string', 'date_format:H:i'];
            $rules["hours.$day.close"] = ['nullable', 'string', 'date_format:H:i'];
            $rules["hours.$day.closed"] = ['nullable', 'boolean'];
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request) {
            // Cross-field checks: a non-closed day must have open AND close, and
            // they must differ (close < open = overnight, allowed).
            foreach (Branch::DAYS as $day) {
                if ($request->boolean("hours.$day.closed")) {
                    continue;
                }
                $open = $request->input("hours.$day.open");
                $close = $request->input("hours.$day.close");
                if (is_array($open) || is_array($close)) {
                    continue; // the `string` rule already rejected it
                }
                if ($open === null || $open === '') {
                    $validator->errors()->add("hours.$day.open", ___('Opening time is required when this day is not closed.'));
                }
                if ($close === null || $close === '') {
                    $validator->errors()->add("hours.$day.close", ___('Closing time is required when this day is not closed.'));
                }
                if ($open !== null && $close !== null && (string) $open === (string) $close) {
                    $validator->errors()->add("hours.$day.close", ___('Closing time must be different from opening time.'));
                }
            }
        });

        $data = $validator->validate(); // → 422 with session errors via $request->validate semantics.

        $hours = [];
        foreach (Branch::DAYS as $day) {
            $hours[$day] = [
                'open' => (string) $request->input("hours.$day.open"),
                'close' => (string) $request->input("hours.$day.close"),
                'closed' => $request->boolean("hours.$day.closed"),
            ];
        }

        $model->update([
            'hours' => $hours,
            'timezone' => $request->input('timezone') ?: $model->timezone,
        ]);

        return back()->with('success', ___('Opening hours saved.'));
    }

    /** P6-291: add or replace a holiday (closed all day) or special hours for one date. */
    public function storeException(Request $request, int $branch): RedirectResponse
    {
        $model = Branch::forOwner(auth()->id())->findOrFail($branch);
        $tz = $model->timezone ?: config('app.timezone', 'UTC');
        try {
            $today = Carbon::now($tz)->toDateString();
        } catch (\Throwable $e) {
            $today = StoreClock::today((int) $model->store_id); // bad branch tz: fall back to the store clock
        }

        $closed = $request->boolean('closed');
        $data = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'closed' => ['nullable', 'boolean'],
            'open' => [$closed ? 'nullable' : 'required', 'string', 'date_format:H:i'],
            'close' => [$closed ? 'nullable' : 'required', 'string', 'date_format:H:i', $closed ? 'nullable' : 'different:open'],
            'note' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $existing = BranchHourException::where('branch_id', $model->id)->whereDate('date', $data['date'])->first();
        if (! $existing && BranchHourException::where('branch_id', $model->id)->where('date', '>=', $today)->count() >= 400) {
            return back()->withErrors(['date' => ___('Too many upcoming dates for this branch.')]);
        }

        BranchHourException::updateOrCreate(
            ['branch_id' => $model->id, 'date' => $data['date']],
            [
                'store_id' => $model->store_id,
                'closed' => $closed,
                'open' => $closed ? null : $data['open'],
                'close' => $closed ? null : $data['close'],
                'note' => $data['note'] ?? null,
            ]
        );

        return back()->with('success', ___('Date saved.'));
    }

    /** P6-291: remove one holiday / special-hours date of the owner's branch. */
    public function destroyException(int $branch, int $exception): RedirectResponse
    {
        $model = Branch::forOwner(auth()->id())->findOrFail($branch);
        BranchHourException::where('branch_id', $model->id)->findOrFail($exception)->delete();

        return back()->with('success', ___('Date removed.'));
    }

    public function destroy(int $branch)
    {
        $model = Branch::forOwner(auth()->id())->findOrFail($branch);

        // Never delete the last branch — a restaurant must keep at least one.
        if (Branch::where('store_id', $model->store_id)->count() <= 1) {
            return back()->with('error', ___('A :noun must keep at least one branch.', ['noun' => store_noun(Store::find($model->store_id), 'gen')]));
        }

        // P6-325: re-home / clear every branch_id row in one transaction; refused while history or live work
        // still points at the branch (see BranchDeletion).
        $why = BranchDeletion::delete($model);
        if ($why !== null) {
            return back()->with('error', match ($why) {
                'history' => ___('This branch has orders, fiscal or accounting records, so it cannot be deleted. Switch it off instead to keep its history.'),
                'stock' => ___('This branch still holds stock. Move or count its stock to zero before deleting it, or switch it off instead.'),
                'unclassified' => ___('This branch cannot be deleted yet: some of its data has no delete rule. Switch it off instead and contact support.'),
                'open_session' => ___('This branch has an open table session. Try again when it has ended.'),
                default => ___('A :noun must keep at least one branch.', ['noun' => store_noun(Store::find($model->store_id), 'gen')]),
            });
        }

        return back()->with('success', ___('Branch deleted.'));
    }
}
