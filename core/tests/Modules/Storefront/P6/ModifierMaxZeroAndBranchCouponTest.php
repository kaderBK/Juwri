<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\P6;

use App\Models\Coupon;
use App\Services\CouponService;
use App\Services\Storefront\LegacyCartLines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\TestCase;

/** P6-310 (modifier max 0 = unlimited, over-max refused) and P6-311 (branch-locked coupon pre-flight). */
class ModifierMaxZeroAndBranchCouponTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create();

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function itemWithGroup(Store $store, ?int $max): array
    {
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Pizza', 'price' => 10, 'active' => 1]);
        $group = MenuVariantOption::create([
            'store_id' => $store->id, 'title' => 'Toppings', 'is_global' => true, 'menu_id' => null,
            'options' => ['A', 'B', 'C'], 'option_prices' => [1, 1, 1],
            'required' => false, 'min_select' => 0, 'max_select' => $max, 'position' => 0, 'active' => true,
        ]);
        $item->attachedVariantOptions()->syncWithoutDetaching([
            $group->id => ['position' => 0, 'required' => false, 'min_select' => 0, 'max_select' => $max, 'active' => true],
        ]);

        return [$item->fresh(), $group];
    }

    private function picks(int $groupId, int $n): array
    {
        return array_map(fn ($i) => (object) ['groupId' => $groupId, 'optIndex' => $i], range(0, $n - 1));
    }

    public function test_group_max_zero_keeps_all_three_picks(): void
    {
        $store = $this->store();
        [$item, $group] = $this->itemWithGroup($store, 0);

        $out = LegacyCartLines::modsToOptions($this->picks($group->id, 3), $item, $store->id);

        $this->assertSame(['A', 'B', 'C'], $out[0]['selected']);
    }

    public function test_group_max_null_keeps_all_picks(): void
    {
        $store = $this->store();
        [$item, $group] = $this->itemWithGroup($store, null);

        $out = LegacyCartLines::modsToOptions($this->picks($group->id, 3), $item, $store->id);

        $this->assertCount(3, $out[0]['selected']);
    }

    public function test_picks_above_a_real_max_are_refused_naming_the_group(): void
    {
        $store = $this->store();
        [$item, $group] = $this->itemWithGroup($store, 2);

        try {
            LegacyCartLines::modsToOptions($this->picks($group->id, 3), $item, $store->id);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Toppings', $e->errors()['options'][0]);
        }
        $this->assertCount(2, LegacyCartLines::modsToOptions($this->picks($group->id, 2), $item, $store->id)[0]['selected']);
    }

    private function coupon(Store $store, array $over = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'BR'.Str::upper(Str::random(4)), 'coupon_type' => 'percentage', 'percentage' => 10,
            'limit' => 100, 'used' => 0, 'user_id' => $store->user_id,
        ], $over));
    }

    public function test_branch_coupon_applies_on_its_branch_refused_on_another_and_ignored_when_absent(): void
    {
        $store = $this->store();
        $c = $this->coupon($store, ['branch_id' => 7]);
        $svc = new CouponService;

        $this->assertTrue($svc->validate($c->code, 100, $store->user_id, false, null, 'USD', 7)['ok']);
        $this->assertSame(10.0, $svc->validate($c->code, 100, $store->user_id, false, null, 'USD', 7)['discount']);
        $this->assertFalse($svc->validate($c->code, 100, $store->user_id, false, null, 'USD', 8)['ok']);
        $this->assertTrue($svc->validate($c->code, 100, $store->user_id, false, null, 'USD')['ok']);
    }

    public function test_minimum_message_shows_the_amount(): void
    {
        $store = $this->store();
        $c = $this->coupon($store, ['min_order_amount' => 50]);

        $r = (new CouponService)->validate($c->code, 10, $store->user_id, false, null, 'USD');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('50.00', $r['message']);
    }
}
