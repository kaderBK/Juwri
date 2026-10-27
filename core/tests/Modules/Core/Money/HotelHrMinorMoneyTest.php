<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelEventItem;
use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\RateService;
use Juwri\Modules\Hr\Models\PayrollItem;
use Juwri\Modules\Hr\Models\PayrollRun;
use Juwri\Modules\Hr\Models\StaffRoleRate;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 8b: Hotel and Hr money in `*_minor` — currency stamping, catalogue rows (room types, wages) follow the
 * store when edited while bookings, event lines and payroll keep theirs, the rate-plan modifier split (percent stays
 * a rate, fixed is money), ISO-exponent rounding (KWD fils, JPY whole yen), per-row SQL sums, legacy archive rows.
 */
class HotelHrMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Store} */
    private function store(string $currency): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'm-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$owner, $store];
    }

    public function test_hotel_rows_stamping_and_rate_plan_split(): void
    {
        config(['settings.currency_code' => 'USD']);
        [, $store] = $this->store('KWD');
        $type = RoomType::create(['base_rate' => 35.125, 'store_id' => $store->id, 'name' => 'Std']);
        $this->assertSame(['KWD', 35125], [$type->currency_code, $type->base_rate_minor]);

        $fixed = RatePlan::create(['store_id' => $store->id, 'name' => 'BB', 'modifier_type' => 'fixed', 'modifier_value' => 1.375]);
        $row = DB::table('rate_plans')->where('id', $fixed->id)->first();
        $this->assertSame([null, 1375, 'KWD'], [$row->modifier_value, (int) $row->modifier_amount_minor, $row->currency_code]);
        $this->assertSame(1.375, $fixed->fresh()->modifier_value);
        $json = $fixed->fresh()->toArray();
        $this->assertSame([1.375, 1.375, 1375], [$json['modifier_value'], $json['modifier_amount'], $json['modifier_amount_minor']]);
        $pct = RatePlan::create(['store_id' => $store->id, 'name' => 'Adv', 'modifier_type' => 'percent', 'modifier_value' => 12.5]);
        $row = DB::table('rate_plans')->where('id', $pct->id)->first();
        $this->assertSame([12.5, null], [(float) $row->modifier_value, $row->modifier_amount_minor]);
        // fixed → percent clears the amount; percent → fixed moves the number into the amount
        $fixed->fresh()->update(['modifier_type' => 'percent', 'modifier_value' => -10]);
        $row = DB::table('rate_plans')->where('id', $fixed->id)->first();
        $this->assertSame([-10.0, null], [(float) $row->modifier_value, $row->modifier_amount_minor]);
        // An archived fixed plan whose amount still sits in modifier_value reads (and quotes) as before.
        DB::table('rate_plans')->where('id', $pct->id)->update(['modifier_type' => 'fixed', 'modifier_value' => 2.5]);
        $this->assertSame(2.5, $pct->fresh()->modifier_value);
        $this->assertSame(37.625, RateService::quote($type->fresh(), $pct->fresh(), '2026-09-01', '2026-09-02')['total']);
        $pct->fresh()->update(['name' => 'Moved']);
        $this->assertSame([null, 2500], [DB::table('rate_plans')->where('id', $pct->id)->value('modifier_value'), (int) DB::table('rate_plans')->where('id', $pct->id)->value('modifier_amount_minor')]);

        $booking = HotelBooking::create([
            'store_id' => $store->id, 'code' => HotelBooking::generateCode(), 'token' => HotelBooking::generateToken(),
            'guest_name' => 'G', 'room_type_id' => $type->id, 'check_in' => '2026-09-01', 'check_out' => '2026-09-02',
            'quoted_total' => 35.125, 'currency' => 'KWD', 'status' => 'pending', 'source' => 'desk',
        ]);
        $event = HotelEvent::create(['store_id' => $store->id, 'title' => 'E']);
        $line = HotelEventItem::create(['event_id' => $event->id, 'store_id' => $store->id, 'description' => 'Hall', 'qty' => 1, 'unit_price' => 10.005]);

        // The store moves to JPY: bookings and event lines keep KWD; the room type follows the store when edited.
        StoreOption::updateStoreOption($store->id, 'currency_code', 'JPY');
        $booking->fresh()->update(['quoted_total' => 40.25]);
        $this->assertSame(['KWD', 40250], [$booking->fresh()->currency, $booking->fresh()->quoted_total_minor]);
        $line->fresh()->update(['unit_price' => 11.125]);
        $this->assertSame(['KWD', 11125], [$line->fresh()->currency_code, $line->fresh()->unit_price_minor]);
        $type->fresh()->update(['base_rate' => 12000.6]);
        $this->assertSame(['JPY', 12001, 12001.0], [$type->fresh()->currency_code, $type->fresh()->base_rate_minor, $type->fresh()->base_rate]);
    }

    public function test_payroll_items_carry_the_run_currency_and_sum_per_row(): void
    {
        config(['settings.currency_code' => 'USD']);
        [$owner, $store] = $this->store('OMR');
        $staff = StaffMember::create([
            'base_wage' => 2.125, 'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'S', 'email' => 's'.Str::random(5).'@x.co',
            'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true,
        ]);
        $this->assertSame(['OMR', 2125, null], [$staff->currency_code, $staff->base_wage_minor, $staff->hourly_rate_minor]);
        $rr = StaffRoleRate::create(['hourly_rate' => 1.875, 'staff_id' => $staff->id, 'role' => 'waiter']);
        $this->assertSame(['OMR', 1875, '1.88'], [$rr->currency_code, $rr->hourly_rate_minor, $rr->fresh()->hourly_rate]);
        $this->assertSame('1.875', $rr->fresh()->money('hourly_rate')->major());

        $run = PayrollRun::create(['store_id' => $store->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-07', 'status' => 'draft']);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $item = PayrollItem::create(['payroll_run_id' => $run->id, 'staff_id' => $staff->id, 'net' => 21.255, 'base_pay' => 21.255]);
        $this->assertSame(['OMR', 21255, '21.255'], [$item->currency_code, $item->net_minor, $item->fresh()->net]);
        PayrollItem::create(['payroll_run_id' => $run->id, 'staff_id' => $staff->id, 'net' => 0.5]);
        $sum = (float) PayrollItem::where('payroll_run_id', $run->id)->sum(DB::raw(PayrollItem::minorMoneySql('net')));
        $this->assertEqualsWithDelta(21.755, $sum, 0.000001);
        $this->assertSame('OMR', $run->fresh()->currency_code);
    }

    public function test_legacy_rows(): void
    {
        $this->assertSame(['base_rate_minor' => 12050, 'currency_code' => 'USD'], LegacyMoneyColumns::upgradeRow('room_types', ['base_rate' => '120.50'], 'USD'));
        $this->assertSame(['currency' => 'OMR', 'quoted_total_minor' => 70250],
            LegacyMoneyColumns::upgradeRow('hotel_bookings', ['currency' => 'OMR', 'quoted_total' => '70.250'], 'USD'));
        $this->assertSame(['quoted_total_minor' => null, 'currency' => 'USD'], LegacyMoneyColumns::upgradeRow('hotel_bookings', ['quoted_total' => null], 'USD'));
        $this->assertSame(['hourly_rate_minor' => null, 'base_wage_minor' => 2125, 'currency_code' => 'OMR'],
            LegacyMoneyColumns::upgradeRow('restaurant_staff', ['hourly_rate' => null, 'base_wage' => '2.125'], 'OMR'));
        $this->assertSame(['tips_minor' => 0, 'net_minor' => 13025, 'currency_code' => 'USD'],
            LegacyMoneyColumns::upgradeRow('payroll_items', ['net' => '130.25', 'tips' => '0.00'], 'USD'));
        $this->assertSame(['modifier_type' => 'fixed', 'modifier_value' => '20.000'],
            LegacyMoneyColumns::upgradeRow('rate_plans', ['modifier_type' => 'fixed', 'modifier_value' => '20.000'], 'USD'));
    }
}
