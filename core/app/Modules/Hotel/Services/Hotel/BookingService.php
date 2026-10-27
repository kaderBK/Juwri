<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel;

use App\Models\StoreTable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelBookingNight;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomType;

/**
 * HPMS A4 — availability + the booking race guard (SPEC §2).
 *
 * Availability uses the ONE half-open overlap condition
 *   existing.check_in < want_out AND existing.check_out > want_in
 * (whereDate so a stored time-suffix never makes a back-to-back stay look like an
 * overlap). Blocking statuses: pending, confirmed, checked_in. cancelled /
 * no_show / checked_out never block.
 *
 * The durable double-book guard is the hotel_booking_nights UNIQUE(room_id, night)
 * — sqlite lockForUpdate is a no-op, so we insert one night row per (room, night)
 * INSIDE a transaction; the loser hits a QueryException we surface as
 * {@see RoomUnavailableException}. Cancel / no-show / early-checkout / modify free
 * the night rows (DELETE) so the inventory reopens.
 */
class BookingService
{
    /**
     * Available room count per active room type for a date range.
     * Returns a map [room_type_id => available_count]. When $typeId is given, the
     * map holds only that type.
     *
     * @return array<int, int>
     */
    public static function typeAvailability(int $storeId, string $in, string $out, ?int $typeId = null): array
    {
        $typesQuery = RoomType::forRestaurant($storeId);
        if ($typeId) {
            $typesQuery->where('id', $typeId);
        } else {
            $typesQuery->where('active', true);
        }
        $types = $typesQuery->orderBy('sort')->get();

        $map = [];
        foreach ($types as $type) {
            $map[(int) $type->id] = self::availableForType($storeId, (int) $type->id, $in, $out);
        }

        return $map;
    }

    /** Available room count for one type over [in, out). */
    public static function availableForType(int $storeId, int $typeId, string $in, string $out): int
    {
        $rooms = StoreTable::where('store_id', $storeId)
            ->where('area_type', 'room')
            ->where('room_type_id', $typeId)
            ->get();

        $total = $rooms->count();
        if ($total === 0) {
            return 0;
        }

        $inDate = Carbon::parse($in)->toDateString();

        // Out-of-order rooms overlapping the range are subtracted; out_of_service
        // is a soft flag and never reduces availability.
        $ooo = $rooms->filter(function (StoreTable $r) use ($inDate) {
            if ($r->ooStatus() !== 'out_of_order') {
                return false;
            }
            $until = $r->getAttribute('oo_until');

            return ! $until || Carbon::parse($until)->toDateString() >= $inDate;
        })->count();

        $blocking = HotelBooking::where('store_id', $storeId)
            ->where('room_type_id', $typeId)
            ->whereIn('status', HotelBooking::BLOCKING_STATUSES)
            ->whereDate('check_in', '<', $out)
            ->whereDate('check_out', '>', $in)
            ->count();

        return (int) max(0, $total - $ooo - $blocking);
    }

    /**
     * Create a booking. $status 'pending' (request/desk hold) or 'confirmed'
     * (instant/desk confirmed). Pins quoted_total + currency via RateService.
     * A confirmed booking assigns a room (given or auto-picked) and writes its
     * night rows inside a transaction — the UNIQUE catches a concurrent taker.
     *
     * @param  array<string, mixed>  $data  guest_name, guest_phone, room_type_id,
     *                                      rate_plan_id, room_id, check_in, check_out,
     *                                      adults, children, notes, customer_id, branch_id
     */
    public static function create(int $storeId, array $data, string $status = HotelBooking::STATUS_PENDING, string $source = HotelBooking::SOURCE_DESK): HotelBooking
    {
        $in = (string) ($data['check_in'] ?? '');
        $out = (string) ($data['check_out'] ?? '');
        if (! self::validRange($in, $out)) {
            throw new \InvalidArgumentException('Check-out must be after check-in.');
        }

        /** @var RoomType $type */
        $type = RoomType::forRestaurant($storeId)->findOrFail((int) $data['room_type_id']);

        $plan = null;
        if (! empty($data['rate_plan_id'])) {
            $plan = RatePlan::forRestaurant($storeId)->find((int) $data['rate_plan_id']);
        }
        if (! $plan) {
            $plan = RateService::ensureDefaultPlan($storeId);
        }

        $quote = RateService::quote($type, $plan, $in, $out);
        $nights = RateService::nightDates($in, $out);

        $base = [
            'store_id' => $storeId,
            'branch_id' => $data['branch_id'] ?? null,
            'code' => HotelBooking::generateCode(),
            'token' => HotelBooking::generateToken(),
            'guest_name' => trim((string) ($data['guest_name'] ?? '')),
            'guest_phone' => $data['guest_phone'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'room_type_id' => (int) $type->id,
            'rate_plan_id' => (int) $plan->id,
            'check_in' => $in,
            'check_out' => $out,
            'adults' => (int) ($data['adults'] ?? 2),
            'children' => (int) ($data['children'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'quoted_total' => $quote['total'],
            'currency' => $quote['currency'],
            'source' => $source,
        ];

        if ($status === HotelBooking::STATUS_CONFIRMED) {
            try {
                return DB::transaction(function () use ($base, $data, $storeId, $type, $in, $out, $nights) {
                    $roomId = ! empty($data['room_id'])
                        ? (int) $data['room_id']
                        : self::freeRoomForType($storeId, (int) $type->id, $in, $out);
                    if (! $roomId) {
                        throw new RoomUnavailableException('No room of that type is available for those dates.');
                    }
                    $booking = HotelBooking::create($base + ['status' => HotelBooking::STATUS_CONFIRMED, 'room_id' => $roomId]);
                    self::insertNights((int) $booking->id, $roomId, $nights);

                    return $booking;
                });
            } catch (QueryException $e) {
                throw new RoomUnavailableException('That room was just taken for those dates.');
            }
        }

        // Pending: a request hold (no room) or a desk hold with an explicit room.
        $booking = HotelBooking::create($base + ['status' => HotelBooking::STATUS_PENDING, 'room_id' => $data['room_id'] ?? null]);
        if (! empty($booking->room_id)) {
            self::assignRoom($booking, (int) $booking->room_id);
        }

        return $booking->refresh();
    }

    /**
     * Assign (or re-assign) a room to a booking and (re)write its night rows in a
     * transaction. The UNIQUE(room_id, night) rejects a double-book — the tx rolls
     * back and a {@see RoomUnavailableException} is thrown, leaving any prior
     * assignment intact.
     */
    public static function assignRoom(HotelBooking $booking, int $roomId): HotelBooking
    {
        $nights = RateService::nightDates(
            Carbon::parse($booking->check_in)->toDateString(),
            Carbon::parse($booking->check_out)->toDateString()
        );

        try {
            DB::transaction(function () use ($booking, $roomId, $nights) {
                HotelBookingNight::where('booking_id', $booking->id)->delete();
                self::insertNights((int) $booking->id, $roomId, $nights);
                $booking->room_id = $roomId;
                $booking->save();
            });
        } catch (QueryException $e) {
            throw new RoomUnavailableException('That room was just taken for those dates.');
        }

        return $booking->refresh();
    }

    /**
     * Confirm a booking: assign a room (given, already-set, or auto-picked free
     * room of the type), write nights, and flip status to confirmed.
     */
    public static function confirm(HotelBooking $booking, ?int $roomId = null): HotelBooking
    {
        $roomId = $roomId
            ?: ($booking->room_id ? (int) $booking->room_id : self::freeRoomForType(
                (int) $booking->store_id,
                (int) $booking->room_type_id,
                Carbon::parse($booking->check_in)->toDateString(),
                Carbon::parse($booking->check_out)->toDateString()
            ));

        if (! $roomId) {
            throw new RoomUnavailableException('No room of that type is available for those dates.');
        }

        self::assignRoom($booking, (int) $roomId);
        $booking->status = HotelBooking::STATUS_CONFIRMED;
        $booking->save();

        return $booking->refresh();
    }

    /**
     * Modify a booking's dates / room / occupancy. Re-quotes and re-writes the
     * night rows atomically; a clash rolls back and throws
     * {@see RoomUnavailableException}. Terminal bookings cannot be modified.
     *
     * @param  array<string, mixed>  $data
     */
    public static function modify(HotelBooking $booking, array $data): HotelBooking
    {
        if (in_array($booking->status, [HotelBooking::STATUS_CANCELLED, HotelBooking::STATUS_NO_SHOW, HotelBooking::STATUS_CHECKED_OUT], true)) {
            throw new \RuntimeException('A closed booking cannot be modified.');
        }

        $storeId = (int) $booking->store_id;
        $in = (string) ($data['check_in'] ?? Carbon::parse($booking->check_in)->toDateString());
        $out = (string) ($data['check_out'] ?? Carbon::parse($booking->check_out)->toDateString());
        if (! self::validRange($in, $out)) {
            throw new \InvalidArgumentException('Check-out must be after check-in.');
        }

        $typeId = (int) ($data['room_type_id'] ?? $booking->room_type_id);
        /** @var RoomType $type */
        $type = RoomType::forRestaurant($storeId)->findOrFail($typeId);

        $plan = ! empty($data['rate_plan_id'])
            ? RatePlan::forRestaurant($storeId)->find((int) $data['rate_plan_id'])
            : ($booking->rate_plan_id ? RatePlan::forRestaurant($storeId)->find((int) $booking->rate_plan_id) : null);
        if (! $plan) {
            $plan = RateService::ensureDefaultPlan($storeId);
        }

        $quote = RateService::quote($type, $plan, $in, $out);
        $nights = RateService::nightDates($in, $out);
        $roomId = array_key_exists('room_id', $data) ? ($data['room_id'] !== null ? (int) $data['room_id'] : null) : ($booking->room_id ? (int) $booking->room_id : null);

        try {
            DB::transaction(function () use ($booking, $data, $type, $plan, $in, $out, $quote, $nights, $roomId) {
                $booking->room_type_id = (int) $type->id;
                $booking->rate_plan_id = (int) $plan->id;
                $booking->check_in = Carbon::parse($in);
                $booking->check_out = Carbon::parse($out);
                $booking->adults = (int) ($data['adults'] ?? $booking->adults);
                $booking->children = (int) ($data['children'] ?? $booking->children);
                if (array_key_exists('notes', $data)) {
                    $booking->notes = $data['notes'];
                }
                $booking->quoted_total = $quote['total'];
                $booking->currency = $quote['currency'];
                $booking->room_id = $roomId;
                $booking->save();

                HotelBookingNight::where('booking_id', $booking->id)->delete();
                if ($roomId && $booking->isBlocking()) {
                    self::insertNights((int) $booking->id, $roomId, $nights);
                }
            });
        } catch (QueryException $e) {
            throw new RoomUnavailableException('That room was just taken for those dates.');
        }

        return $booking->refresh();
    }

    /** Cancel a booking (any pre-checkout state) and free its night rows. */
    public static function cancel(HotelBooking $booking): HotelBooking
    {
        if ($booking->status === HotelBooking::STATUS_CHECKED_OUT) {
            throw new \RuntimeException('A checked-out booking cannot be cancelled.');
        }
        DB::transaction(function () use ($booking) {
            HotelBookingNight::where('booking_id', $booking->id)->delete();
            $booking->status = HotelBooking::STATUS_CANCELLED;
            $booking->save();
        });

        return $booking->refresh();
    }

    /**
     * HPMS A6 — check a booking's guest in: assign the room (race guard), open the
     * stay + folio via HotelFolioService, post the per-night room charges, and link
     * the booking (status checked_in, hotel_stay_id). Idempotent: a booking already
     * checked in returns its existing stay without re-posting.
     */
    public static function checkIn(HotelBooking $booking, ?int $roomId = null): HotelStay
    {
        $booking->refresh();

        // Idempotent short-circuit — already checked in.
        if ($booking->status === HotelBooking::STATUS_CHECKED_IN && $booking->hotel_stay_id) {
            $stay = HotelStay::find($booking->hotel_stay_id);
            if ($stay) {
                return $stay;
            }
        }

        if (in_array($booking->status, [HotelBooking::STATUS_CANCELLED, HotelBooking::STATUS_NO_SHOW, HotelBooking::STATUS_CHECKED_OUT], true)) {
            throw new \RuntimeException('This booking cannot be checked in.');
        }

        $storeId = (int) $booking->store_id;
        $in = Carbon::parse($booking->check_in)->toDateString();
        $out = Carbon::parse($booking->check_out)->toDateString();

        $roomId = $roomId
            ?: ($booking->room_id ? (int) $booking->room_id : self::freeRoomForType($storeId, (int) $booking->room_type_id, $in, $out));
        if (! $roomId) {
            throw new RoomUnavailableException('No room of that type is available for those dates.');
        }

        /** @var StoreTable|null $room */
        $room = StoreTable::where('id', $roomId)->where('store_id', $storeId)->first();
        if (! $room) {
            throw new RoomUnavailableException('Assigned room not found.');
        }

        // Hold the room nights (idempotent re-write of this booking's own rows).
        self::assignRoom($booking, $roomId);

        return DB::transaction(function () use ($booking, $room, $storeId, $in, $out) {
            $stay = HotelFolioService::checkIn(
                $storeId,
                $room,
                (string) $booking->guest_name,
                $booking->guest_phone,
                $booking->branch_id ? (int) $booking->branch_id : null
            );

            // Post the nightly room charges (the night audit, posted at check-in).
            $type = RoomType::forRestaurant($storeId)->find((int) $booking->room_type_id);
            $plan = $booking->rate_plan_id ? RatePlan::forRestaurant($storeId)->find((int) $booking->rate_plan_id) : null;
            if ($type) {
                $quote = RateService::quote($type, $plan, $in, $out);
                $label = ($room->label !== null && trim((string) $room->label) !== '') ? (string) $room->label : (string) $room->table_no;
                HotelFolioService::postNightlyCharges($stay, $quote['nights'], $label);
            }

            $booking->hotel_stay_id = (int) $stay->id;
            $booking->room_id = (int) $room->id;
            $booking->status = HotelBooking::STATUS_CHECKED_IN;
            $booking->save();

            return $stay->refresh();
        });
    }

    /**
     * HPMS A6 — check a booking's guest out. Reverses any unstayed nights (early
     * departure) and closes the folio via HotelFolioService::checkOut, which also
     * syncs the booking to checked_out. Requires a zero folio balance unless
     * $writeOff. Returns the closed stay.
     */
    public static function checkOut(HotelBooking $booking, ?string $actualCheckoutDate = null, bool $writeOff = false, ?int $grantedBy = null): HotelStay
    {
        $booking->refresh();
        if (! $booking->hotel_stay_id) {
            throw new \RuntimeException('This booking has not been checked in.');
        }
        $stay = HotelStay::find($booking->hotel_stay_id);
        if (! $stay) {
            throw new \RuntimeException('Stay not found for this booking.');
        }

        return HotelFolioService::checkOut($stay, $writeOff, $grantedBy, $actualCheckoutDate);
    }

    /**
     * HPMS A6 — extend a checked-in (or confirmed) booking to a later check-out
     * date: re-hold the nights (race guard) and, when checked in, post the new
     * nightly charges to the folio (idempotent — only the added nights post).
     */
    public static function extend(HotelBooking $booking, string $newCheckOut): HotelBooking
    {
        $booking->refresh();
        $in = Carbon::parse($booking->check_in)->toDateString();
        $newOut = Carbon::parse($newCheckOut)->toDateString();
        if (! self::validRange($in, $newOut) || $newOut <= Carbon::parse($booking->check_out)->toDateString()) {
            throw new \InvalidArgumentException('The new check-out must be after the current check-out.');
        }
        if (! $booking->room_id) {
            throw new \RuntimeException('Assign a room before extending.');
        }

        $storeId = (int) $booking->store_id;
        $roomId = (int) $booking->room_id;
        $nights = RateService::nightDates($in, $newOut);

        try {
            DB::transaction(function () use ($booking, $roomId, $nights, $newOut, $storeId, $in) {
                // Re-write the full night span (existing rows re-inserted, new ones added).
                HotelBookingNight::where('booking_id', $booking->id)->delete();
                self::insertNights((int) $booking->id, $roomId, $nights);

                $type = RoomType::forRestaurant($storeId)->find((int) $booking->room_type_id);
                $plan = $booking->rate_plan_id ? RatePlan::forRestaurant($storeId)->find((int) $booking->rate_plan_id) : null;
                $booking->check_out = Carbon::parse($newOut);
                if ($type) {
                    $quote = RateService::quote($type, $plan, $in, $newOut);
                    $booking->quoted_total = $quote['total'];
                    $booking->currency = $quote['currency'];
                }
                $booking->save();
            });
        } catch (QueryException $e) {
            throw new RoomUnavailableException('The room is not free for the extended nights.');
        }

        // Post the (new) nightly charges to the folio when already checked in.
        if ($booking->status === HotelBooking::STATUS_CHECKED_IN && $booking->hotel_stay_id) {
            $stay = HotelStay::find($booking->hotel_stay_id);
            $type = RoomType::forRestaurant($storeId)->find((int) $booking->room_type_id);
            $plan = $booking->rate_plan_id ? RatePlan::forRestaurant($storeId)->find((int) $booking->rate_plan_id) : null;
            if ($stay && $type) {
                $room = StoreTable::find($roomId);
                $label = $room ? (($room->label !== null && trim((string) $room->label) !== '') ? (string) $room->label : (string) $room->table_no) : null;
                $quote = RateService::quote($type, $plan, $in, $newOut);
                HotelFolioService::postNightlyCharges($stay, $quote['nights'], $label);
            }
        }

        return $booking->refresh();
    }

    /** Mark a booking a no-show (confirmed/pending, past arrival) and free nights. */
    public static function markNoShow(HotelBooking $booking): HotelBooking
    {
        if (! in_array($booking->status, [HotelBooking::STATUS_PENDING, HotelBooking::STATUS_CONFIRMED], true)) {
            throw new \RuntimeException('Only a pending or confirmed booking can be marked a no-show.');
        }
        DB::transaction(function () use ($booking) {
            HotelBookingNight::where('booking_id', $booking->id)->delete();
            $booking->status = HotelBooking::STATUS_NO_SHOW;
            $booking->save();
        });

        return $booking->refresh();
    }

    /**
     * The first room of the type free for the whole range (no overlapping night
     * rows, not out-of-order), or null.
     */
    public static function freeRoomForType(int $storeId, int $typeId, string $in, string $out): ?int
    {
        $inDate = Carbon::parse($in)->toDateString();

        $rooms = StoreTable::where('store_id', $storeId)
            ->where('area_type', 'room')
            ->where('room_type_id', $typeId)
            ->orderBy('id')
            ->get();

        foreach ($rooms as $room) {
            if ($room->ooStatus() === 'out_of_order') {
                $until = $room->getAttribute('oo_until');
                if (! $until || Carbon::parse($until)->toDateString() >= $inDate) {
                    continue; // OOO in range
                }
            }
            $taken = HotelBookingNight::where('room_id', $room->id)
                ->whereDate('night', '>=', $in)
                ->whereDate('night', '<', $out)
                ->exists();
            if (! $taken) {
                return (int) $room->id;
            }
        }

        return null;
    }

    /** @param  array<int, string>  $nights */
    private static function insertNights(int $bookingId, int $roomId, array $nights): void
    {
        foreach ($nights as $night) {
            HotelBookingNight::create([
                'booking_id' => $bookingId,
                'room_id' => $roomId,
                'night' => $night,
            ]);
        }
    }

    private static function validRange(string $in, string $out): bool
    {
        try {
            return Carbon::parse($out)->startOfDay()->gt(Carbon::parse($in)->startOfDay());
        } catch (\Throwable $e) {
            return false;
        }
    }
}
