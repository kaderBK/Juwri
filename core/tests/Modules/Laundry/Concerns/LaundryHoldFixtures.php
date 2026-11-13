<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Laundry\Concerns;

use App\Models\StoreOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;

/** P3-11 test fixtures: a laundry store with slot scheduling on (UTC, 30-minute slots, capacity 1) and its two services. */
trait LaundryHoldFixtures
{
    /** @var list<object> */
    protected array $laundryPlans = [];

    /** @param  array<string, mixed>  $options  post options over the defaults */
    protected function laundryStore(array $options = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999],
        ]);
        $this->laundryPlans[] = $plan;
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'ldy-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'Laundry '.Str::random(4), 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 Olaya St', 'phone' => '1', 'store_type' => 'laundry',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options + [
            'allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30, 'scheduling_lead_minutes' => 30,
            'scheduling_max_days' => 7, 'scheduling_slot_capacity' => 1, 'timezone' => 'UTC', 'currency_code' => 'SAR',
        ] as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store->fresh() ?? $store;
    }

    protected function shirtIron(Store $store): Menu
    {
        return Menu::create(['store_id' => $store->id, 'name' => 'Shirt — iron', 'price' => 3.5, 'pricing_unit' => 'each', 'active' => 1]);
    }

    protected function washAndFold(Store $store): Menu
    {
        return Menu::create(['store_id' => $store->id, 'name' => 'Wash & fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);
    }

    /** A slot id (`Y-m-d\TH:i`, store time = UTC) `$days` days from the frozen "now" at `$time`. */
    protected function slotId(int $days, string $time): string
    {
        return CarbonImmutable::now('UTC')->addDays($days)->format('Y-m-d').'T'.$time;
    }
}
