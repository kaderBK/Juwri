<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Http\Controllers\Rwg;

use App\Models\Reservation;
use App\Services\ReservationDepositService;
use App\Services\WebhookFanout;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Google\GoogleMerchantId;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Reservations\Jobs\PushRwgBookingUpdateJob;
use Juwri\Modules\Reservations\Services\ReservationBookingService;
use Juwri\Modules\Reservations\Services\Rwg\ReservationAvailabilityService;

/**
 * GOOGLE-PARTNER Lane C — Reserve with Google reservations booking server (Google → us).
 *
 *   POST /rwg/v3/BatchAvailabilityLookup  — real-time slot re-check
 *   POST /rwg/v3/CreateBooking            — lease-honoring, idempotent on rwg_booking_id
 *   POST /rwg/v3/UpdateBooking            — modify / cancel
 *   POST /rwg/v3/GetBookingStatus         — status poll
 *   POST /rwg/v3/ListBookings             — a diner's bookings
 *
 * Auth + CSRF-exemption + rate-limit are on the route group (C2). Business-logic
 * failures return HTTP 200 with a `booking_failure` body (E2E contract), NOT a
 * non-2xx. Availability and capacity come from the ONE shared
 * ReservationAvailabilityService — the same truth the storefront + feeds use.
 *
 * Idempotency key = Google's CreateBooking idempotency_token, persisted in the NEW
 * `rwg_booking_id` column (own unique index) and echoed back as booking_id. NEVER
 * `reservations.token` (the deposit-pay bearer secret).
 */
class BookingServerController extends RwgController
{
    /**
     * Per-request memo: store id => is the store a multi-branch publisher (2+ eligible
     * branches)? Read by bookingArray() when a list has several bookings for one store.
     *
     * @var array<int,bool>
     */
    private array $multiBranchCache = [];

    /** @var array<int,Branch|null> */
    private array $branchCache = [];

    public function __construct(private ReservationAvailabilityService $availability) {}

    /** Real-time availability for a batch of requested slot times. */
    public function batchAvailabilityLookup(Request $request): JsonResponse
    {
        $p = $this->payload($request);
        $merchant = $this->resolveMerchant((string) ($p['merchant_id'] ?? ''));

        $out = [];
        foreach ((array) ($p['slot_time'] ?? []) as $slotTime) {
            $out[] = [
                'slot_time' => $slotTime,
                'available' => $this->slotAvailable($merchant, (array) $slotTime),
            ];
        }

        // No matches ⇒ empty response, never a 400.
        return response()->json(['slot_time_availability' => $out], 200);
    }

    /** Create a booking. Idempotent on idempotency_token; capacity re-checked under lock. */
    public function createBooking(Request $request): JsonResponse
    {
        $p = $this->payload($request);
        $token = (string) ($p['idempotency_token'] ?? '');
        if ($token === '') {
            return $this->badRequest('missing_idempotency_token');
        }
        // rwg_booking_id is VARCHAR(64). An over-length token would raise a
        // data-too-long QueryException on insert (a 500 — not the caught unique
        // violation) or silently truncate under non-strict MySQL and collide on the
        // 64-char prefix. Reject out-of-spec tokens cleanly instead.
        if (mb_strlen($token) > 64) {
            return $this->badRequest('idempotency_token_too_long');
        }

        $slot = (array) ($p['slot'] ?? []);
        $user = (array) ($p['user_information'] ?? []);
        $merchant = $this->resolveMerchant((string) ($slot['merchant_id'] ?? ''));
        if (! $merchant) {
            return $this->bookingFailure('SLOT_UNAVAILABLE');
        }
        $storeId = $merchant['store'];
        $branch = $merchant['branch'];

        // G1: idempotent replay returns the existing booking ONLY when it belongs to
        // the store this request names. A token replayed against another store is not
        // a replay — never leak that store's booking.
        $existing = Reservation::where('rwg_booking_id', $token)->first();
        if ($existing) {
            return (int) $existing->store_id === $storeId
                ? $this->bookingResponse($existing)
                : $this->bookingFailure('SLOT_UNAVAILABLE');
        }

        $startSec = (int) ($slot['start_sec'] ?? 0);
        if ($startSec <= 0) {
            return $this->bookingFailure('SLOT_UNAVAILABLE');
        }
        $at = Carbon::createFromTimestamp($startSec, $this->availability->timezone($storeId, $branch));
        $party = $this->partySize($slot);

        // G3: a booking must carry a way to reach the guest (telephone or email).
        if (trim((string) ($user['telephone'] ?? '')) === '' && trim((string) ($user['email'] ?? '')) === '') {
            return $this->bookingFailure('USER_INFORMATION_INVALID');
        }

        // G2: refuse the past, beyond-horizon, over-max-party, closed / out-of-window.
        if (! $this->availability->slotIsBookable($storeId, $at, $party, $branch)) {
            return $this->bookingFailure('SLOT_UNAVAILABLE');
        }

        // Deposit-required stores: REJECT (owner-decision, C0 — deposits can't be
        // collected through the Google flow without a payments onboarding this pack
        // does not do). Availability feed advertises no prepayment, so this bites
        // only the rare deposit-required store.
        if (ReservationDepositService::required($storeId, $party)) {
            return $this->bookingFailure('PAYMENT_ERROR');
        }

        if (ReservationBookingService::blackoutHit($storeId, $at->format('Y-m-d H:i'))) {
            return $this->bookingFailure('SLOT_UNAVAILABLE');
        }

        // Lease honor: re-check covers capacity under the per-slot lock, held across INSERT.
        try {
            $lock = $this->availability->lockSlot($storeId, $branch, $at, $party);
        } catch (\InvalidArgumentException $e) {
            return $this->bookingFailure('SLOT_UNAVAILABLE');
        }

        try {
            $reservation = Reservation::create([
                'store_id' => $storeId,
                'branch_id' => $branch?->id,
                'name' => $this->fullName($user),
                'phone' => (string) ($user['telephone'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'party_size' => $party,
                'reserved_at' => $at->format('Y-m-d H:i:s'),
                'status' => 'confirmed',
                'token' => Str::random(40), // deposit-pay column contract; unused for Google
                'rwg_booking_id' => $token,
                'source' => 'google',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent identical create won the race — return that booking.
            optional($lock)->release();
            $winner = Reservation::where('rwg_booking_id', $token)->first();

            return $winner && (int) $winner->store_id === $storeId
                ? $this->bookingResponse($winner)
                : $this->bookingFailure('SLOT_UNAVAILABLE');
        } finally {
            optional($lock)->release();
        }

        WebhookFanout::forRestaurant($storeId, 'reservation.created', [
            'reservation_id' => $reservation->id, 'status' => $reservation->status, 'source' => 'google',
        ]);

        return $this->bookingResponse($reservation);
    }

    /** Modify (reschedule) or cancel an existing booking. */
    public function updateBooking(Request $request): JsonResponse
    {
        $p = $this->payload($request);
        $booking = (array) ($p['booking'] ?? $p);
        $bookingId = (string) ($booking['booking_id'] ?? '');

        $reservation = Reservation::where('rwg_booking_id', $bookingId)->first();
        if (! $reservation) {
            return response()->json(['error' => 'not_found'], 404);
        }
        // G1: only a store that still has Google reservations switched on answers.
        if (! store_options((int) $reservation->store_id, 'rwg_reservations_enabled')) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $newStatus = (string) ($booking['status'] ?? '');
        if ($newStatus === 'CANCELED' || $newStatus === 'CANCELLED') {
            return $this->cancelBooking($reservation);
        }

        // Reschedule: a new slot time / party size.
        $slot = (array) ($booking['slot'] ?? []);
        $startSec = (int) ($slot['start_sec'] ?? 0);
        if ($startSec > 0) {
            $storeId = (int) $reservation->store_id;
            $branch = $reservation->branch_id !== null ? $this->branch((int) $reservation->branch_id) : null;

            // G5: a finished booking can never be revived by a move.
            if (in_array((string) $reservation->status, ['cancelled', 'seated', 'no_show'], true)) {
                return $this->bookingFailure('SLOT_UNAVAILABLE');
            }

            $at = Carbon::createFromTimestamp($startSec, $this->availability->timezone($storeId, $branch));
            $party = $this->partySize($slot, (int) $reservation->party_size);

            // G2: the same past / horizon / max-party / window / blackout gate as create.
            if (! $this->availability->slotIsBookable($storeId, $at, $party, $branch)) {
                return $this->bookingFailure('SLOT_UNAVAILABLE');
            }

            $timeChanged = false;
            try {
                // G5: exclude this booking's own covers from the capacity re-check.
                $lock = $this->availability->lockSlot($storeId, $branch, $at, $party, (int) $reservation->id);
            } catch (\InvalidArgumentException $e) {
                return $this->bookingFailure('SLOT_UNAVAILABLE');
            }
            try {
                $reservation->update([
                    'reserved_at' => $at->format('Y-m-d H:i:s'),
                    'party_size' => $party,
                ]);
                $timeChanged = $reservation->wasChanged('reserved_at');
            } finally {
                optional($lock)->release();
            }

            // G5: a status change pushes from the model observer; a TIME change does not,
            // so push it here (best-effort — a push may never break the write).
            if ($timeChanged) {
                try {
                    PushRwgBookingUpdateJob::dispatch((int) $reservation->id);
                } catch (\Throwable $e) {
                    // best-effort only
                }
            }
        }

        return $this->bookingResponse($reservation->fresh());
    }

    /**
     * G4: cancel with the store's cancel window and terminal-state guard.
     * Already cancelled ⇒ the booking is returned unchanged (no second update,
     * webhook or refund). Seated / no-show, or inside the window, are refused.
     */
    private function cancelBooking(Reservation $reservation): JsonResponse
    {
        $status = (string) $reservation->status;

        if ($status === 'cancelled') {
            return $this->bookingResponse($reservation);
        }
        if (in_array($status, ['seated', 'no_show'], true)) {
            return $this->bookingFailure('BOOKING_CANCELLATION_NOT_ALLOWED');
        }

        $storeId = (int) $reservation->store_id;
        $tz = store_tz($storeId);
        $window = max(0, (int) store_options($storeId, 'reservation_cancel_window_minutes', 0));
        $reservedAt = Carbon::parse((string) $reservation->reserved_at, $tz);
        // Window 0 ⇒ cancellable up to the start time; a positive window blocks the
        // last N minutes before it. At/after the reservation itself is always refused.
        if (Carbon::now($tz)->gte($reservedAt->copy()->subMinutes($window))) {
            return $this->bookingFailure('BOOKING_CANCELLATION_NOT_ALLOWED');
        }

        // Two cancels at once: only the one that flips the status (under a row lock) sends the webhook / refund.
        $flipped = DB::transaction(function () use (&$reservation): bool {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $locked || (string) $locked->status === 'cancelled') {
                return false;
            }
            $locked->update(['status' => 'cancelled']);
            $reservation = $locked;

            return true;
        });
        if (! $flipped) {
            return $this->bookingResponse($reservation->fresh());
        }
        WebhookFanout::forRestaurant($storeId, 'reservation.cancelled', [
            'reservation_id' => $reservation->id, 'status' => 'cancelled', 'source' => 'google',
        ]);
        if ($reservation->deposit_status === 'paid') {
            ReservationDepositService::refund($reservation->fresh());
        }

        return $this->bookingResponse($reservation->fresh());
    }

    /** Poll a booking's status. */
    public function getBookingStatus(Request $request): JsonResponse
    {
        $p = $this->payload($request);
        $bookingId = (string) ($p['booking_id'] ?? '');
        $reservation = Reservation::where('rwg_booking_id', $bookingId)->first();
        // G1: a store that switched Google reservations off is treated as not found.
        if (! $reservation || ! store_options((int) $reservation->store_id, 'rwg_reservations_enabled')) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'booking_id' => $bookingId,
            'booking_status' => $this->googleStatus((string) $reservation->status),
            'prepayment_status' => 'PREPAYMENT_NOT_PROVIDED', // G6: we never take prepayment
        ], 200);
    }

    /** List a diner's Google-sourced bookings (matched by user_id / phone / email). */
    public function listBookings(Request $request): JsonResponse
    {
        $p = $this->payload($request);
        $user = (array) ($p['user_information'] ?? []);
        // G7: v3 sends user_id; there is no dedicated column, so it joins the contact
        // fallbacks and matches a stored phone / email.
        $identifiers = array_values(array_filter([
            (string) ($user['user_id'] ?? $p['user_id'] ?? ''),
            (string) ($user['telephone'] ?? ''),
            (string) ($user['email'] ?? ''),
        ], fn (string $v) => $v !== ''));

        // G7: never list a store that switched Google reservations off.
        $enabledStoreIds = StoreSettings::storeIdsWhere(
            'rwg_reservations_enabled',
            fn (string $v) => (bool) filter_var($v, FILTER_VALIDATE_BOOLEAN)
        );

        if ($identifiers === [] || $enabledStoreIds === []) {
            return response()->json(['bookings' => []], 200);
        }

        $bookings = Reservation::query()
            ->whereNotNull('rwg_booking_id')
            ->where('source', 'google')
            ->whereIn('store_id', $enabledStoreIds)
            ->where(function ($w) use ($identifiers) {
                foreach ($identifiers as $identifier) {
                    $w->orWhere('phone', $identifier)->orWhere('email', $identifier);
                }
            })
            ->orderByDesc('reserved_at')
            ->limit(50)
            ->get()
            ->map(fn (Reservation $r) => $this->bookingArray($r))
            ->all();

        return response()->json(['bookings' => $bookings], 200);
    }

    // --- helpers ---------------------------------------------------------

    /**
     * Resolve + verify a merchant id (`store-N` / `store-N-bB`) to its store + branch.
     *
     * Owner decision 2026-10-12 ("Google merchant = branch"): the feed publishes `store-N`
     * for a one-eligible-branch store and `store-N-bB` per branch when a store has two or
     * more eligible branches. The booking server must accept exactly what the feed
     * published and refuse the rest (unknown merchant ⇒ the same soft failure as an
     * unknown store today):
     *   - a bad/unknown/disabled store,
     *   - `-bB` naming a branch that is not an eligible branch OF THIS STORE
     *     (cross-store, inactive or without coordinates),
     *   - a bare `store-N` for a multi-branch store (must name a branch),
     *   - `-bB` for a one-branch (or branch-less) store (the feed keeps `store-N`).
     * A branch-less store keeps the historic bare `store-N` (single-branch stores unchanged).
     *
     * @return array{store:int, branch:Branch|null}|null
     */
    private function resolveMerchant(string $merchantId): ?array
    {
        $parsed = GoogleMerchantId::parse($merchantId);
        if ($parsed === null) {
            return null;
        }
        $storeId = $parsed['store'];
        if (! Store::where('id', $storeId)->exists()) {
            return null;
        }
        if (! store_options($storeId, 'rwg_reservations_enabled')) {
            return null;
        }

        $eligible = $this->eligibleBranches($storeId); // keyed by branch id, id order
        $branchId = $parsed['branch'];

        if ($branchId === null) {
            // A multi-branch store publishes one merchant PER BRANCH; the bare id is not one.
            if (count($eligible) >= 2) {
                return null;
            }

            // Single eligible branch (or none) → the feed's `store-N`; carry the branch.
            return ['store' => $storeId, 'branch' => $eligible === [] ? null : reset($eligible)];
        }

        // Per-branch merchant: the branch must be eligible AND the store must be multi-branch
        // (a one-branch store publishes the bare `store-N`, never `store-N-bB`).
        $branch = $eligible[$branchId] ?? null;
        if ($branch === null || count($eligible) < 2) {
            return null;
        }

        return ['store' => $storeId, 'branch' => $branch];
    }

    /**
     * The store's eligible branches (active + both coordinates), keyed by id, id order —
     * exactly the set the feed publishes from (store-scoped).
     *
     * @return array<int,Branch>
     */
    private function eligibleBranches(int $storeId): array
    {
        return Branch::query()
            ->where('store_id', $storeId)
            ->where('active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** Does the store publish one merchant PER BRANCH (2+ eligible branches)? */
    private function isMultiBranch(int $storeId): bool
    {
        if (! array_key_exists($storeId, $this->multiBranchCache)) {
            $this->multiBranchCache[$storeId] = count($this->eligibleBranches($storeId)) >= 2;
        }

        return $this->multiBranchCache[$storeId];
    }

    /** Cached branch lookup for a reservation's branch_id. */
    private function branch(int $branchId): ?Branch
    {
        if (! array_key_exists($branchId, $this->branchCache)) {
            $this->branchCache[$branchId] = Branch::find($branchId);
        }

        return $this->branchCache[$branchId];
    }

    /**
     * @param  array{store:int, branch:Branch|null}|null  $merchant
     * @param  array<string,mixed>  $slotTime
     */
    private function slotAvailable(?array $merchant, array $slotTime): bool
    {
        if ($merchant === null) {
            return false;
        }
        $storeId = $merchant['store'];
        $branch = $merchant['branch'];
        $startSec = (int) ($slotTime['start_sec'] ?? 0);
        if ($startSec <= 0) {
            return false;
        }
        $at = Carbon::createFromTimestamp($startSec, $this->availability->timezone($storeId, $branch));
        if (ReservationBookingService::blackoutHit($storeId, $at->format('Y-m-d H:i'))) {
            return false;
        }

        $party = $this->partySize($slotTime);

        // Same gate as CreateBooking: never advertise a slot the create would refuse.
        return $this->availability->slotIsBookable($storeId, $at, $party, $branch)
            && $this->availability->hasCapacity($storeId, $branch, $at, $party);
    }

    /** @param array<string,mixed> $slot */
    private function partySize(array $slot, int $default = 2): int
    {
        $resources = (array) ($slot['resources'] ?? []);
        $party = (int) ($resources['party_size'] ?? $slot['party_size'] ?? $default);

        return max(1, $party);
    }

    /** @param array<string,mixed> $user */
    private function fullName(array $user): string
    {
        $name = trim((string) ($user['given_name'] ?? '').' '.(string) ($user['family_name'] ?? ''));

        return $name !== '' ? Str::limit($name, 80, '') : 'Google guest';
    }

    /** reservations.status → Google BookingStatus. */
    private function googleStatus(string $status): string
    {
        return match ($status) {
            'confirmed', 'seated' => 'CONFIRMED',
            'cancelled' => 'CANCELED',
            'no_show' => 'NO_SHOW',
            default => 'PENDING_MERCHANT_CONFIRMATION', // pending
        };
    }

    /** @return array<string,mixed> */
    private function bookingArray(Reservation $r): array
    {
        $storeId = (int) $r->store_id;
        // A booking made against a per-branch merchant echoes that branch's merchant id
        // (`store-N-bB` / `dining-N-bB`); a branch-less (or one-branch store) booking keeps
        // the historic `store-N` / `dining-N`, byte-identical to before.
        $branchId = $r->branch_id !== null ? (int) $r->branch_id : 0;
        $single = $branchId === 0 || ! $this->isMultiBranch($storeId);
        $merchantId = GoogleMerchantId::format($storeId, $branchId, $single);
        $serviceId = str_replace('store-', 'dining-', $merchantId);

        $branch = $branchId > 0 ? $this->branch($branchId) : null;
        $startSec = Carbon::parse((string) $r->reserved_at, $this->availability->timezone($storeId, $branch))->getTimestamp();

        return [
            'booking_id' => (string) $r->rwg_booking_id,
            'slot' => [
                'merchant_id' => $merchantId,
                'service_id' => $serviceId,
                'start_sec' => $startSec,
                'duration_sec' => $this->availability->slotInterval($storeId) * 60,
            ],
            'status' => $this->googleStatus((string) $r->status),
        ];
    }

    private function bookingResponse(Reservation $r): JsonResponse
    {
        return response()->json(['booking' => $this->bookingArray($r)], 200);
    }

    private function bookingFailure(string $cause): JsonResponse
    {
        // E2E contract: business-logic failure is HTTP 200 with a booking_failure body.
        return response()->json(['booking_failure' => ['cause' => $cause]], 200);
    }
}
