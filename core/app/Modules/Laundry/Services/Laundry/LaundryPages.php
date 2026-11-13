<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Services\Laundry;

use Carbon\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\ScheduledSlots;

/**
 * P3-11: what the laundry "choose your times" page shows, as plain data: the day strip and the free times of the pickup
 * slot, and — when the laundry brings the order back (`delivery`) — of the delivery-back slot, from the earliest the
 * turnaround allows. Slot ids are the scheduling's `Y-m-d\TH:i` (store time), what the hold takes.
 */
final class LaundryPages
{
    public function __construct(private ScheduledSlots $scheduling, private LaundryHolds $holds) {}

    /**
     * @return array{mode: string, date: string, days: list<array{date: string, label: string, closed: bool}>, groups: array<string, list<array{id: string, label: string, free: bool}>>, freeCount: int, returnDate: string|null, returnDays: list<array{date: string, label: string, closed: bool}>, returnGroups: array<string, list<array{id: string, label: string, free: bool}>>, returnFreeCount: int, turnaround: int}
     */
    public function schedule(Store $store, string $mode, ?string $date, ?string $pickup, ?string $returnDate): array
    {
        $storeId = (int) $store->id;
        $tz = $this->scheduling->timezone($storeId);
        $today = Carbon::now($tz)->startOfDay();

        $days = $this->days($storeId, $mode, $today);
        $date = $this->pick($days, $date);
        $slots = $this->scheduling->slotsFor($storeId, null, $mode, $date);

        $out = [
            'mode' => $mode,
            'date' => $date,
            'days' => $this->mark($days),
            'groups' => $this->group($slots, fn (array $s) => ['at' => $s['at'], 'free' => ! $s['full']]),
            'freeCount' => count(array_filter($slots, fn (array $s) => ! $s['full'])),
            'returnDate' => null,
            'returnDays' => [],
            'returnGroups' => [],
            'returnFreeCount' => 0,
            'turnaround' => $this->holds->turnaroundHours($storeId),
        ];

        if ($mode === 'delivery') {
            $pickupAt = null;
            if ($pickup !== null && $pickup !== '') {
                try {
                    $pickupAt = Carbon::parse($pickup, $tz);
                } catch (\Throwable) {
                    $pickupAt = null;
                }
            }
            $earliest = $pickupAt?->copy()->addHours($out['turnaround'])->startOfDay() ?? $today;
            $returnDays = $this->days($storeId, 'delivery', $earliest->lt($today) ? $today : $earliest);
            $returnDate = $this->pick($returnDays, $returnDate);
            $rows = $this->holds->returnSlots($storeId, $returnDate, $pickupAt);
            $out['returnDate'] = $returnDate;
            $out['returnDays'] = $this->mark($returnDays);
            $out['returnGroups'] = $this->group($rows, fn (array $s) => ['at' => $s['at'], 'free' => ! $s['full']]);
            $out['returnFreeCount'] = count(array_filter($rows, fn (array $s) => ! $s['full']));
        }

        return $out;
    }

    /**
     * Seven days from `$from` (within the store's horizon), marking the days with no slot at all.
     *
     * @return list<array{date: string, closed: bool}>
     */
    private function days(int $storeId, string $mode, Carbon $from): array
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $from->copy()->addDays($i);
            $key = $day->format('Y-m-d');
            $slots = $this->scheduling->slotsFor($storeId, null, $mode, $key);
            $horizon = Carbon::now($from->timezone)->addDays($this->scheduling->maxDays($storeId))->startOfDay();
            if ($day->gt($horizon)) {
                break;
            }
            $days[] = ['date' => $key, 'closed' => $slots === []];
        }

        return $days;
    }

    /**
     * The asked day when it is in the strip and open, else the first open day.
     *
     * @param  list<array<string, mixed>>  $days
     */
    private function pick(array $days, ?string $asked): string
    {
        foreach ($days as $d) {
            if ($d['date'] === $asked && ! $d['closed']) {
                return $d['date'];
            }
        }
        foreach ($days as $d) {
            if (! $d['closed']) {
                return $d['date'];
            }
        }

        return $days[0]['date'] ?? Carbon::now()->format('Y-m-d');
    }

    /**
     * @param  list<array{date: string, closed: bool}>  $days
     * @return list<array{date: string, label: string, closed: bool}>
     */
    private function mark(array $days): array
    {
        return array_map(fn (array $d) => $d + ['label' => Carbon::createFromFormat('!Y-m-d', $d['date'])->translatedFormat('D j M')], $days);
    }

    /**
     * Slots grouped by part of day.
     *
     * @param  list<array<string, mixed>>  $slots
     * @param  callable(array<string, mixed>): array{at: Carbon, free: bool}  $read
     * @return array<string, list<array{id: string, label: string, free: bool}>>
     */
    private function group(array $slots, callable $read): array
    {
        $groups = [];
        foreach ($slots as $slot) {
            ['at' => $at, 'free' => $free] = $read($slot);
            $part = $at->hour < 12 ? ___('Morning') : ($at->hour < 17 ? ___('Afternoon') : ___('Evening'));
            $groups[$part][] = ['id' => $at->format('Y-m-d\TH:i'), 'label' => $at->format('H:i'), 'free' => $free];
        }

        return $groups;
    }
}
