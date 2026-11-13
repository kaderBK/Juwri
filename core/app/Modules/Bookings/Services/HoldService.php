<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Models\BookingStaff;

/**
 * P3-10: 10-minute holds (API-11) and the double-booking guard.
 *
 * A hold inserts one booking_slot_cells row per 5-minute cell of its blocked time; UNIQUE(staff_id, cell_at) lets
 * exactly one of two overlapping inserts commit, on SQLite, MySQL and PostgreSQL alike. Expired cells of that staff
 * member are cleared first, in the same transaction (an unpaid booking past its payment window is cancelled then).
 * "Any staff" tries each eligible staff member in order until one insert succeeds. A reschedule hold (API-8) does not
 * re-insert the cells its own booking already owns.
 */
final class HoldService
{
    /** At most this many live holds per holder (session or API token + IP), so nobody can block a calendar. */
    public const MAX_LIVE = 3;

    public function __construct(private SlotEngine $engine) {}

    /**
     * @param  list<int|string>  $serviceIds
     * @param  array{lat?: float|null, lng?: float|null}  $where  the customer's position (at-home visits)
     * @return array{hold: BookingHold, token: string}
     */
    public function hold(Store $store, array $serviceIds, ?int $staffId, CarbonInterface $startsAt, string $location, ?string $holderKey, array $where = [], ?Booking $reschedule = null): array
    {
        BookingSetup::assertOpen($store);
        $settings = BookingSettings::for((int) $store->id);
        $location = $location === 'at_home' ? 'at_home' : 'venue';

        if ($reschedule !== null) {
            if ($reschedule->status !== 'confirmed') {
                throw BookingException::notActive();
            }
            if (! $reschedule->beforeDeadline()) {
                throw BookingException::deadlinePassed();
            }
            $location = $reschedule->location;
            $serviceIds = $reschedule->lines->pluck('service_id')->filter()->values()->all();
            $where = ['lat' => $reschedule->lat !== null ? (float) $reschedule->lat : null, 'lng' => $reschedule->lng !== null ? (float) $reschedule->lng : null];
        }

        $services = $this->engine->services((int) $store->id, $serviceIds, $location);
        if ($location === 'at_home') {
            $this->assertInArea($settings, $where);
        }

        $eligible = $this->engine->eligibleStaff((int) $store->id, $services);
        if ($staffId !== null && $settings->staffChoice) {
            $eligible = $eligible->where('id', $staffId)->values();
            if ($eligible->isEmpty()) {
                throw BookingException::invalid(___('This staff member does not do the chosen services.'));
            }
        }

        if ($holderKey !== null) {
            $live = BookingHold::query()->where('holder_key', $holderKey)->where('status', 'active')
                ->where('expires_at', '>', now('UTC'))->count();
            if ($live >= self::MAX_LIVE) {
                throw new BookingException('too_many_holds', ___('You are holding too many times. Finish or cancel one first.'), 429);
            }
        }

        $plan = $this->engine->plan($services, $location, $settings);
        $start = CarbonImmutable::instance($startsAt)->utc()->setSecond(0);
        foreach ($eligible as $staff) {
            if (! $this->engine->isFreeSlot($settings, $services, $location, $staff, $start, $reschedule?->id)) {
                continue;
            }
            $made = $this->tryInsert($store, $staff, $services, $location, $start, $plan, $holderKey, $reschedule);
            if ($made !== null) {
                return $made;
            }
        }

        throw BookingException::slotTaken();
    }

    /** Release a live hold (the customer went back); its time is free at once. */
    public function release(BookingHold $hold): void
    {
        DB::transaction(function () use ($hold) {
            $updated = BookingHold::query()->whereKey($hold->id)->where('status', 'active')->update(['status' => 'released', 'updated_at' => now('UTC')]);
            if ($updated === 1) {
                DB::table('booking_slot_cells')->where('hold_id', $hold->id)->delete();
            }
        });
    }

    /**
     * Clear what has expired for one staff member: unpaid bookings past the payment window are cancelled, expired
     * holds marked, and their cells deleted. Runs inside the hold transaction and in the sweep.
     */
    public function purgeExpired(?int $staffId = null): int
    {
        $now = now('UTC');
        app(BookingService::class)->expireUnpaid($staffId);
        BookingHold::query()->where('status', 'active')->where('expires_at', '<=', $now)
            ->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId))
            ->update(['status' => 'expired', 'updated_at' => $now]);

        return DB::table('booking_slot_cells')->whereNotNull('expires_at')->where('expires_at', '<=', $now->copy()->utc()->format('Y-m-d H:i:s'))
            ->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId))
            ->whereNull('booking_id')
            ->delete();
    }

    /**
     * @param  Collection<int, BookableService>  $services
     * @param  array{duration: int, before: int, after: int}  $plan
     * @return array{hold: BookingHold, token: string}|null null = the time was taken first
     */
    private function tryInsert(Store $store, BookingStaff $staff, Collection $services, string $location, CarbonImmutable $start, array $plan, ?string $holderKey, ?Booking $reschedule): ?array
    {
        $token = Str::random(40);
        $blockStart = $start->subMinutes($plan['before']);
        $blockEnd = $start->addMinutes($plan['duration'] + $plan['after']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($store, $staff, $services, $location, $start, $plan, $holderKey, $reschedule, $token, $blockStart, $blockEnd) {
                    $this->purgeExpired((int) $staff->id);

                    $hold = BookingHold::query()->create([
                        'store_id' => (int) $store->id,
                        'staff_id' => (int) $staff->id,
                        'booking_id' => $reschedule?->id,
                        'service_ids' => $services->pluck('id')->map(fn ($id) => (int) $id)->all(),
                        'location' => $location,
                        'starts_at' => $start,
                        'ends_at' => $start->addMinutes($plan['duration']),
                        'block_starts_at' => $blockStart,
                        'block_ends_at' => $blockEnd,
                        'expires_at' => now('UTC')->addMinutes(BookingHold::MINUTES),
                        'token_hash' => hash('sha256', $token),
                        'holder_key' => $holderKey,
                        'status' => 'active',
                    ]);

                    $cells = $this->engine->cells($blockStart, $blockEnd);
                    if ($reschedule !== null && (int) $reschedule->staff_id === (int) $staff->id) {
                        $own = DB::table('booking_slot_cells')->where('booking_id', $reschedule->id)->pluck('cell_at')
                            ->map(fn ($at) => CarbonImmutable::parse((string) $at, 'UTC')->getTimestamp())->all();
                        $cells = array_values(array_diff($cells, $own));
                    }
                    $this->insertCells($store, (int) $staff->id, $cells, ['hold_id' => $hold->id, 'expires_at' => $hold->expires_at]);

                    return ['hold' => $hold, 'token' => $token];
                });
            } catch (UniqueConstraintViolationException) {
                return null; // the guard: someone holds or booked an overlapping time
            } catch (QueryException $e) {
                if (! self::isLockConflict($e)) {
                    throw $e;
                }
                // deadlock / lock wait on the same cells: retry, then give the time up
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $cells  epoch seconds
     * @param  array{hold_id?: int|null, booking_id?: int|null, expires_at?: CarbonInterface|null}  $owner
     */
    public function insertCells(Store $store, int $staffId, array $cells, array $owner): void
    {
        $expires = isset($owner['expires_at']) ? CarbonImmutable::instance($owner['expires_at'])->utc()->format('Y-m-d H:i:s') : null;
        $rows = array_map(fn (int $c) => [
            'account_id' => (int) $store->account_id,
            'store_id' => (int) $store->id,
            'staff_id' => $staffId,
            'cell_at' => CarbonImmutable::createFromTimestampUTC($c)->format('Y-m-d H:i:s'),
            'hold_id' => $owner['hold_id'] ?? null,
            'booking_id' => $owner['booking_id'] ?? null,
            'expires_at' => $expires,
        ], $cells);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('booking_slot_cells')->insert($chunk);
        }
    }

    /** @param  array{lat?: float|null, lng?: float|null}  $where */
    public function assertInArea(BookingSettings $settings, array $where): void
    {
        if (! $settings->atHome) {
            throw BookingException::invalid(___('This business does not offer visits at home.'));
        }
        $lat = $where['lat'] ?? null;
        $lng = $where['lng'] ?? null;
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            throw BookingException::invalid(___('Add your location so we can check that you are in the service area.'));
        }
        if ($settings->baseLat !== null && $settings->baseLng !== null
            && Geo::km($settings->baseLat, $settings->baseLng, $lat, $lng) > $settings->atHomeRadiusKm) {
            throw new BookingException('outside_area', ___('This address is outside the service area. Book in the venue or change the address.'), 422);
        }
    }

    /** MySQL 1205/1213, PostgreSQL 40P01/40001/55P03: a lock conflict, not a bug. */
    public static function isLockConflict(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code = (int) ($e->errorInfo[1] ?? 0);

        return in_array($code, [1205, 1213], true) || in_array($state, ['40001', '40P01', '55P03'], true);
    }
}
