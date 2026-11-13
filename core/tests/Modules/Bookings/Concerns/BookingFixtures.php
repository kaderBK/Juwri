<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings\Concerns;

use App\Models\StoreOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Tests\Support\TestPlans;

/**
 * P3-10 test fixtures: a `services` store with an owner, staff, services and weekly hours.
 */
trait BookingFixtures
{
    /** @var list<object> */
    protected array $bookingPlans = [];

    /** @param  array<string, mixed>  $settings  StoreSettings name => value */
    protected function servicesStore(string $tz = 'Asia/Riyadh', array $settings = [], ?User $owner = null): Store
    {
        if ($owner === null) {
            $plan = TestPlans::create([
                'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
                'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
            ]);
            $this->bookingPlans[] = $plan;
            $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        }
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'svc-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'Salon '.Str::random(4), 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 Olaya St', 'phone' => '1', 'store_type' => 'services',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'timezone', $tz);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        StoreSettings::setMany((int) $store->id, $settings + ['booking_lead_minutes' => 0]);

        return $store->fresh() ?? $store;
    }

    /** @param  array<string, mixed>  $attrs */
    protected function service(Store $store, array $attrs = []): BookableService
    {
        return BookableService::query()->create($attrs + [
            'store_id' => (int) $store->id, 'name' => 'Haircut', 'duration_min' => 60, 'price_minor' => 10000,
            'currency' => 'SAR', 'deposit_type' => 'none', 'deposit_value' => 0, 'venue' => true, 'at_home' => false, 'active' => true,
        ]);
    }

    /** @param  list<BookableService>  $services */
    protected function staffMember(Store $store, array $services, string $name = 'Maha', int $position = 0): BookingStaff
    {
        $staff = BookingStaff::query()->create(['store_id' => (int) $store->id, 'name' => $name, 'active' => true, 'position' => $position]);
        $staff->services()->syncWithPivotValues(array_map(fn ($s) => $s->id, $services), ['store_id' => (int) $store->id, 'account_id' => (int) $store->account_id]);

        return $staff;
    }

    /** Store hours every day of the week ("open" rows), optionally with a break. */
    protected function weeklyHours(Store $store, string $from = '09:00', string $to = '17:00', ?array $break = null, ?BookingStaff $staff = null, ?array $days = null): void
    {
        foreach ($days ?? [0, 1, 2, 3, 4, 5, 6] as $day) {
            BookingHour::query()->create(['store_id' => (int) $store->id, 'staff_id' => $staff?->id, 'weekday' => $day, 'kind' => 'open', 'starts_local' => $from, 'ends_local' => $to]);
            if ($break !== null) {
                BookingHour::query()->create(['store_id' => (int) $store->id, 'staff_id' => $staff?->id, 'weekday' => $day, 'kind' => 'break', 'starts_local' => $break[0], 'ends_local' => $break[1]]);
            }
        }
    }

    /** An owner on a plan whose settings blob holds these keys (e.g. ['booking' => '0'] withholds bookings). */
    protected function ownerWithPlan(array $planSettings): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999] + $planSettings, // an array: Plan casts settings to an object
        ]);
        $this->bookingPlans[] = $plan;

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    /** A ready-to-book store: hours 09–17, one 60-minute service, one staff member. */
    protected function readyStore(string $tz = 'Asia/Riyadh', array $settings = [], array $serviceAttrs = [], ?User $owner = null): array
    {
        $store = $this->servicesStore($tz, $settings, $owner);
        $service = $this->service($store, $serviceAttrs);
        $staff = $this->staffMember($store, [$service]);
        $this->weeklyHours($store);

        return [$store, $service, $staff];
    }

    /** A store-local wall time as a UTC instant. */
    protected function local(Store $store, string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, store_tz((int) $store->id))->utc();
    }
}
