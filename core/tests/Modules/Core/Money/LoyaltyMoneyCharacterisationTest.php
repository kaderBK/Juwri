<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Coupon;
use App\Models\GiftCard;
use App\Models\LoyaltyProgram;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use App\Services\CouponService;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * P1-05 slice 7c (characterisation): the public money behaviour of a coupon's minimum order, a gift card's face
 * value, a loyalty program's reward discount and a price schedule's adjustment (a percent OR an amount, by type).
 * Written against the decimal columns BEFORE the move to `*_minor` and kept green after it: only the storage changes.
 */
class LoyaltyMoneyCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency, ?User $owner = null): Store
    {
        $store = Store::create([
            'user_id' => ($owner ?? User::factory()->create())->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    public function test_coupon_minimum_order_shape_and_gate(): void
    {
        $owner = User::factory()->create();
        $this->store('USD', $owner);
        $c = Coupon::create(['code' => 'MIN30', 'percentage' => 10, 'limit' => 0, 'coupon_type' => 'percentage', 'user_id' => $owner->id, 'issuer_scope' => 'owner', 'min_order_amount' => 30]);
        $this->assertSame('30.00', $c->fresh()->min_order_amount);
        $this->assertSame('30.00', $c->fresh()->toArray()['min_order_amount']);
        $none = Coupon::create(['code' => 'NOMIN', 'percentage' => 5, 'limit' => 0, 'coupon_type' => 'percentage', 'user_id' => $owner->id]);
        $this->assertNull($none->fresh()->min_order_amount);

        $svc = app(CouponService::class);
        $this->assertFalse($svc->resolve('MIN30', ['surface' => 'food_order', 'owner_id' => $owner->id, 'subtotal' => 29.99])['ok']);
        $this->assertTrue($svc->resolve('MIN30', ['surface' => 'food_order', 'owner_id' => $owner->id, 'subtotal' => 30])['ok']);

        $c->update(['min_order_amount' => 12.5]);
        $this->assertSame('12.50', $c->fresh()->min_order_amount);
        $c->update(['min_order_amount' => null]);
        $this->assertNull($c->fresh()->min_order_amount);
    }

    public function test_gift_card_face_value(): void
    {
        $r = $this->store('USD');
        $card = GiftCardService::issueManual($r->id, 25.5);
        $fresh = GiftCard::find($card->id);
        $this->assertSame('25.50', $fresh->initial_value);
        $this->assertSame('25.50', $fresh->toArray()['initial_value']);
        $this->assertSame('USD', $fresh->currency);
        $this->assertSame(25.5, $fresh->balance());

        $omr = $this->store('OMR');
        $o = GiftCard::create(['store_id' => $omr->id, 'code' => 'GC-OMR1', 'currency' => 'OMR', 'initial_value' => 10.125, 'status' => 'pending']);
        $this->assertSame('10.125', GiftCard::find($o->id)->initial_value);
    }

    public function test_loyalty_reward_discount(): void
    {
        $r = $this->store('USD');
        $p = LoyaltyProgram::forRestaurant($r->id);
        $this->assertSame('0.00', $p->fresh()->reward_discount);
        $p->update(['reward_discount' => 2.5, 'active' => true]);
        $this->assertSame('2.50', $p->fresh()->reward_discount);
        $this->assertSame('2.50', $p->fresh()->toArray()['reward_discount']);

        $omr = $this->store('OMR');
        $q = LoyaltyProgram::forRestaurant($omr->id);
        $q->update(['reward_discount' => 0.75]);
        $this->assertSame('0.750', $q->fresh()->reward_discount);
    }

    public function test_price_schedule_adjustment_by_type(): void
    {
        $r = $this->store('USD');
        $base = ['store_id' => $r->id, 'name' => 'HH', 'scope' => 'store', 'active' => true];

        $pct = PriceSchedule::create($base + ['adjustment_type' => 'percent', 'adjustment_value' => 15]);
        $fresh = PriceSchedule::find($pct->id);
        $this->assertSame('15.00', $fresh->adjustment_value);
        $this->assertSame('15.00', $fresh->toArray()['adjustment_value']);
        $this->assertSame(8.5, $fresh->apply(10.0));
        $this->assertSame(16.98, PriceSchedule::create($base + ['adjustment_type' => 'percent', 'adjustment_value' => 15.05])->fresh()->apply(19.99), '15.05 % of 19.99');

        $amt = PriceSchedule::create($base + ['adjustment_type' => 'amount', 'adjustment_value' => 2.5]);
        $this->assertSame('2.50', $amt->fresh()->adjustment_value);
        $this->assertSame(7.5, $amt->fresh()->apply(10.0));
        $this->assertSame(0.0, $amt->fresh()->apply(2.0), 'never negative');

        $fixed = PriceSchedule::create($base + ['adjustment_type' => 'fixed_price', 'adjustment_value' => 6]);
        $this->assertSame(6.0, $fixed->fresh()->apply(10.0));
        $this->assertSame(5.0, $fixed->fresh()->apply(5.0), 'never more than the base');

        $bare = PriceSchedule::create($base);
        $this->assertSame('percent', $bare->fresh()->adjustment_type);
        $this->assertSame('0.00', $bare->fresh()->adjustment_value, 'column default');

        // The owner switches the type and keeps the number.
        $pct->fresh()->update(['adjustment_type' => 'amount']);
        $this->assertSame('15.00', $pct->fresh()->adjustment_value);
        $this->assertSame(5.0, $pct->fresh()->apply(20.0));
        $amt->fresh()->update(['adjustment_type' => 'percent']);
        $this->assertSame(9.75, $amt->fresh()->apply(10.0), 'the 2.5 is now 2.5 %');

        $omr = $this->store('OMR');
        $o = PriceSchedule::create(['store_id' => $omr->id, 'name' => 'O', 'scope' => 'store', 'active' => true, 'adjustment_type' => 'amount', 'adjustment_value' => 0.125]);
        $this->assertSame('0.125', $o->fresh()->adjustment_value);
        $this->assertSame(0.875, $o->fresh()->apply(1.0));
        $op = PriceSchedule::create(['store_id' => $omr->id, 'name' => 'P', 'scope' => 'store', 'active' => true, 'adjustment_type' => 'percent', 'adjustment_value' => 12.5]);
        $this->assertSame('12.500', $op->fresh()->adjustment_value);
        $this->assertSame(0.875, $op->fresh()->apply(1.0));
    }
}
