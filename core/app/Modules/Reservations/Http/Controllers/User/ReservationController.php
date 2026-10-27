<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Http\Controllers\User;

use App\Models\Reservation;
use App\Models\StoreOption;
use App\Services\ReservationDepositService;
use App\Services\WebhookFanout;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Juwri\Core\Contracts\Google\GoogleMerchantId;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Reservations\Services\ReservationBookingService;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedValidator;
use Juwri\Modules\Reservations\Services\Rwg\ReservationAvailabilityService;
use Juwri\Modules\Reservations\Services\Rwg\RwgFeedDrop;
use Juwri\Modules\Reservations\Services\Rwg\RwgService;

class ReservationController extends Controller
{
    /**
     * P6-337: the only legal status transitions for the reservation-desk
     * `updateStatus` action. Forward arc: pending → confirmed/cancelled/no_show/seated;
     * confirmed → seated/cancelled/no_show; seated is the dine-in endpoint with no
     * onward transitions (the "completed" status does not exist anywhere in the
     * codebase — verified by grep across `app/Modules/Reservations` and the
     * migrations). The terminal cancelled/no_show states may be REVIVED back into
     * pending/confirmed; any revive re-checks the blackout window and the
     * table-time slot, so a revoked blackout or a booking that filled the slot
     * while we were away refuses the revive.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled', 'no_show', 'seated'],
        'confirmed' => ['seated', 'cancelled', 'no_show'],
        'seated' => [],
        'cancelled' => ['pending', 'confirmed'],
        'no_show' => ['pending', 'confirmed'],
    ];

    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids(); // BL-036: a staff session is bound to its own store
    }

    /**
     * Is a posted reserved_at (the diner's local wall-clock) already in the past,
     * judged in the STORE's own timezone? Replaces a bare after:now, which used
     * the platform timezone and wrongly rejected valid bookings for stores
     * elsewhere.
     */
    private function reservationInPast(string $reservedAt, int $storeId): bool
    {
        $tz = store_tz($storeId);
        try {
            return Carbon::parse($reservedAt, $tz)->lte(Carbon::now($tz));
        } catch (\Throwable $e) {
            return false; // an unparseable value is already rejected by the date rule
        }
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = (int) $request->query('store_id', dashboard_current_store()->id ?? $storeIds[0] ?? 0);

        if ($storeId && ! in_array($storeId, $storeIds)) {
            abort(403);
        }

        $branchId = dashboard_request_branch_id($storeId ?: null);

        // "Now" in the store's timezone — reserved_at is the diner's wall-clock, so
        // the upcoming/past split has to be judged on the store's own clock.
        $storeNow = now($storeId ? store_tz($storeId) : null);

        $upcoming = Reservation::forRestaurant($storeId)->upcoming($storeId ?: null)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('reserved_at')->get();

        $past = Reservation::forRestaurant($storeId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where(function ($q) use ($storeNow) {
                $q->where('reserved_at', '<', $storeNow)
                    ->orWhereIn('status', ['cancelled', 'no_show', 'seated']);
            })
            ->orderByDesc('reserved_at')->limit(40)->get();

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        // C7: Reserve-with-Google card state (current per-store settings + platform status).
        $rwg = $this->rwgCardData($storeId);

        return view('owner.tables.reservations', compact('upcoming', 'past', 'stores', 'storeId', 'rwg'));
    }

    /**
     * C7: data for the "Reserve with Google" dashboard card — the store's current
     * availability settings, its toggle state, and the platform integration status
     * (dark until an owner wires Actions Center creds).
     *
     * @return array<string,mixed>
     */
    private function rwgCardData(int $storeId): array
    {
        $avail = app(ReservationAvailabilityService::class);
        $covers = $storeId ? $avail->coversPerSlot($storeId) : ReservationAvailabilityService::DEFAULT_COVERS;

        // Platform feed artifact freshness (feeds are platform-wide).
        $feedFile = RwgFeedBuilder::feedDir().'/merchant_feed.json';
        $feedAt = is_file($feedFile) ? Carbon::createFromTimestamp(filemtime($feedFile)) : null;

        return [
            'enabled' => $storeId ? (bool) store_options($storeId, 'rwg_reservations_enabled') : false,
            'placeId' => $storeId ? (string) Store::query()->whereKey($storeId)->value('google_place_id') : '',
            'interval' => $storeId ? $avail->slotInterval($storeId) : ReservationAvailabilityService::DEFAULT_INTERVAL,
            'covers' => $covers,
            'maxParty' => $storeId ? $avail->maxParty($storeId) : ReservationAvailabilityService::DEFAULT_MAX_PARTY,
            'horizon' => $storeId ? $avail->horizonDays($storeId) : ReservationAvailabilityService::DEFAULT_HORIZON_DAYS,
            'platformOn' => RwgService::enabled(),
            'driver' => RwgService::driver(),
            'coversUnlimited' => $covers <= 0,
            'feedAt' => $feedAt,
            // P5-23: Google ordering panel state. The feed run is platform-wide.
            'ordering' => $storeId ? (bool) store_options($storeId, 'google_ordering_enabled') : false,
            'cancelWindow' => $storeId ? (int) store_options($storeId, 'reservation_cancel_window_minutes', 0) : 0,
            'lastDrop' => $this->lastFeedDrop(),
            // P5-30: per-branch Google merchant + place-id state (empty row when no store selected).
            'branches' => $storeId ? $this->googleBranches($storeId) : [],
        ];
    }

    /**
     * P5-30: every branch of the owner-checked store, with its Google merchant and
     * place-id state for the "Google branches" panel. A branch is ELIGIBLE (published
     * as its own Google merchant) when it is active and carries both coordinates (the
     * same rule as Storefront's GoogleOrderingMerchantSource::eligibleBranches(), restated
     * here because Reservations may not depend on Storefront) — `$single` (exactly one
     * eligible branch) keeps the historic `store-<storeId>` merchant id. Branches are
     * always read scoped to the store id ($storeId has already been owned by the
     * caller); a branch id alone is never trusted.
     *
     * @return list<array{id: int, name: string, merchant_id: string|null, place_id: string|null, eligible: bool}>
     */
    private function googleBranches(int $storeId): array
    {
        $branches = Branch::query()->where('store_id', $storeId)->orderBy('id')->get();
        $isEligible = fn (Branch $b): bool => (bool) $b->active && $b->latitude !== null && $b->longitude !== null;
        $single = $branches->filter($isEligible)->count() === 1;

        $out = [];
        foreach ($branches as $branch) {
            $eligible = $isEligible($branch);

            $out[] = [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'merchant_id' => $eligible ? GoogleMerchantId::format($storeId, (int) $branch->id, $single) : null,
                'place_id' => $branch->google_place_id !== null ? (string) $branch->google_place_id : null,
                'eligible' => $eligible,
            ];
        }

        return $out;
    }

    /**
     * P5-23: the platform-wide last feed-drop status, shaped for the store's Google
     * panel. A missing or invalid record (unknown status) reads as null.
     *
     * @return array{status:string, at:?Carbon, problems:int}|null
     */
    private function lastFeedDrop(): ?array
    {
        $record = RwgFeedDrop::last();
        if (! is_array($record) || ! isset($record['status'])) {
            return null;
        }
        $status = (string) $record['status'];
        if (! in_array($status, ['ok', 'partial', 'failed', 'written'], true)) {
            return null;
        }

        $at = null;
        if (! empty($record['at'])) {
            try {
                $at = Carbon::parse((string) $record['at']);
            } catch (\Throwable $e) {
                $at = null;
            }
        }

        return [
            'status' => $status,
            'at' => $at,
            'problems' => (int) ($record['problem_count'] ?? 0),
        ];
    }

    public function store(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();

        $data = $request->validate([
            'store_id' => 'required|integer',
            'name' => 'required|string|max:80',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'party_size' => 'required|integer|min:1|max:50',
            'reserved_at' => 'required|date',
            'table_number' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
        ]);

        if (! in_array((int) $data['store_id'], $storeIds)) {
            abort(403);
        }

        // "In the future" must be judged in the STORE's timezone — reserved_at is
        // the diner's wall-clock, and a bare after:now would reject valid
        // near-future bookings for any store not on the platform timezone.
        if ($this->reservationInPast($data['reserved_at'], (int) $data['store_id'])) {
            return back()->withErrors(['reserved_at' => ___('The reservation time must be in the future.')])->withInput();
        }

        // E1: refuse a time that falls inside a store blackout window.
        if (ReservationBookingService::blackoutHit((int) $data['store_id'], $data['reserved_at'])) {
            return back()->withErrors(['reserved_at' => ___('That date is not available for reservations.')])->withInput();
        }

        // E1: table double-book guard — only when a table is named (tableless skips).
        if (ReservationBookingService::clashesForCreate((int) $data['store_id'], $data['table_number'] ?? null, $data['reserved_at'])) {
            return back()->withErrors(['table_number' => ___('That table is already booked around this time.')])->withInput();
        }

        $data['status'] = 'confirmed';
        $data['token'] = \Str::random(40);

        // P6-239: re-check the clash and insert under one store+table lock.
        try {
            $reservation = ReservationBookingService::withTableLock(
                (int) $data['store_id'], $data['table_number'] ?? null, $data['reserved_at'],
                fn () => Reservation::create($data)
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['table_number' => ___('That table is already booked around this time.')])->withInput();
        }

        // E10: reservation.created webhook.
        WebhookFanout::forRestaurant((int) $data['store_id'], 'reservation.created', [
            'reservation_id' => $reservation->id, 'status' => $reservation->status, 'source' => 'desk',
        ]);

        return back()->with('success', ___('Reservation added.'));
    }

    /**
     * E1: reservation booking rules — clash buffer + blackout windows. Kept out of
     * the big posts/edit form (and its StoreController whitelist) on purpose; these
     * are reservation-desk settings and live beside the reservations screen.
     * `blackouts` arrives as a JSON string in the §5 shape.
     */
    public function saveSettings(Request $request): RedirectResponse
    {
        $storeIds = $this->ownerRestaurantIds();
        if ($request->has('google_place_id')) {
            $request->merge(['google_place_id' => trim((string) $request->input('google_place_id'))]);
        }

        $data = $request->validate([
            'store_id' => 'required|integer',
            'reservation_buffer_minutes' => 'nullable|integer|min:0|max:1440',
            'blackouts' => 'nullable|string|max:20000',
            // C7: Reserve-with-Google availability settings (lane-owned save, avoids
            // the StoreController whitelist per SPEC §9).
            'reservation_slot_interval' => 'nullable|integer|min:5|max:240',
            'reservation_covers_per_slot' => 'nullable|integer|min:0|max:100000',
            'reservation_max_party' => 'nullable|integer|min:1|max:100',
            'reservation_horizon_days' => 'nullable|integer|min:1|max:365',
            // P5-23: Google ordering (store option, written every save) + cancel window.
            'google_ordering_enabled' => 'boolean',
            'reservation_cancel_window_minutes' => 'nullable|integer|min:0|max:10080',
            'google_place_id' => ['nullable', 'string', 'regex:'.RwgFeedValidator::PLACE_ID],
        ]);

        if (! in_array((int) $data['store_id'], $storeIds, true)) {
            abort(403);
        }
        $rid = (int) $data['store_id'];

        // Two independent cards post here; a hidden `settings_form` marks which one so
        // saving the RwG card never resets the buffer/blackout card and vice-versa.
        // Absent marker ⇒ the original E1 booking-rules form (back-compat).
        $section = (string) $request->input('settings_form', 'buffer');

        if ($section === 'rwg') {
            StoreOption::updateStoreOption($rid, 'rwg_reservations_enabled', $request->boolean('rwg_reservations_enabled') ? '1' : '0');
            // P5-23: written every save, like rwg_reservations_enabled.
            StoreOption::updateStoreOption($rid, 'google_ordering_enabled', $request->boolean('google_ordering_enabled') ? '1' : '0');
            if ($request->has('reservation_cancel_window_minutes')) {
                StoreOption::updateStoreOption($rid, 'reservation_cancel_window_minutes', (string) (int) ($data['reservation_cancel_window_minutes'] ?? 0));
            }
            if ($request->has('google_place_id')) {
                $placeId = trim((string) ($data['google_place_id'] ?? '')) ?: null;
                if ($placeId !== null && Store::query()->where('google_place_id', $placeId)->where('id', '!=', $rid)->exists()) {
                    return back()->withErrors(['google_place_id' => ___('This Google place ID is already used by another store.')])->withInput();
                }
                Store::query()->whereKey($rid)->update(['google_place_id' => $placeId]);
            }
            $rwgKeys = [
                'reservation_slot_interval' => ReservationAvailabilityService::DEFAULT_INTERVAL,
                'reservation_covers_per_slot' => ReservationAvailabilityService::DEFAULT_COVERS,
                'reservation_max_party' => ReservationAvailabilityService::DEFAULT_MAX_PARTY,
                'reservation_horizon_days' => ReservationAvailabilityService::DEFAULT_HORIZON_DAYS,
            ];
            foreach ($rwgKeys as $key => $default) {
                if ($request->has($key)) {
                    StoreOption::updateStoreOption($rid, $key, (string) (int) ($data[$key] ?? $default));
                }
            }

            return back()->with('success', ___('Google settings saved.'));
        }

        $buffer = (int) ($data['reservation_buffer_minutes'] ?? ReservationBookingService::WINDOW_MIN);
        StoreOption::updateStoreOption($rid, 'reservation_buffer_minutes', (string) ($buffer > 0 ? $buffer : ReservationBookingService::WINDOW_MIN));

        $blackouts = $this->normalizeBlackouts($data['blackouts'] ?? '');
        if ($blackouts === false) {
            return back()->withErrors(['blackouts' => ___('Blackout list must be a valid JSON array of dates or {from,to} ranges.')])->withInput();
        }
        StoreOption::updateStoreOption($rid, 'reservation_blackouts', json_encode($blackouts));

        return back()->with('success', ___('Reservation settings saved.'));
    }

    /**
     * Validate + normalize the blackouts JSON string. Returns the cleaned array,
     * or false when the payload is not valid JSON of the expected shape. An empty
     * string clears the list.
     *
     * @return array<int,array<string,string>>|false
     */
    private function normalizeBlackouts(string $raw): array|false
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return false;
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                return false;
            }
            if (! empty($entry['date'])) {
                try {
                    $out[] = ['date' => Carbon::parse((string) $entry['date'])->format('Y-m-d')];
                } catch (\Throwable $e) {
                    return false;
                }
            } elseif (! empty($entry['from']) && ! empty($entry['to'])) {
                try {
                    $out[] = [
                        'from' => Carbon::parse((string) $entry['from'])->format('Y-m-d H:i'),
                        'to' => Carbon::parse((string) $entry['to'])->format('Y-m-d H:i'),
                    ];
                } catch (\Throwable $e) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return $out;
    }

    public function updateStatus(Request $request, int $id)
    {
        $storeIds = $this->ownerRestaurantIds();
        $res = Reservation::whereIn('store_id', $storeIds)->findOrFail($id);

        $status = $request->validate(['status' => 'required|in:pending,confirmed,cancelled,no_show,seated'])['status'];

        // P6-337: compare-and-set under a row lock so a second click that asks for
        // the same status is a no-op (no webhook, no deposit side effect), and a
        // forbidden transition is rejected without ever touching the row.
        [$changed, $error] = $this->applyStatusTransition((int) $res->id, $status);

        if ($error !== null) {
            return back()->withErrors(['status' => $error]);
        }

        if ($changed) {
            // E10: reservation.cancelled webhook.
            if ($status === 'cancelled') {
                WebhookFanout::forRestaurant((int) $res->store_id, 'reservation.cancelled', [
                    'reservation_id' => $res->id, 'status' => 'cancelled',
                ]);
            }

            // T5: deposit lifecycle — cancel refunds per policy, no-show keeps the deposit.
            $res->refresh();
            if ($res->deposit_status === 'paid') {
                if ($status === 'cancelled') {
                    ReservationDepositService::refund($res);
                } elseif ($status === 'no_show') {
                    ReservationDepositService::keep($res);
                }
            }
        }

        return back()->with('success', ___('Reservation updated.'));
    }

    /**
     * BL-016 (Opus: money): the owner refunded a `refund_due` deposit in the provider dashboard and
     * confirms it here. Scoped to the signed-in owner's stores (another store's reservation is 404).
     * Only `refund_due` is accepted; an already-`refunded` deposit is a no-op success, so a second click
     * books nothing. The service re-checks the state under the row lock, so two concurrent posts book
     * the ledger legs once (the legs also carry idempotent refs).
     */
    public function confirmManualDepositRefund(int $id): RedirectResponse
    {
        $res = Reservation::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($id);

        if ($res->deposit_status === 'refunded') {
            return back()->with('success', ___('Deposit already marked as refunded.'));
        }
        if ($res->deposit_status !== 'refund_due') {
            return back()->withErrors(['deposit' => ___('Only a deposit with a refund due can be marked as refunded.')]);
        }

        // Books the ledger legs and writes the `reservation.deposit_refunded` (manual) activity entry
        // with this owner as actor — once, under the reservation's row lock.
        ReservationDepositService::confirmManualRefund($res);

        return back()->with('success', ___('Deposit marked as refunded.'));
    }

    /**
     * P6-337: validate + apply the requested status change atomically.
     *
     * Returns [bool $changed, ?string $error]:
     *   - changed=true, error=null  → the row's status was actually updated;
     *   - changed=false, error=null → same status, treat as a no-op success;
     *   - changed=false, error!=null → a user-visible error message.
     *
     * Reviving a cancelled/no_show row out of a terminal state re-checks the
     * store blackout window and the table-time slot (excluding this reservation
     * from the clash query) inside the transaction, so a stale "revive" cannot
     * quietly hijack a slot another confirmed booking now occupies.
     *
     * @return array{0:bool,1:?string}
     */
    private function applyStatusTransition(int $id, string $newStatus): array
    {
        return DB::transaction(function () use ($id, $newStatus) {
            $locked = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $oldStatus = (string) $locked->status;

            if ($oldStatus === $newStatus) {
                return [false, null];
            }

            $allowed = self::ALLOWED_TRANSITIONS[$oldStatus] ?? [];
            if (! in_array($newStatus, $allowed, true)) {
                return [false, ___('This status change is not allowed.')];
            }

            // Reviving out of a terminal state — re-check the booking is still bookable.
            if (in_array($oldStatus, ['cancelled', 'no_show'], true)) {
                $storeId = (int) $locked->store_id;
                $reservedAt = $locked->reserved_at->format('Y-m-d H:i:s');

                if (ReservationBookingService::blackoutHit($storeId, $reservedAt)) {
                    return [false, ___('That date is not available for reservations.')];
                }

                try {
                    // the status is written while the table lock is held, so no other booking can take the slot between
                    // the clash check and this write
                    ReservationBookingService::withTableLock(
                        $storeId,
                        $locked->table_number,
                        $reservedAt,
                        static fn () => $locked->forceFill(['status' => $newStatus])->save(),
                        (int) $locked->id
                    );
                } catch (\RuntimeException $e) {
                    return [false, ___('That table is already booked around this time.')];
                }

                return [true, null];
            }

            $locked->status = $newStatus;
            $locked->save();

            return [true, null];
        });
    }

    /**
     * Public booking endpoint (for aurora public menu).
     */
    public function publicBook(Request $request, string $slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();

        // Only stores that opted in accept bookings; the themes gate their booking sheet
        // on the same option, so a store with it off has no form pointing here.
        abort_unless(store_options($store->id, 'enable_reservations'), 404);

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'party_size' => 'required|integer|min:1|max:50',
            'reserved_at' => 'required|date',
            'table_number' => 'nullable|string|max:20',
            'branch_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
        ]);

        // Future check in the store's own timezone (see store()).
        if ($this->reservationInPast($data['reserved_at'], (int) $store->id)) {
            return back()->withErrors(['reserved_at' => ___('The reservation time must be in the future.')])->withInput();
        }

        // E1: honour store blackout windows on the public path too.
        if (ReservationBookingService::blackoutHit((int) $store->id, $data['reserved_at'])) {
            return back()->withErrors(['reserved_at' => ___('That date is not available for reservations.')])->withInput();
        }

        // E1: table double-book guard (tableless public bookings skip it).
        if (ReservationBookingService::clashesForCreate((int) $store->id, $data['table_number'] ?? null, $data['reserved_at'])) {
            return back()->withErrors(['table_number' => ___('That table is already booked around this time.')])->withInput();
        }

        // E1: a branch, when passed, must belong to this store; otherwise store-level (null).
        if (! empty($data['branch_id'])
            && ! Branch::where('store_id', $store->id)->whereKey($data['branch_id'])->exists()) {
            unset($data['branch_id']);
        }

        // C3 (RwG): shared covers-capacity guard — ONE truth for the storefront and
        // the Google booking server. Buckets reserved_at into a covers slot and
        // rejects (302 back-with-errors, matching this endpoint's guard contract —
        // NOT 422) when the party will not fit. No-op for unlimited-covers stores
        // (the load-bearing default), so a store that never configured capacity is
        // unchanged. lockSlot serialises concurrent bookers on the slot key and is
        // held across the INSERT.
        // Capacity (covers_per_slot) is a STORE-WIDE option and coverCounts is
        // branch-agnostic, so the slot must be bucketed in the STORE tz on both
        // sides. Passing the branch here made lockSlot bucket in the branch tz
        // (branch->timezone) while the booked-covers were counted in the store tz —
        // a mismatch that could mis-bucket (over-book / falsely reject) when a
        // branch tz differed from the store tz. Pass null (matching the Google
        // booking-server path) so both sides bucket in the store tz.
        $availability = app(ReservationAvailabilityService::class);
        try {
            $at = Carbon::parse($data['reserved_at'], store_tz((int) $store->id));
            $lock = $availability->lockSlot((int) $store->id, null, $at, (int) $data['party_size']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['party_size' => $e->getMessage()])->withInput();
        }

        $data['store_id'] = $store->id;
        $data['status'] = 'pending';
        $data['token'] = \Str::random(40);

        try {
            // P6-239: re-check the clash and insert under one store+table lock.
            $reservation = ReservationBookingService::withTableLock(
                (int) $store->id, $data['table_number'] ?? null, $data['reserved_at'],
                fn () => Reservation::create($data)
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['table_number' => ___('That table is already booked around this time.')])->withInput();
        } finally {
            optional($lock)->release();
        }

        // E10: reservation.created webhook (public request path).
        WebhookFanout::forRestaurant((int) $store->id, 'reservation.created', [
            'reservation_id' => $reservation->id, 'status' => $reservation->status, 'source' => 'request',
        ]);

        // T5: if this store takes an anti-no-show deposit for a party this size, the
        // booking is not complete until the deposit is paid — route the diner to the
        // deposit step (server-decided; the booking form itself is unchanged). Before
        // this, initiate()/required() had no live caller, so a configured deposit was
        // never taken.
        if (ReservationDepositService::required((int) $store->id, (int) $data['party_size'])) {
            return redirect()->route('publicView.reservation.deposit', [
                'slug' => $store->slug,
                'token' => $reservation->token,
            ]);
        }

        return back()->with('reservation_success', ___('Your reservation request has been submitted!'));
    }

    /**
     * T5 deposit step (storefront, anonymous): show the amount + installed gateways for a
     * reservation that requires a deposit. Capability is the reservation's random token.
     */
    public function depositPay(Request $request, string $slug, string $token): View|RedirectResponse
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        abort_unless(store_options($store->id, 'enable_reservations'), 404);

        $reservation = Reservation::where('store_id', $store->id)->where('token', $token)->firstOrFail();

        // Only a still-unpaid reservation that actually needs a deposit shows the page.
        if (! ReservationDepositService::required((int) $store->id, (int) $reservation->party_size)
            || in_array($reservation->deposit_status, ['paid', 'refunded', 'refund_due', 'kept'], true)) {
            return redirect()->route('publicView', $store->slug);
        }

        return view('reservations.deposit-pay', [
            'store' => $store,
            'reservation' => $reservation,
            'amount' => ReservationDepositService::amountFor((int) $store->id, (int) $reservation->party_size),
            'currency' => ReservationDepositService::currencyFor((int) $store->id),
            // P1-23: the store's own gateways — the deposit is paid to the store, never to the platform.
            'gateways' => app(CustomerGateways::class)->forStore((int) $store->id),
        ]);
    }

    /**
     * T5 deposit charge (storefront, anonymous): open the deposit Transaction and hand off
     * to the chosen installed gateway — the exact pattern GiftCardController uses. On
     * gateway confirmation the shared choke point (CheckoutController) calls
     * ReservationDepositService::markPaid(), which confirms the reservation.
     */
    public function depositCharge(Request $request, string $slug, string $token): View|RedirectResponse
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        abort_unless(store_options($store->id, 'enable_reservations'), 404);

        $reservation = Reservation::where('store_id', $store->id)->where('token', $token)->firstOrFail();

        abort_unless(ReservationDepositService::required((int) $store->id, (int) $reservation->party_size), 404);
        if (in_array($reservation->deposit_status, ['paid', 'refunded', 'refund_due', 'kept'], true)) {
            return redirect()->route('publicView', $store->slug);
        }

        $data = $request->validate(['payment_method' => ['required']]);
        $folder = app(CustomerGateways::class)->resolve((int) $store->id, $data['payment_method']);
        $gateway = $folder !== null ? (object) ['payment_folder' => $folder] : null;
        if (! $gateway) {
            return back()->withErrors(['payment_method' => ___('Please choose a valid payment method.')]);
        }

        // initiate() re-asserts the store's entitlement (the money door) before opening a charge.
        $transaction = ReservationDepositService::initiate($reservation, $gateway->payment_folder);

        $paymentController = 'App\\Http\\Controllers\\User\\PaymentMethods\\'
            .str_replace(' ', '', ucwords(str_replace('_', ' ', $gateway->payment_folder)))
            .'Controller';

        return $paymentController::pay($transaction);
    }
}
