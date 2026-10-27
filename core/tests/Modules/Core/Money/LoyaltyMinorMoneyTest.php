<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Coupon;
use App\Models\GiftCard;
use App\Models\LoyaltyProgram;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Tests\TestCase;

/**
 * P1-05 slice 7c: coupons.min_order_amount, gift_cards.initial_value and loyalty_programs.reward_discount are stored
 * as `*_minor`; price_schedules.adjustment_value is split by type (owner decision 2026-10-01): a percent in
 * `adjustment_rate`, an amount in `adjustment_minor` — exactly one per row.
 */
class LoyaltyMinorMoneyTest extends TestCase
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

    public function test_coupon_minimum_is_in_the_issuers_currency(): void
    {
        config(['settings.currency_code' => 'USD']);
        $owner = User::factory()->create();
        $this->store('KWD', $owner);
        // The old cast had no store to resolve on a coupon and always rounded to 2 decimals (1.28).
        $c = Coupon::create(['code' => 'KW1', 'percentage' => 10, 'limit' => 0, 'coupon_type' => 'percentage', 'min_order_amount' => 1.275, 'user_id' => $owner->id]);
        $row = DB::table('coupons')->where('id', $c->id)->first();
        $this->assertSame(['KWD', 1275], [$row->currency_code, (int) $row->min_order_amount_minor]);
        $this->assertSame('1.275', $c->fresh()->min_order_amount);
        $listed = Coupon::whereKey($c->id)->get(['id', 'min_order_amount_minor', 'currency_code'])->first();
        $this->assertSame('1.275', $listed->min_order_amount);

        $platform = Coupon::create(['code' => 'PL1', 'percentage' => 10, 'limit' => 0, 'coupon_type' => 'percentage', 'min_order_amount' => 20]);
        $this->assertSame(['USD', 2000], [DB::table('coupons')->where('id', $platform->id)->value('currency_code'), (int) DB::table('coupons')->where('id', $platform->id)->value('min_order_amount_minor')]);
    }

    public function test_gift_card_face_value_and_reward_discount(): void
    {
        $r = $this->store('OMR');
        $card = GiftCard::create(['initial_value' => 10.125, 'store_id' => $r->id, 'code' => 'GC-1', 'currency' => 'OMR', 'status' => 'pending']);
        $this->assertSame(10125, (int) DB::table('gift_cards')->where('id', $card->id)->value('initial_value_minor'));
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        $this->assertSame('10.125', GiftCard::find($card->id)->initial_value, 'the card currency is a fact');

        $p = LoyaltyProgram::forRestaurant($r->id);
        $p->update(['reward_discount' => 2.5]);
        $row = DB::table('loyalty_programs')->where('id', $p->id)->first();
        $this->assertSame(['USD', 250], [$row->currency_code, (int) $row->reward_discount_minor]);
    }

    public function test_price_schedule_rows_say_what_their_number_means(): void
    {
        $r = $this->store('KWD');
        $base = ['store_id' => $r->id, 'name' => 'S', 'scope' => 'store', 'active' => true];

        // Value before type (fill order does not matter): a percent goes to the rate column, unrounded.
        $pct = PriceSchedule::create(['adjustment_value' => 12.345, 'adjustment_type' => 'percent'] + $base);
        $row = DB::table('price_schedules')->where('id', $pct->id)->first();
        $this->assertEqualsWithDelta(12.345, (float) $row->adjustment_rate, 1e-9);
        $this->assertNull($row->adjustment_minor);
        $this->assertSame('12.345', $pct->fresh()->adjustment_value);
        $this->assertSame(12.345, $pct->fresh()->adjustmentRate());

        $amt = PriceSchedule::create(['adjustment_value' => 0.125, 'adjustment_type' => 'amount'] + $base);
        $row = DB::table('price_schedules')->where('id', $amt->id)->first();
        $this->assertSame([null, 125, 'KWD'], [$row->adjustment_rate, (int) $row->adjustment_minor, $row->currency_code]);
        $fresh = $amt->fresh();
        $this->assertSame(0.125, $fresh->adjustment_amount);
        $this->assertNull($fresh->adjustmentRate());
        $json = $fresh->toArray();
        $this->assertSame(['0.125', null, 125, 0.125], [$json['adjustment_value'], $json['adjustment_rate'], $json['adjustment_minor'], $json['adjustment_amount']]);

        // Type switch keeps the number and moves it to the other column.
        $fresh->update(['adjustment_type' => 'percent']);
        $row = DB::table('price_schedules')->where('id', $amt->id)->first();
        $this->assertEqualsWithDelta(0.125, (float) $row->adjustment_rate, 1e-9);
        $this->assertNull($row->adjustment_minor);
        $amt->fresh()->update(['adjustment_type' => 'fixed_price', 'adjustment_value' => 3]);
        $row = DB::table('price_schedules')->where('id', $amt->id)->first();
        $this->assertSame([null, 3000], [$row->adjustment_rate, (int) $row->adjustment_minor]);
        $this->assertSame(3.0, $amt->fresh()->apply(5.0));

        // A bare rule: percent (the column default type) of 0.
        $bare = PriceSchedule::create($base);
        $row = DB::table('price_schedules')->where('id', $bare->id)->first();
        $this->assertSame([0.0, null], [(float) $row->adjustment_rate, $row->adjustment_minor]);
    }

    public function test_legacy_rows(): void
    {
        $this->assertSame(['adjustment_type' => 'percent', 'adjustment_rate' => '15.000', 'adjustment_minor' => null],
            LegacyMoneyColumns::upgradeRow('price_schedules', ['adjustment_type' => 'percent', 'adjustment_value' => '15.000'], 'KWD'));
        $this->assertSame(['adjustment_type' => 'amount', 'adjustment_rate' => null, 'adjustment_minor' => 1250, 'currency_code' => 'KWD'],
            LegacyMoneyColumns::upgradeRow('price_schedules', ['adjustment_type' => 'amount', 'adjustment_value' => '1.250'], 'KWD'));
        $this->assertSame(['adjustment_rate' => 10, 'adjustment_minor' => null], LegacyMoneyColumns::upgradeRow('price_schedules', ['adjustment_value' => 10], 'USD'));
        $this->assertSame(['currency' => 'OMR', 'initial_value_minor' => 10125], LegacyMoneyColumns::upgradeRow('gift_cards', ['currency' => 'OMR', 'initial_value' => '10.125'], 'USD'));
        $this->assertSame(['min_order_amount_minor' => null, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('coupons', ['min_order_amount' => null], 'USD'));
        $this->assertSame(['reward_discount_minor' => 250, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('loyalty_programs', ['reward_discount' => '2.50'], 'USD'));
    }
}
