<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Contracts\StorefrontBookings;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\BookingStaff;

/** P3-11: {@see StorefrontBookings}. Reads only the asked store (every query is store-scoped). */
final class StorefrontBookingsService implements StorefrontBookings
{
    public function __construct(private SlotEngine $engine) {}

    public function inUse(Store $store): bool
    {
        return BookableService::query()->where('store_id', $store->id)->where('active', true)->exists();
    }

    public function open(Store $store): bool
    {
        return BookingSetup::isOpen($store);
    }

    public function services(Store $store, string $location = 'venue'): array
    {
        return BookableService::query()->where('store_id', $store->id)->where('active', true)
            ->when($location === 'at_home', fn ($q) => $q->where('at_home', true), fn ($q) => $q->where('venue', true))
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (BookableService $s) => [
                'id' => (string) $s->public_id,
                'name' => (string) $s->name,
                'description' => trim((string) $s->description),
                'duration_min' => (int) $s->duration_min,
                'price_minor' => (int) $s->price_minor,
                'currency' => (string) $s->currency,
                'deposit_minor' => (int) $s->depositMinor(),
                'venue' => (bool) $s->venue,
                'at_home' => (bool) $s->at_home,
            ])->values()->all();
    }

    public function staff(Store $store): array
    {
        if (! BookingSettings::for((int) $store->id)->staffChoice) {
            return [];
        }
        $services = BookableService::query()->where('store_id', $store->id)->where('active', true)->pluck('public_id', 'id');

        return BookingStaff::query()->where('store_id', $store->id)->where('active', true)->with('services:id')
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (BookingStaff $m) => [
                'id' => (string) $m->public_id,
                'name' => (string) $m->name,
                'role' => trim((string) $m->role),
                'photo' => trim((string) $m->photo_url),
                'service_ids' => $m->services->map(fn ($s) => (string) ($services[$s->id] ?? ''))->filter()->values()->all(),
            ])->values()->all();
    }

    public function nextFree(Store $store, string $location = 'venue'): ?string
    {
        if (! BookingSetup::isOpen($store)) {
            return null;
        }

        // a short memo: the home page asks on every view and the search walks a few days of calendars
        return Cache::remember('bookings:next-free:'.$store->id.':'.$location, 60, function () use ($store, $location): string {
            return $this->searchNextFree($store, $location) ?? '';
        }) ?: null;
    }

    private function searchNextFree(Store $store, string $location): ?string
    {
        $settings = BookingSettings::for((int) $store->id);
        $first = BookableService::query()->where('store_id', $store->id)->where('active', true)
            ->when($location === 'at_home', fn ($q) => $q->where('at_home', true), fn ($q) => $q->where('venue', true))
            ->orderBy('position')->orderBy('id')->first();
        if (! $first instanceof BookableService) {
            return null;
        }
        $services = collect([$first]);
        $staff = $this->engine->eligibleStaff((int) $store->id, $services);
        $day = CarbonImmutable::now($settings->timezone)->startOfDay();
        for ($i = 0; $i < min(14, $settings->maxDays + 1); $i++, $day = $day->addDay()) {
            $best = null;
            foreach ($staff as $member) {
                foreach ($this->engine->slots($settings, $services, $location, $member, $day->toDateString()) as $slot) {
                    if ($slot['free'] && ($best === null || $slot['starts_at']->lt($best))) {
                        $best = $slot['starts_at'];
                        break;
                    }
                }
            }
            if ($best !== null) {
                return $best->setTimezone($settings->timezone)->toIso8601String();
            }
        }

        return null;
    }

    public function rules(Store $store): array
    {
        $s = BookingSettings::for((int) $store->id);

        return [
            'timezone' => $s->timezone,
            'cancel_window_hours' => $s->cancelWindowHours,
            'deposit_refunds' => $s->depositRefunds,
            'at_home' => $s->atHome,
            'staff_choice' => $s->staffChoice,
            'policy_note' => $s->policyNote,
            'tax_label' => BookingQuote::taxLabel((int) $store->id),
        ];
    }

    public function quote(Store $store, array $serviceIds, string $location = 'venue'): ?array
    {
        try {
            $services = $this->engine->services((int) $store->id, $serviceIds, $location === 'at_home' ? 'at_home' : 'venue');
        } catch (BookingException) {
            return null;
        }
        $quote = BookingQuote::for((int) $store->id, $services);
        $quote['lines'] = $services->values()->map(fn (BookableService $s, int $i) => [
            'id' => (string) $s->public_id, 'name' => (string) $s->name, 'duration_min' => (int) $s->duration_min,
            'price_minor' => (int) $s->price_minor, 'deposit_minor' => (int) $s->depositMinor(),
        ])->all();

        return $quote;
    }
}
