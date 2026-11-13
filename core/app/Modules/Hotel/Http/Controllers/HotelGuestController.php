<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Http\Controllers;

use App\Models\KitchenStation;
use App\Models\StoreTable;
use App\Support\OutboundText;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\TableKeyGuard;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelBookingNight;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;
use Juwri\Modules\Hotel\Services\Hotel\RateService;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Payments\Models\LedgerEntry;

/**
 * FEATURES-RUN-4 T1 — Guest-facing hotel endpoints (public, from the room QR).
 *
 * H9: the guest info hub (Wi-Fi, house rules, breakfast, reception contact),
 * content sourced from `hotel_settings` via {@see HotelService::settings()}.
 * H5: guest submission of a service request from the room, routed to a
 * department through the hotel_settings.departments map.
 *
 * All endpoints 404 when hotel_mode is off for the restaurant.
 */
class HotelGuestController extends Controller
{
    private function resolveStore(string $slug): Store
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        abort_unless(HotelService::enabled($store->id), 404);

        return $store;
    }

    /**
     * Resolve the room row (store_tables) for a QR table key, if given. A non-string key (`table[]=`) is never a
     * room and counts as a miss; after too many unknown keys every key answers "no room" without a lookup
     * ({@see TableKeyGuard}).
     */
    private function room(Store $store, mixed $tableKey): ?StoreTable
    {
        if ($tableKey === null || $tableKey === '') {
            return null;
        }
        if (! is_string($tableKey)) {
            TableKeyGuard::miss((int) $store->id);

            return null;
        }
        if (TableKeyGuard::blocked((int) $store->id)) {
            return null;
        }

        $room = StoreTable::where('store_id', $store->id)
            ->where('key', $tableKey)
            ->whereIn('area_type', ['room', 'zone'])
            ->first();
        if (! $room) {
            TableKeyGuard::miss((int) $store->id);
        }

        return $room;
    }

    /** H9 — public guest info hub. */
    public function info(Request $request, string $slug)
    {
        $store = $this->resolveStore($slug);
        // NB: must NOT be named `$settings` — the global view composer injects the
        // site settings object under that name and would shadow this in the view
        // (guest-info then did $settings['wifi'] on a stdClass → 500). [[view-settings-collision]]
        $hotelSettings = HotelService::settings($store->id);
        $room = $this->room($store, $request->query('table'));

        /* C5 — the Wi-Fi credentials are shown ONLY to a guest who proves room presence:
           a valid scanned room key, or a valid active-stay token. A bare store slug (the
           public menu URL, a crawler) still sees house rules / breakfast / reception, but
           not the password — the page has no noindex history and slugs are public. */
        $wifiVisible = $room !== null;
        if (! $wifiVisible) {
            $token = trim((string) $request->query('token'));
            if (strlen($token) >= 20) {
                $wifiVisible = HotelStay::where('store_id', $store->id)
                    ->where('token', $token)
                    ->where('status', 'checked_in')
                    ->exists();
            }
        }

        return view('hotel.guest-info', compact('store', 'hotelSettings', 'room', 'wifiVisible'));
    }

    /**
     * C3 — guest-facing folio ("my bill"), reached by the per-stay token minted at
     * check-in. Possession of the 48-char token is the capability; no login. Valid
     * only while the stay is active or within 7 days after check-out, so a stale link
     * from a past guest cannot read the folio indefinitely. Any miss (unknown token,
     * expired grace, hotel mode off) is an indistinguishable 404 — no oracle.
     */
    public function folio(Request $request, string $slug, string $token): View
    {
        $store = $this->resolveStore($slug);

        // Length floor before the query: the mint is 48 chars, so a short/garbage
        // token can be rejected without touching the DB.
        $token = trim($token);
        abort_if(strlen($token) < 20, 404);

        $stay = HotelStay::where('store_id', $store->id)
            ->where('token', $token)
            ->first();
        abort_unless($stay !== null, 404);

        // Active, or checked out no more than 7 days ago. A cancelled stay or one
        // checked out longer ago is no longer viewable.
        if (! $stay->isActive()) {
            abort_if(
                $stay->status !== 'checked_out'
                    || $stay->checked_out_at === null
                    || $stay->checked_out_at->lt(now()->subDays(7)),
                404
            );
        }

        // SF2: opening the folio link proves the visitor is the current guest. Stash the stay token so the room's
        // storefront may show this guest their name, bill and folio link (StoreController::currentStayTokenHeld);
        // the printed room token alone never does. Only an active stay is stashed.
        if ($stay->isActive()) {
            $request->session()->put('hotel_stay_token', $token);
        }

        $statement = HotelFolioService::statement($stay);
        $room = $stay->room()->first(); // store_tables row, for the human label

        /* C3 — map each statement line id to its ledger ref so the blade can bucket the
           flat charge rows into a room/nights group and a services group by ref prefix,
           without touching HotelFolioService::statement (lane A). */
        $folioRefs = $this->folioLineRefs($stay);

        return view('hotel.guest-folio', compact('store', 'stay', 'statement', 'room', 'folioRefs'));
    }

    /**
     * C3 — [ledger-entry id => ref] for a stay's folio account. Lets the guest-folio blade
     * group the flat statement lines (which carry no ref) by ref prefix. Returns [] on any
     * miss so the blade simply falls back to the single un-grouped charges list.
     *
     * @return array<int, string>
     */
    private function folioLineRefs(HotelStay $stay): array
    {
        try {
            $account = HotelFolioService::folioAccountFor($stay);

            return LedgerEntry::where('ledger_account_id', $account->id)
                ->pluck('ref', 'id')
                ->map(fn ($r) => (string) $r)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ====================================================================
     | C1/C2 — public room booking (3 modes: off | request | instant).
     * ==================================================================== */

    /**
     * The owner's online-booking mode. `hotel_online_booking` is written by the Hotel
     * settings screen (lane B) and defaults OFF: an absent option (getStoreOption's FALSE
     * miss sentinel), '', '0' and any unknown value all read as 'off'.
     */
    private function onlineBookingMode(int $storeId): string
    {
        $v = strtolower(trim((string) store_options($storeId, 'hotel_online_booking')));

        return in_array($v, ['request', 'instant'], true) ? $v : 'off';
    }

    /** The PMS booking engine (lane A models + migrations) is present in this deploy. */
    private function bookingEngineReady(): bool
    {
        return class_exists(HotelBooking::class)
            && Schema::hasTable('hotel_bookings');
    }

    /**
     * C1 — the public booking page. Anon; gated inside on HotelService::enabled() and the
     * owner's online-booking mode (404 when off). Renders date pickers, room-type cards
     * (availability + nightly total from lane A's BookingService when present) and the
     * guest fields. When the engine has not shipped yet the page degrades to a friendly
     * "booking opens soon" empty state — never a 500.
     */
    public function book(Request $request, string $slug): View
    {
        $store = $this->resolveStore($slug);
        $mode = $this->onlineBookingMode($store->id);
        abort_if($mode === 'off', 404);

        $today = Carbon::today();
        $checkIn = $this->parseDate($request->query('check_in'), $today, $today);
        $out = $this->parseDate($request->query('check_out'), $checkIn->copy()->addDay(), $checkIn->copy()->addDay());
        if ($out->lte($checkIn)) {
            $out = $checkIn->copy()->addDay();
        }

        $currency = store_options($store->id, 'currency_code') ?: config('settings.currency_code', 'USD');
        $types = $this->availableTypes($store, $checkIn, $out);
        $nights = $checkIn->diffInDays($out);

        return view('hotel.booking', [
            'store' => $store,
            'mode' => $mode,
            'types' => $types,
            'currency' => $currency,
            'checkIn' => $checkIn->toDateString(),
            'checkOut' => $out->toDateString(),
            'nights' => $nights,
            'today' => $today->toDateString(),
        ]);
    }

    /**
     * C1 — submit a booking. request mode → status pending / source request (no room
     * assigned, no race). instant mode → availability re-check + night-row race guard
     * inside a transaction; the UNIQUE(room_id,night) violation is the loser's signal,
     * caught here into a friendly "just taken" re-render. On success redirects to the
     * token manage page (the success surface). No payment/deposit this run.
     */
    public function bookSubmit(Request $request, string $slug): RedirectResponse
    {
        $store = $this->resolveStore($slug);
        $mode = $this->onlineBookingMode($store->id);
        abort_if($mode === 'off', 404);

        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'room_type_id' => ['required', 'integer'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Engine not shipped yet: keep the pre-merge behaviour — degrade gracefully.
        if (! $this->bookingEngineReady()) {
            return back()->withInput()->with('booking_error', ___('Online booking is not available right now. Please contact reception.'));
        }

        // The room type must belong to THIS store — an anon guest could otherwise
        // submit a foreign store's room_type_id and see its name on the manage page.
        if (! RoomType::forRestaurant((int) $store->id)->whereKey((int) $data['room_type_id'])->exists()) {
            return back()->withInput()->with('booking_error', ___('That room type is not available.'));
        }

        $checkIn = Carbon::parse($data['check_in'])->startOfDay();
        $out = Carbon::parse($data['check_out'])->startOfDay();
        $currency = store_options($store->id, 'currency_code') ?: config('settings.currency_code', 'USD');
        $quoted = $this->quoteTotal($store, (int) $data['room_type_id'], $checkIn, $out);

        $base = [
            'store_id' => $store->id,
            'guest_name' => $data['guest_name'],
            'guest_phone' => $data['guest_phone'] ?? null,
            'room_type_id' => (int) $data['room_type_id'],
            'check_in' => $checkIn->toDateString(),
            'check_out' => $out->toDateString(),
            'adults' => (int) ($data['adults'] ?? 2),
            'children' => (int) ($data['children'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'quoted_total' => $quoted,
            'currency' => $currency,
            'code' => $this->newBookingCode(),
            'token' => Str::random(64),
        ];

        if ($mode === 'instant') {
            $booking = $this->createInstantBooking($store, $base, $checkIn, $out);
            if ($booking === null) {
                return back()->withInput()->with('booking_error', ___('Sorry — that room type was just taken for those dates. Please try different dates.'));
            }
        } else {
            $booking = HotelBooking::create($base + ['status' => 'pending', 'source' => 'request']);
        }

        // C2 — owner alerts on create (notif centre + WhatsApp, best-effort).
        try {
            $this->dispatchBookingAlerts($store, $booking, $mode);
        } catch (\Throwable $e) {
            Log::warning('hotel booking alert dispatch failed', ['booking' => $booking->id ?? null, 'error' => $e->getMessage()]);
        }

        if (Route::has('hotel.guest.booking') && ! empty($booking->token)) {
            return redirect()->route('hotel.guest.booking', ['slug' => $store->slug, 'token' => $booking->token]);
        }

        return redirect()->route('hotel.guest.book', $store->slug)->with('booking_success', $booking->code ?? '');
    }

    /**
     * C2 — booking manage page (status + cancel), reached by the 64-char token. Possession
     * of the token is the capability; no login. noindex. Any miss — unknown token, engine
     * not shipped, hotel off — is an indistinguishable 404.
     */
    public function booking(Request $request, string $slug, string $token): View
    {
        $store = $this->resolveStore($slug);
        abort_unless($this->bookingEngineReady(), 404);

        $token = trim($token);
        abort_if(strlen($token) < 20, 404);

        $booking = HotelBooking::where('store_id', $store->id)
            ->where('token', $token)
            ->first();
        abort_unless($booking !== null, 404);

        return view('hotel.booking-manage', [
            'store' => $store,
            'booking' => $booking,
            'typeName' => $this->bookingTypeName($booking),
            'currency' => $booking->currency ?: (store_options($store->id, 'currency_code') ?: config('settings.currency_code', 'USD')),
            'cancellable' => $this->bookingCancellable($booking),
        ]);
    }

    /**
     * C2 — guest cancels a booking within the plan's window. Frees the room via lane A's
     * BookingService::cancel when present (deletes the night rows); otherwise the guarded
     * inline fallback marks it cancelled and clears any night rows itself. Outside the
     * window / wrong status → a friendly notice, never a 500.
     */
    public function bookingCancel(Request $request, string $slug, string $token): RedirectResponse
    {
        $store = $this->resolveStore($slug);
        abort_unless($this->bookingEngineReady(), 404);

        $token = trim($token);
        abort_if(strlen($token) < 20, 404);

        $booking = HotelBooking::where('store_id', $store->id)
            ->where('token', $token)
            ->first();
        abort_unless($booking !== null, 404);

        if (! $this->bookingCancellable($booking)) {
            return back()->with('booking_error', ___('This booking can no longer be cancelled online. Please contact reception.'));
        }

        $done = false;
        try {
            BookingService::cancel($booking);
            $done = true;
        } catch (\Throwable $e) {
            $done = false;
        }
        if (! $done) {
            DB::transaction(function () use ($booking) {
                $booking->update(['status' => 'cancelled']);
                if (class_exists(HotelBookingNight::class)) {
                    HotelBookingNight::where('booking_id', $booking->id)->delete();
                }
            });
        }

        return redirect()
            ->route('hotel.guest.booking', ['slug' => $store->slug, 'token' => $token])
            ->with('booking_cancelled', true);
    }

    /**
     * Is a booking cancellable online right now? pending/confirmed only, the plan must be
     * refundable, and now must be before check-in minus the plan's cancel_before_hours.
     * Default plan (no rate plan / RatePlan not shipped) is refundable with 24h notice.
     */
    private function bookingCancellable(object $booking): bool
    {
        if (! in_array((string) ($booking->status ?? ''), ['pending', 'confirmed'], true)) {
            return false;
        }
        [$refundable, $beforeHours] = $this->cancelPolicy($booking);
        if (! $refundable) {
            return false;
        }
        try {
            $deadline = Carbon::parse((string) $booking->check_in)->startOfDay()->subHours($beforeHours);
        } catch (\Throwable $e) {
            return false;
        }

        return Carbon::now()->lt($deadline);
    }

    /**
     * The booking's cancellation policy [refundable, cancel_before_hours] from its rate
     * plan, defaulting to [true, 24] when there is no plan or the RatePlan model has not
     * shipped yet.
     *
     * @return array{0: bool, 1: int}
     */
    private function cancelPolicy(object $booking): array
    {
        $refundable = true;
        $before = 24;
        try {
            $planId = (int) ($booking->rate_plan_id ?? 0);
            if ($planId && class_exists(RatePlan::class)) {
                $plan = RatePlan::find($planId);
                if ($plan) {
                    $refundable = (bool) ($plan->refundable ?? true);
                    $before = (int) ($plan->cancel_before_hours ?? 24);
                }
            }
        } catch (\Throwable $e) {
            // defaults hold
        }

        return [$refundable, $before];
    }

    /** Human room-type name for a booking, via RoomType when shipped; else a generic label. */
    private function bookingTypeName(object $booking): string
    {
        $id = (int) ($booking->room_type_id ?? 0);
        $rid = (int) ($booking->store_id ?? 0);
        if ($id && $rid && class_exists(RoomType::class)) {
            try {
                // Scope to the booking's own store so a foreign room_type_id can never
                // surface another tenant's room-type name on the manage page.
                $name = RoomType::where('id', $id)->where('store_id', $rid)->value('name');
                if ($name) {
                    return (string) $name;
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return ___('Room');
    }

    /**
     * C2 — owner alerts on booking create. Notification centre (default ON) + WhatsApp
     * (default OFF), per the hotel_alerts_* keys, mirroring dispatchRequestAlerts. The
     * notif links to the PMS booking (lane A) when that route exists, else the stays list.
     * Best-effort: a channel outage must never fail the booking (already saved).
     */
    private function dispatchBookingAlerts(Store $store, object $booking, string $mode): void
    {
        $typeName = $this->bookingTypeName($booking);
        $in = (string) ($booking->check_in ?? '');
        $out = (string) ($booking->check_out ?? '');

        if ($this->alertChannelOn($store->id, 'hotel_alerts_notif', true)) {
            $head = $mode === 'instant' ? ___('New booking') : ___('New booking request');
            $title = $head.' — '.___('Room type').' '.$this->mdEscape($typeName).', '.$this->mdEscape($in).'→'.$this->mdEscape($out);
            $link = Route::has('hotel.pms.bookings.show')
                ? route('hotel.pms.bookings.show', ['booking' => $booking->id ?? 0], false)
                : (Route::has('hotel.stays') ? route('hotel.stays', [], false) : route('dashboard', [], false));
            try {
                CreateNotificationJob::dispatch($title, 'hotel_booking', $link)->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('hotel booking notif dispatch failed', ['booking' => $booking->id ?? null, 'error' => $e->getMessage()]);
            }
        }

        if ($this->alertChannelOn($store->id, 'hotel_alerts_whatsapp', false)) {
            $settings = HotelService::settings($store->id);
            $to = whatsapp_normalize_number((string) ($settings['contact']['whatsapp'] ?? ''));
            if ($to !== '') {
                $msg = $typeName.' · '.$in.'→'.$out.' · '.OutboundText::name((string) ($booking->guest_name ?? ''), 80);
                try {
                    app(MessageQueue::class)->whatsApp($to, $msg, $store->id, 'hotel_booking', afterCommit: true);
                } catch (\Throwable $e) {
                    Log::warning('hotel booking whatsapp dispatch failed', ['booking' => $booking->id ?? null, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * instant confirm: pick a free room of the type and, inside a transaction, write the
     * booking plus one hotel_booking_nights row per night. The UNIQUE(room_id,night) is
     * the real race guard (sqlite lockForUpdate is a no-op); a concurrent winner's rows
     * make ours throw QueryException → we return null so the caller re-renders "just
     * taken". Returns the confirmed booking, or null when full / raced.
     *
     * @param  array<string, mixed>  $base
     */
    private function createInstantBooking(Store $store, array $base, Carbon $checkIn, Carbon $out): ?HotelBooking
    {
        $nights = [];
        for ($d = $checkIn->copy(); $d->lt($out); $d->addDay()) {
            $nights[] = $d->toDateString();
        }
        if (empty($nights)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($store, $base, $nights) {
                $room = $this->pickFreeRoom($store, (int) $base['room_type_id'], $nights);
                if ($room === null) {
                    return null;
                }
                $booking = HotelBooking::create($base + [
                    'status' => 'confirmed',
                    'source' => 'instant',
                    'room_id' => $room->id,
                ]);
                foreach ($nights as $night) {
                    HotelBookingNight::create([
                        'booking_id' => $booking->id,
                        'room_id' => $room->id,
                        'night' => $night,
                    ]);
                }

                return $booking;
            });
        } catch (QueryException $e) {
            // UNIQUE(room_id,night) — someone else took the last room mid-transaction.
            return null;
        }
    }

    /**
     * A room of the requested type with no booked night in the range. Best-effort and
     * fully guarded: the assignment is still race-protected by the night-row UNIQUE, so
     * a stale read here only means the transaction rolls back and the guest retries.
     *
     * @param  list<string>  $nights
     */
    private function pickFreeRoom(Store $store, int $roomTypeId, array $nights): ?StoreTable
    {
        $rooms = StoreTable::where('store_id', $store->id)
            ->where('area_type', 'room')
            ->when(Schema::hasColumn('store_tables', 'room_type_id'), fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->when(Schema::hasColumn('store_tables', 'oo_status'), fn ($q) => $q->where(fn ($w) => $w->whereNull('oo_status')->orWhere('oo_status', 'none')))
            ->orderBy('id')
            ->get();

        foreach ($rooms as $room) {
            $taken = HotelBookingNight::where('room_id', $room->id)
                ->whereIn('night', $nights)
                ->exists();
            if (! $taken) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Nightly total for a type over the range, via lane A's RateService when present.
     * Null (not zero) when the rate engine has not shipped, so the booking stores a
     * blank quote rather than a misleading 0.
     */
    private function quoteTotal(Store $store, int $roomTypeId, Carbon $checkIn, Carbon $out): ?float
    {
        $cls = RateService::class;
        if (! class_exists($cls)) {
            return null;
        }
        try {
            $type = RoomType::forRestaurant((int) $store->id)->where('id', $roomTypeId)->first();
            if (! $type) {
                return null;
            }
            $plan = $cls::ensureDefaultPlan((int) $store->id);
            $quote = $cls::quote($type, $plan, $checkIn->toDateString(), $out->toDateString());

            return (float) $quote['total'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Room-type availability cards for the range, via BookingService when it has shipped.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableTypes(Store $store, Carbon $checkIn, Carbon $out): array
    {
        $svcCls = BookingService::class;
        $rateCls = RateService::class;
        if (! class_exists($svcCls) || ! class_exists($rateCls)) {
            return [];
        }
        try {
            $in = $checkIn->toDateString();
            $outStr = $out->toDateString();
            // typeAvailability returns a [type_id => available_count] map — build a
            // display card per active room type with its nightly quote (mirrors the
            // owner-side availability endpoint; the blade reads id/name/base_rate/
            // available/nightly_total).
            $avail = $svcCls::typeAvailability((int) $store->id, $in, $outStr);
            $plan = $rateCls::ensureDefaultPlan((int) $store->id);
            $types = RoomType::forRestaurant((int) $store->id)
                ->where('active', true)->orderBy('sort')->orderBy('id')->get();

            $cards = [];
            foreach ($types as $type) {
                $quote = $rateCls::quote($type, $plan, $in, $outStr);
                $cards[] = [
                    'id' => (int) $type->id,
                    'name' => $type->name,
                    'base_rate' => (float) $type->base_rate,
                    'available' => (int) ($avail[(int) $type->id] ?? 0),
                    'nightly_total' => (float) $quote['total'],
                ];
            }

            return $cards;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** A short, human-typable public booking reference (A–Z0–9, collision-checked). */
    private function newBookingCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (class_exists(HotelBooking::class) && HotelBooking::where('code', $code)->exists());

        return $code;
    }

    /** Parse a Y-m-d query date, clamped to a floor; falls back when missing/invalid. */
    private function parseDate(?string $raw, Carbon $fallback, Carbon $floor): Carbon
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return $fallback->copy();
        }
        try {
            $d = Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable $e) {
            return $fallback->copy();
        }

        return $d->lt($floor) ? $floor->copy() : $d;
    }

    /** H5 — guest raises a service request from the room QR. */
    public function requestStore(Request $request, string $slug)
    {
        $store = $this->resolveStore($slug);

        $data = $request->validate([
            'table' => ['nullable', 'string', 'max:191'],
            'type' => ['required', 'in:'.implode(',', ServiceRequest::TYPES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // A room key was given but this visitor is cut off by the key guard: refuse, never file the request
        // without its room (staff would not know where to go).
        if (($data['table'] ?? '') !== '' && TableKeyGuard::blocked((int) $store->id)) {
            $message = ___('Too many wrong codes. Please ask a member of staff.');

            return $request->wantsJson()
                ? response()->json(['ok' => false, 'message' => $message], 429)
                : back()->with('error', $message);
        }

        $room = $this->room($store, $data['table'] ?? null);

        // Route to a department via the settings map. C9 — only an ACTIVE station may
        // receive requests: a mapped-but-deactivated/deleted station falls back to the
        // first active station rather than routing to a dead one (never 500, never a
        // deactivated department). No mapping at all stays unrouted (owner guidance = lane B).
        $departmentId = null;
        $map = HotelService::settings($store->id)['departments'] ?? [];
        if (is_array($map) && ! empty($map[$data['type']])) {
            $departmentId = KitchenStation::where('id', $map[$data['type']])
                ->where('store_id', $store->id)
                ->where('active', true)
                ->value('id');
            if (! $departmentId) {
                $departmentId = KitchenStation::where('store_id', $store->id)
                    ->where('active', true)
                    ->orderBy('id')
                    ->value('id');
            }
        }

        $serviceRequest = ServiceRequest::create([
            'store_id' => $store->id,
            'branch_id' => $room?->branch_id,
            'table_id' => $room?->id,
            'stay_id' => optional($room?->activeStay)->id,
            'type' => $data['type'],
            'note' => $data['note'] ?? null,
            'status' => 'open',
            'department_id' => $departmentId ? (int) $departmentId : null,
        ]);

        // C6 — the request used to be fire-and-forget: no staff was alerted. Dispatch the
        // owner's chosen channels (lane B writes the toggles; defaults: notif ON, others OFF).
        $this->dispatchRequestAlerts($store, $serviceRequest, $room, $data['note'] ?? null);

        if ($request->wantsJson()) {
            // Return the created request so the guest layer can show an immediate status chip.
            return response()->json([
                'ok' => true,
                'request' => [
                    'id' => $serviceRequest->id,
                    'type' => $serviceRequest->type,
                    'status' => $serviceRequest->status,
                ],
            ]);
        }

        return back()->with('success', ___('Your request has been sent.'));
    }

    /**
     * C6 — alert dispatch for a new guest service request. Best-effort in every channel:
     * an outage on one must never fail the request (the row is already saved). Channels
     * are the per-store toggles lane B ships; the exact option keys are the cross-lane
     * contract, and the defaults here hold pre-merge (notification centre ON, WhatsApp
     * and web push OFF).
     */
    private function dispatchRequestAlerts(Store $store, ServiceRequest $sr, ?StoreTable $room, ?string $note): void
    {
        $roomLabel = $room ? ($room->label ?: ('Room '.$room->table_no)) : '—';
        $typeLabel = $this->requestTypeLabel($sr->type);

        // 1) Notification centre (default ON). Escape markdown control chars in the
        //    owner-set room label so a bracket/paren in it can't break the notif link.
        if ($this->alertChannelOn($store->id, 'hotel_alerts_notif', true)) {
            $title = ___('Room').' '.$this->mdEscape($roomLabel).': '.$this->mdEscape($typeLabel);
            try {
                CreateNotificationJob::dispatch($title, 'hotel_request', route('hotel.requests', [], false))
                    ->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('hotel request notif dispatch failed', ['request' => $sr->id, 'error' => $e->getMessage()]);
            }
        }

        // 2) WhatsApp to the store's reception number (default OFF).
        if ($this->alertChannelOn($store->id, 'hotel_alerts_whatsapp', false)) {
            $settings = HotelService::settings($store->id);
            $to = whatsapp_normalize_number((string) ($settings['contact']['whatsapp'] ?? ''));
            if ($to !== '') {
                $msg = $roomLabel.' · '.$typeLabel;
                $note = trim((string) $note);
                if ($note !== '') {
                    $msg .= ' — '.Str::limit(OutboundText::line($note, 200), 80);
                }
                try {
                    app(MessageQueue::class)->whatsApp($to, $msg, $store->id, 'hotel_request', afterCommit: true);
                } catch (\Throwable $e) {
                    Log::warning('hotel request whatsapp dispatch failed', ['request' => $sr->id, 'error' => $e->getMessage()]);
                }
            }
        }

        // 3) Web push (default OFF, dark until the platform's VAPID keys are set). This is
        //    the deliberate no-op stub: the toggle exists and this is where a push job
        //    (SendPushNotificationJob) would dispatch once web push is configured.
        if ($this->alertChannelOn($store->id, 'hotel_alerts_push', false)) {
            // no-op: web push requires VAPID setup the platform does not yet ship.
        }
    }

    /** A per-store alert channel toggle, with a pre-merge default while lane B's UI is unbuilt. */
    private function alertChannelOn(int $storeId, string $key, bool $default): bool
    {
        $value = store_options($storeId, $key);
        // An unset option comes back as false (getStoreOption's miss sentinel), null or '' —
        // in every "not configured" case fall back to the default channel state.
        if ($value === null || $value === false || $value === '') {
            return $default;
        }

        return (string) $value === '1';
    }

    /** Escape the markdown link control chars so a bracket in dynamic text can't break rendering. */
    private function mdEscape(string $text): string
    {
        return str_replace(['[', ']', '(', ')'], ['\\[', '\\]', '\\(', '\\)'], $text);
    }

    /** Human, localized label for a request type. */
    private function requestTypeLabel(string $type): string
    {
        return match ($type) {
            'housekeeping' => ___('Housekeeping'),
            'amenity' => ___('Extra towels / amenities'),
            'maintenance' => ___('Maintenance'),
            'staff_call' => ___('Call staff'),
            default => ___('Request'),
        };
    }
}
