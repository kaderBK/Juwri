<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\BookingClosedDay;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingStaff;

/**
 * P3-10: availability maths — which start times one staff member has on one store-local day for a list of services.
 *
 *  - Working windows: the staff member's own weekly rows when they have any, else the store's; `break` rows are cut
 *    out; a store or staff closed day has none. Local "HH:MM" times are turned into instants in the store's time zone
 *    for THAT date, and slots step through real (UTC) time, so a DST change day has exactly the hours the clock shows
 *    (spring forward: the missing hour has no slots; fall back: the repeated hour is counted once per instant).
 *  - A slot at T needs [T, T + Σ durations] inside one window (services run back to back; the customer sees only the
 *    service time) and the blocked time [T − before, T + duration + after] free of other holds and bookings, where
 *    before/after are the first service's buffer_before and the last one's buffer_after, plus the store's travel
 *    buffer on both sides for an at-home visit. Buffers may run past opening hours (setup before opening, cleaning
 *    after closing) but never over another booking.
 *  - Slots start at the window start and every `slotInterval` minutes after it; nothing earlier than now + lead time,
 *    nothing after the booking horizon (max days).
 *  - Blocked time is stored as 5-minute cells (booking_slot_cells); a cell whose `expires_at` has passed is free.
 */
final class SlotEngine
{
    /** Cell size in minutes: every duration, buffer and hours value is a multiple of it. */
    public const CELL = 5;

    /**
     * The active services of the store with these ids, in the order given; refuses unknown, inactive, duplicate ids
     * and services that cannot be done at this location.
     *
     * @param  list<int|string>  $ids  service ids or public ids
     * @return Collection<int, BookableService>
     */
    public function services(int $storeId, array $ids, string $location): Collection
    {
        $ids = array_values(array_unique(array_map('strval', array_filter($ids, fn ($v) => $v !== ''))));
        if ($ids === [] || count($ids) > 4) {
            throw BookingException::invalid(___('Choose between 1 and 4 services.'));
        }
        // PostgreSQL types public_id as uuid: compare it only with uuid-shaped values.
        $numeric = array_values(array_filter($ids, 'ctype_digit'));
        $uuids = array_values(array_filter($ids, fn (string $v) => Str::isUuid($v)));
        $rows = BookableService::query()->where('store_id', $storeId)->where('active', true)
            ->where(fn ($q) => $q->whereIn('id', $numeric)->orWhereIn('public_id', $uuids))
            ->get();
        $ordered = collect($ids)->map(fn (string $id) => $rows->first(fn (BookableService $s) => (string) $s->id === $id || $s->public_id === $id))->filter()->unique('id')->values();
        if ($ordered->count() !== count($ids)) {
            throw BookingException::invalid(___('One of the chosen services is not available.'));
        }
        foreach ($ordered as $service) {
            $ok = $location === 'at_home' ? $service->at_home : $service->venue;
            if (! $ok) {
                throw BookingException::invalid($location === 'at_home'
                    ? ___(':service cannot be booked at home.', ['service' => $service->name])
                    : ___(':service is only booked at home.', ['service' => $service->name]));
            }
        }

        return $ordered;
    }

    /**
     * @param  Collection<int, BookableService>  $services
     * @return array{duration: int, before: int, after: int}
     */
    public function plan(Collection $services, string $location, BookingSettings $settings): array
    {
        $travel = $location === 'at_home' ? $settings->travelBufferMin : 0;

        return [
            'duration' => (int) $services->sum('duration_min'),
            'before' => (int) ($services->first()->buffer_before_min ?? 0) + $travel,
            'after' => (int) ($services->last()->buffer_after_min ?? 0) + $travel,
        ];
    }

    /**
     * Active staff who do every one of these services, by position.
     *
     * @param  Collection<int, BookableService>  $services
     * @return Collection<int, BookingStaff>
     */
    public function eligibleStaff(int $storeId, Collection $services): Collection
    {
        $ids = $services->pluck('id')->all();

        return BookingStaff::query()->where('store_id', $storeId)->where('active', true)
            ->whereIn('id', function ($q) use ($ids) {
                $q->select('staff_id')->from('booking_staff_services')->whereIn('service_id', $ids)
                    ->groupBy('staff_id')->havingRaw('COUNT(DISTINCT service_id) = ?', [count($ids)]);
            })
            ->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Working windows of one staff member on a store-local date, as UTC instants.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function windows(BookingSettings $settings, BookingStaff $staff, string $date): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $settings->timezone);
        if ($day === null) {
            return [];
        }
        $closed = BookingClosedDay::query()->where('store_id', $settings->storeId)
            ->where(fn ($q) => $q->whereNull('staff_id')->orWhere('staff_id', $staff->id))
            ->whereDate('date', $date)->exists();
        if ($closed) {
            return [];
        }

        $own = BookingHour::query()->where('store_id', $settings->storeId)->where('staff_id', $staff->id)->exists();
        $rows = BookingHour::query()->where('store_id', $settings->storeId)
            ->where('weekday', $day->dayOfWeek)
            ->when($own, fn ($q) => $q->where('staff_id', $staff->id), fn ($q) => $q->whereNull('staff_id'))
            ->get();

        $open = [];
        $breaks = [];
        foreach ($rows as $row) {
            $start = $this->instant($date, $row->starts_local, $settings->timezone);
            $end = $this->instant($date, $row->ends_local, $settings->timezone);
            if ($start === null || $end === null || $end->lte($start)) {
                continue;
            }
            if ($row->kind === 'break') {
                $breaks[] = [$start, $end];
            } else {
                $open[] = [$start, $end];
            }
        }

        return $this->subtract($this->merge($open), $this->merge($breaks));
    }

    /** "HH:MM" on a local date → the UTC instant ("24:00" = the next midnight). */
    public function instant(string $date, string $hhmm, string $timezone): ?CarbonImmutable
    {
        if (! preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $hhmm, $m) || ($m[1] === '24' && $m[2] !== '00')) {
            return null;
        }
        $base = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($base === null) {
            return null;
        }
        if ($m[1] === '24') {
            return $base->addDay()->utc();
        }

        return CarbonImmutable::create($base->year, $base->month, $base->day, (int) $m[1], (int) $m[2], 0, $timezone)->utc();
    }

    /**
     * Start times on a local date for these services with this staff member.
     *
     * @param  Collection<int, BookableService>  $services
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, free: bool}>
     */
    public function slots(BookingSettings $settings, Collection $services, string $location, BookingStaff $staff, string $date, ?int $ownBookingId = null): array
    {
        if (! $this->inHorizon($settings, $date)) {
            return [];
        }
        $plan = $this->plan($services, $location, $settings);
        $windows = $this->windows($settings, $staff, $date);
        if ($windows === [] || $plan['duration'] <= 0) {
            return [];
        }

        $from = $windows[0][0]->subMinutes($plan['before']);
        $to = end($windows)[1]->addMinutes($plan['after'] + $plan['duration']);
        $busy = $this->busyCells($staff->id, $from, $to, $ownBookingId);
        $earliest = CarbonImmutable::now()->addMinutes($settings->leadMinutes);

        $slots = [];
        foreach ($windows as [$open, $close]) {
            for ($t = $open; $t->addMinutes($plan['duration'])->lte($close); $t = $t->addMinutes($settings->slotInterval)) {
                if ($t->lt($earliest)) {
                    continue;
                }
                $free = true;
                foreach ($this->cells($t->subMinutes($plan['before']), $t->addMinutes($plan['duration'] + $plan['after'])) as $cell) {
                    if (isset($busy[$cell])) {
                        $free = false;
                        break;
                    }
                }
                $slots[] = ['starts_at' => $t, 'ends_at' => $t->addMinutes($plan['duration']), 'free' => $free];
            }
        }

        return $slots;
    }

    /**
     * Is this start time one of the free slots of that staff member (the server-side check of a hold request)?
     *
     * @param  Collection<int, BookableService>  $services
     */
    public function isFreeSlot(BookingSettings $settings, Collection $services, string $location, BookingStaff $staff, CarbonInterface $startsAt, ?int $ownBookingId = null): bool
    {
        $date = CarbonImmutable::instance($startsAt)->setTimezone($settings->timezone)->format('Y-m-d');
        foreach ($this->slots($settings, $services, $location, $staff, $date, $ownBookingId) as $slot) {
            if ($slot['free'] && $slot['starts_at']->getTimestamp() === $startsAt->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    public function inHorizon(BookingSettings $settings, string $date): bool
    {
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $settings->timezone);

        return $day !== null && $day->gte($today) && $day->lte($today->addDays($settings->maxDays));
    }

    /**
     * Epoch seconds of every cell that [from, to) touches (start floored, end ceiled to the cell grid).
     *
     * @return list<int>
     */
    public function cells(CarbonInterface $from, CarbonInterface $to): array
    {
        $size = self::CELL * 60;
        $start = intdiv($from->getTimestamp(), $size) * $size;
        $end = $to->getTimestamp();
        $out = [];
        for ($c = $start; $c < $end; $c += $size) {
            $out[] = $c;
        }

        return $out;
    }

    /**
     * Cells held or booked for this staff member in a range (expired ones are free; a reschedule ignores its own).
     *
     * @return array<int, true>
     */
    public function busyCells(int $staffId, CarbonInterface $from, CarbonInterface $to, ?int $ownBookingId = null): array
    {
        $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:s');
        $rows = DB::table('booking_slot_cells')->where('staff_id', $staffId)
            ->where('cell_at', '>=', CarbonImmutable::instance($from)->utc()->subMinutes(self::CELL)->format('Y-m-d H:i:s'))
            ->where('cell_at', '<', CarbonImmutable::instance($to)->utc()->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->when($ownBookingId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('booking_id')->orWhere('booking_id', '!=', $ownBookingId)))
            ->pluck('cell_at');

        $busy = [];
        foreach ($rows as $at) {
            $busy[CarbonImmutable::parse((string) $at, 'UTC')->getTimestamp()] = true;
        }

        return $busy;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function merge(array $ranges): array
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($ranges as $r) {
            $last = count($out) - 1;
            if ($last >= 0 && $r[0]->lte($out[$last][1])) {
                if ($r[1]->gt($out[$last][1])) {
                    $out[$last][1] = $r[1];
                }

                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $open
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $breaks
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function subtract(array $open, array $breaks): array
    {
        foreach ($breaks as [$bs, $be]) {
            $next = [];
            foreach ($open as [$os, $oe]) {
                if ($be->lte($os) || $bs->gte($oe)) {
                    $next[] = [$os, $oe];

                    continue;
                }
                if ($bs->gt($os)) {
                    $next[] = [$os, $bs];
                }
                if ($be->lt($oe)) {
                    $next[] = [$be, $oe];
                }
            }
            $open = $next;
        }

        return $open;
    }
}
