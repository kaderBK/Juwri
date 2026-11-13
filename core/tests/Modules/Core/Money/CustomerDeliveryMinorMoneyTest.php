<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\Zone;
use App\Services\Crm\AudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\City;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Tests\TestCase;

/**
 * P1-05 slice 7b: customers.total_spent, courier_deliveries.fee and zones.base_fee/per_km_fee/min_order are stored
 * as `*_minor` in the row's `currency_code` (the store's; the store's at dispatch; the zone's country currency).
 */
class CustomerDeliveryMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency): Store
    {
        $store = Store::create([
            'user_id' => User::factory()->create()->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    public function test_customer_spend_keeps_fils_whatever_the_fill_order_and_audience_threshold_is_minor(): void
    {
        $r = $this->store('KWD');
        // Amount before store_id: the old StoreMoney cast rounded it to the 2-decimal fallback (1.28).
        $c = Customer::create(['total_spent' => 1.275, 'name' => 'C', 'store_id' => $r->id, 'phone' => '5551']);
        $row = DB::table('customers')->where('id', $c->id)->first();
        $this->assertSame(['KWD', 1275], [$row->currency_code, (int) $row->total_spent_minor]);
        $this->assertSame('1.275', $c->fresh()->total_spent);
        $this->assertSame(1275, $c->fresh()->toArray()['total_spent_minor']);

        $rules = ['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => 'gte', 'value' => '1.275']]];
        $this->assertSame(1, (new AudienceService)->count($r->id, $rules));
        $rules['conditions'][0]['value'] = 1.276;
        $this->assertSame(0, (new AudienceService)->count($r->id, $rules));

        // The checkout counter: an atomic increment of the minor column in the row currency.
        $c->increment('total_spent_minor', 500);
        $this->assertSame('1.775', $c->fresh()->total_spent);
    }

    public function test_courier_fee_is_a_fact_of_dispatch(): void
    {
        $r = $this->store('OMR');
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'customer_name' => 'Joe', 'status' => 'new']);
        $d = CourierDelivery::create(['fee' => 2.125, 'order_id' => $order->id, 'store_id' => $r->id, 'provider' => 'doordash', 'status' => 'created']);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        $d->fresh()->update(['fee' => 2.5]);
        $row = DB::table('courier_deliveries')->where('id', $d->id)->first();
        $this->assertSame(['OMR', 2500], [$row->currency_code, (int) $row->fee_minor]);
        $this->assertSame('2.500', $d->fresh()->fee);
    }

    public function test_zone_fees_use_the_country_currency(): void
    {
        config(['settings.currency_code' => 'USD']);
        $kw = Country::factory()->create(['currency' => 'KWD']);
        $city = City::factory()->create(['country_id' => $kw->id]);
        $square = [[29.0, 47.0], [29.1, 47.0], [29.1, 47.1], [29.0, 47.1]];
        $zone = Zone::create(['city_id' => $city->id, 'name' => 'Z', 'polygon' => $square, 'base_fee' => 0.75, 'per_km_fee' => 0.125, 'min_order' => 3.5]);
        $row = DB::table('zones')->where('id', $zone->id)->first();
        $this->assertSame(['KWD', 750, 125, 3500], [$row->currency_code, (int) $row->base_fee_minor, (int) $row->per_km_fee_minor, (int) $row->min_order_minor]);
        $this->assertSame([0.75, 0.125, 3.5], [Zone::find($zone->id)->base_fee, Zone::find($zone->id)->per_km_fee, Zone::find($zone->id)->min_order]);

        // A zone whose city has no known currency falls back to the platform currency.
        $nowhere = Country::factory()->create(['currency' => '']);
        $z2 = Zone::create(['city_id' => City::factory()->create(['country_id' => $nowhere->id])->id, 'name' => 'Y', 'polygon' => $square, 'base_fee' => 4.5]);
        $this->assertSame(['USD', 450], [DB::table('zones')->where('id', $z2->id)->value('currency_code'), (int) DB::table('zones')->where('id', $z2->id)->value('base_fee_minor')]);
    }

    public function test_legacy_rows(): void
    {
        $this->assertSame(['total_spent_minor' => 1275, 'currency_code' => 'OMR'], LegacyMoneyColumns::upgradeRow('customers', ['total_spent' => '1.275'], 'OMR'));
        $this->assertSame(['currency_code' => 'USD', 'fee_minor' => 750], LegacyMoneyColumns::upgradeRow('courier_deliveries', ['currency_code' => 'USD', 'fee' => '7.50'], 'OMR'));
        $this->assertSame(['base_fee_minor' => 500, 'per_km_fee_minor' => 150, 'min_order_minor' => 2000, 'currency_code' => 'SAR'],
            LegacyMoneyColumns::upgradeRow('zones', ['base_fee' => '5.00', 'per_km_fee' => '1.50', 'min_order' => '20.00'], 'SAR'));
    }
}
