<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-34: the owner saves "Free delivery over this amount also covers branch delivery fees" next to the threshold
 * (store settings, PUT restaurants.update). Unset = ON; the form round-trips 0/1.
 */
class FreeDeliveryBranchFeeSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    public function test_the_owner_turns_the_setting_off_and_on_again(): void
    {
        [$owner, $store] = $this->makeStore();
        $this->assertFalse(StoreSettings::has((int) $store->id, 'restaurant_free_delivery_covers_branch_fee'));

        $this->actingAs($owner)->put(route('stores.update', $store->id), $this->payload(['restaurant_free_delivery_covers_branch_fee' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertFalse(StoreSettings::get((int) $store->id, 'restaurant_free_delivery_covers_branch_fee'));
        $this->assertSame('5', (string) StoreOption::getStoreOption($store->id, 'restaurant_free_delivery_over'));

        $this->actingAs($owner)->put(route('stores.update', $store->id), $this->payload(['restaurant_free_delivery_covers_branch_fee' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertTrue(StoreSettings::get((int) $store->id, 'restaurant_free_delivery_covers_branch_fee'));

        // a value other than 0/1 is refused and nothing changes
        $this->actingAs($owner)->put(route('stores.update', $store->id), $this->payload(['restaurant_free_delivery_covers_branch_fee' => 'maybe', 'restaurant_free_delivery_over' => '9']));
        $this->assertTrue(StoreSettings::get((int) $store->id, 'restaurant_free_delivery_covers_branch_fee'));
        $this->assertSame('5', (string) StoreOption::getStoreOption($store->id, 'restaurant_free_delivery_over'));
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra): array
    {
        return array_merge([
            'color' => '#111',
            'title' => 'My Store',
            'description' => 'A description',
            'address' => '1 Main St',
            'store_template' => 'juwri',
            'currency_code' => 'USD',
            'currency_sign' => '$',
            'currency_pos' => 0,
            'restaurant_free_delivery_over' => '5',
        ], $extra);
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makeStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');

        return [$owner, $store];
    }
}
