<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-48b: the landing "N restaurants" count applies the store.public-listing fence (no unpublished pharmacies). */
class PharmacyActiveCountTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(string $type): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Store '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#1f6feb', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'logo.png', 'cover_image' => 'logo.png', 'address' => '1 St', 'phone' => '1234',
        ]);
    }

    public function test_unpublished_pharmacy_is_not_counted(): void
    {
        Cache::flush();
        $this->makeStore('restaurant');
        $this->makeStore('pharmacy');

        $this->assertSame(1, active_store_count());
    }

    public function test_published_pharmacy_is_counted(): void
    {
        Cache::flush();
        $this->makeStore('restaurant');
        PharmacyMarket::open($this->makeStore('pharmacy'));

        $this->assertSame(2, active_store_count());
    }
}
