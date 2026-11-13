<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingStaff;

/**
 * P3-10 / P3-11: what a booking page shows, as plain data — the day strip, the free times of the chosen day grouped by
 * part of day, the staff who can do the chosen services, the appointment summary and the cancellation-policy line.
 * One place for the plain pages and the `book` theme's view model, so the two can never disagree.
 */
final class BookingPages
{
    public function __construct(private SlotEngine $engine) {}

    /**
     * The "choose a time" data: staff, 7-day strip, and the free times of one day.
     *
     * @param  Collection<int, BookableService>  $services
     * @return array<string, mixed>
     */
    public function times(Store $store, BookingSettings $settings, Collection $services, string $location, ?int $staffParam, ?string $dateParam, ?Booking $own = null): array
    {
        $staffList = $this->engine->eligibleStaff((int) $store->id, $services);
        $staffId = $settings->staffChoice ? ($staffParam ?: null) : null;
        if ($staffId !== null && ! $staffList->contains('id', $staffId)) {
            $staffId = null;
        }
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        $date = (string) ($dateParam ?? $today->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $this->engine->inHorizon($settings, $date)) {
            $date = $today->toDateString();
        }
        $pool = $staffId !== null ? $staffList->where('id', $staffId) : $staffList;

        // day strip: 7 days from the week of the chosen date (closed days stay visible, marked)
        $first = max($today->getTimestamp(), CarbonImmutable::createFromFormat('!Y-m-d', $date, $settings->timezone)->subDays(3)->getTimestamp());
        $stripStart = CarbonImmutable::createFromTimestamp($first, $settings->timezone)->startOfDay();
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $stripStart->addDays($i);
            if (! $this->engine->inHorizon($settings, $d->toDateString())) {
                break;
            }
            $works = $pool->contains(fn (BookingStaff $s) => $this->engine->windows($settings, $s, $d->toDateString()) !== []);
            $days[] = ['date' => $d->toDateString(), 'label' => $d->translatedFormat('D j M'), 'closed' => ! $works];
        }

        // slots: "any staff" = the union, each time booked with the first free staff member
        $slots = [];
        foreach ($pool as $staff) {
            foreach ($this->engine->slots($settings, $services, $location, $staff, $date, $own?->id) as $slot) {
                $key = $slot['starts_at']->getTimestamp();
                if (! isset($slots[$key]) || (! $slots[$key]['free'] && $slot['free'])) {
                    $slots[$key] = $slot + ['staff_id' => $staff->id];
                }
            }
        }
        ksort($slots);
        $groups = [];
        foreach ($slots as $ts => $slot) {
            $local = $slot['starts_at']->setTimezone($settings->timezone);
            $part = $local->hour < 12 ? ___('Morning') : ($local->hour < 17 ? ___('Afternoon') : ___('Evening'));
            $groups[$part][] = [
                'ts' => $ts,
                'label' => $local->format('H:i'),
                'range' => $local->format('H:i').'–'.$slot['ends_at']->setTimezone($settings->timezone)->format('H:i'),
                'free' => $slot['free'],
                'staff_id' => $staffId,
            ];
        }
        $plan = $this->engine->plan($services, $location, $settings);

        return [
            'services' => $services,
            'serviceIds' => $services->pluck('id')->all(),
            'serviceLine' => $services->pluck('name')->implode(', ').' · '.BookingMoney::duration($plan['duration']),
            'duration' => $plan['duration'],
            'location' => $location,
            'staffChoice' => $settings->staffChoice && $staffList->count() > 1,
            'staffList' => $staffList,
            'staffId' => $staffId,
            'date' => $date,
            'days' => $days,
            'groups' => $groups,
            'freeCount' => count(array_filter($slots, fn ($s) => $s['free'])),
        ];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function summary(BookingSettings $settings, \DateTimeInterface $start, \DateTimeInterface $end, array $names, ?BookingStaff $staff): array
    {
        $s = CarbonImmutable::instance($start)->setTimezone($settings->timezone);
        $e = CarbonImmutable::instance($end)->setTimezone($settings->timezone);

        return [
            'date' => $s->translatedFormat('l j F Y'),
            'time' => $s->format('H:i').'–'.$e->format('H:i'),
            'duration' => BookingMoney::duration((int) round(($e->getTimestamp() - $s->getTimestamp()) / 60)),
            'services' => implode(', ', $names),
            'staff' => (string) $staff?->name,
        ];
    }

    /** The cancellation policy line, computed from the real deadline (book §7, D7/D8). */
    public function policy(BookingSettings $settings, CarbonImmutable $deadline, bool $hasDeposit): string
    {
        $when = $deadline->setTimezone($settings->timezone)->translatedFormat('D j M, H:i');
        if (! $hasDeposit) {
            return ___('Free cancellation or changes until :when.', ['when' => $when]);
        }

        return $settings->depositRefunds
            ? ___('Free cancellation or changes until :when. After that the deposit is kept.', ['when' => $when])
            : ___('Deposits are not refunded. You can reschedule until :when.', ['when' => $when]);
    }

    /** "Times are in Riyadh time (GMT+3)." */
    public function zoneNote(BookingSettings $settings): string
    {
        $offset = CarbonImmutable::now($settings->timezone)->format('P');

        return ___('Times are in :zone time (GMT:offset).', ['zone' => str_replace('_', ' ', (string) Str::afterLast($settings->timezone, '/')), 'offset' => $offset]);
    }
}
