<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelEventItem;
use Juwri\Modules\Hotel\Models\HotelExtraService;
use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomRate;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\RateService;
use Juwri\Modules\Hr\Models\StaffRoleRate;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Juwri\Modules\Hr\Services\Payroll\PayrollEngine;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 8b (characterisation): the public money behaviour of Hotel (room-type base rates, per-date rates,
 * rate-plan modifiers — percent and fixed —, extra services, booking quotes, event lines) and Hr (staff wages,
 * role rates, payroll lines/run totals, adjustments) for a USD and a 3-decimal (OMR) store. Written against the
 * decimal columns BEFORE the move to `*_minor` and kept green after it: only the storage changes.
 */
class HotelHrMoneyCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.currency_code' => 'USD', 'settings.admin_allow_payroll_suite' => true]);
        app()->setLocale('en');
    }

    /** @return array{0: User, 1: Store} */
    private function store(string $currency = 'USD'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'h-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$owner, $store];
    }

    public function test_room_rates_plans_services_and_quotes(): void
    {
        [, $store] = $this->store();
        $plain = RoomType::create(['store_id' => $store->id, 'name' => 'Plain']);
        $this->assertSame(0.0, $plain->fresh()->base_rate, 'column default');

        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 120.5]);
        $this->assertSame(120.5, $type->fresh()->base_rate);
        $this->assertSame(120.5, $type->fresh()->toArray()['base_rate']);
        $rate = RoomRate::create(['store_id' => $store->id, 'room_type_id' => $type->id, 'rate_plan_id' => null, 'date' => '2026-09-02', 'price' => 175.25]);
        $this->assertSame(175.25, $rate->fresh()->price);
        $this->assertSame(175.25, $rate->fresh()->toArray()['price']);

        $q = RateService::quote($type, null, '2026-09-01', '2026-09-04');
        $this->assertSame([120.5, 175.25, 120.5], array_column($q['nights'], 'price'));
        $this->assertSame(416.25, $q['total']);

        $default = RateService::ensureDefaultPlan($store->id);
        $this->assertNull($default->fresh()->modifier_value);
        $pct = RatePlan::create(['store_id' => $store->id, 'name' => 'Adv', 'modifier_type' => 'percent', 'modifier_value' => -20]);
        $this->assertSame(-20.0, $pct->fresh()->modifier_value);
        $this->assertSame(-20.0, $pct->fresh()->toArray()['modifier_value']);
        $fixed = RatePlan::create(['store_id' => $store->id, 'name' => 'BB', 'modifier_type' => 'fixed', 'modifier_value' => 20.5]);
        $this->assertSame(20.5, $fixed->fresh()->modifier_value);
        $this->assertSame(20.5, $fixed->fresh()->toArray()['modifier_value']);
        $this->assertSame([96.4, 140.2, 96.4], array_column(RateService::quote($type, $pct->fresh(), '2026-09-01', '2026-09-04')['nights'], 'price'));
        $this->assertSame([141.0, 195.75], array_column(RateService::quote($type, $fixed->fresh(), '2026-09-01', '2026-09-03')['nights'], 'price'));
        // Switching a fixed plan to a percent one (and back) keeps the number the owner typed.
        $fixed->fresh()->update(['modifier_type' => 'percent', 'modifier_value' => 10]);
        $this->assertSame(['percent', 10.0], [$fixed->fresh()->modifier_type, $fixed->fresh()->modifier_value]);
        $fixed->fresh()->update(['modifier_type' => 'fixed', 'modifier_value' => -5.25]);
        $this->assertSame(['fixed', -5.25], [$fixed->fresh()->modifier_type, $fixed->fresh()->modifier_value]);
        $this->assertSame(115.25, RateService::quote($type, $fixed->fresh(), '2026-09-01', '2026-09-02')['total']);

        $svc = HotelExtraService::create(['store_id' => $store->id, 'name' => 'Breakfast', 'price' => 12.5, 'tax_rate' => 5]);
        $this->assertSame([12.5, 5.0], [$svc->fresh()->price, $svc->fresh()->tax_rate]);
        $this->assertSame(12.5, $svc->fresh()->toArray()['price']);

        $booking = BookingService::create($store->id, [
            'guest_name' => 'G', 'room_type_id' => $type->id, 'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ]);
        $this->assertSame([295.75, 'USD'], [$booking->fresh()->quoted_total, $booking->fresh()->currency]);
        $this->assertSame(295.75, $booking->fresh()->toArray()['quoted_total']);

        $event = HotelEvent::create(['store_id' => $store->id, 'title' => 'Wedding']);
        $line = HotelEventItem::create(['store_id' => $store->id, 'event_id' => $event->id, 'description' => 'Hall', 'qty' => 2, 'unit_price' => 150.75]);
        $this->assertSame(150.75, $line->fresh()->unit_price);
        $this->assertSame(301.5, $line->fresh()->lineTotal());
        $this->assertSame(150.75, $line->fresh()->toArray()['unit_price']);
    }

    public function test_three_decimal_hotel_keeps_fils(): void
    {
        [, $store] = $this->store('OMR');
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 35.125]);
        $this->assertSame(35.125, $type->fresh()->base_rate);
        $fixed = RatePlan::create(['store_id' => $store->id, 'name' => 'BB', 'modifier_type' => 'fixed', 'modifier_value' => 1.375]);
        $q = RateService::quote($type, $fixed->fresh(), '2026-09-01', '2026-09-03');
        $this->assertSame([36.5, 36.5], array_column($q['nights'], 'price'));
        $this->assertSame([73.0, 'OMR'], [$q['total'], $q['currency']]);
        $booking = BookingService::create($store->id, [
            'guest_name' => 'G', 'room_type_id' => $type->id, 'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ]);
        $this->assertSame([70.25, 'OMR'], [$booking->fresh()->quoted_total, $booking->fresh()->currency]);
        $svc = HotelExtraService::create(['store_id' => $store->id, 'name' => 'Spa', 'price' => 4.125]);
        $this->assertSame(4.125, $svc->fresh()->price);
    }

    public function test_staff_wages_and_payroll_lines(): void
    {
        [$owner, $store] = $this->store();
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Sam', 'email' => 's'.Str::random(5).'@x.co',
            'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => 15, 'hourly_rate' => 11.5,
        ]);
        $this->assertSame(['15.00', '11.50'], [$staff->fresh()->base_wage, $staff->fresh()->hourly_rate]);
        $this->assertSame(['15.00', '11.50'], [$staff->fresh()->toArray()['base_wage'], $staff->fresh()->toArray()['hourly_rate']]);
        $bare = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Ann', 'email' => 'a'.Str::random(5).'@x.co',
            'password' => Hash::make('secret1'), 'role' => 'cashier', 'active' => true,
        ]);
        $this->assertSame([null, null], [$bare->fresh()->base_wage, $bare->fresh()->hourly_rate]);
        $roleRate = StaffRoleRate::create(['staff_id' => $staff->id, 'role' => 'waiter', 'hourly_rate' => 12.5]);
        $this->assertSame('12.50', $roleRate->fresh()->hourly_rate);
        $this->assertSame('12.50', $roleRate->fresh()->toArray()['hourly_rate']);

        $start = now()->startOfWeek();
        StaffTimeClock::create(['staff_id' => $staff->id, 'store_id' => $store->id, 'clock_in_at' => $start->copy()->addDay()->setTime(8, 0), 'clock_out_at' => $start->copy()->addDay()->setTime(18, 0), 'method' => 'manual']);
        $run = (new PayrollEngine)->build($store->id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());
        $item = $run->items->firstWhere('staff_id', $staff->id);
        $this->assertSame(['12.50', '125.00', '0.00', '0.00', '0.00', '0.00', '125.00'], [
            $item->rate, $item->base_pay, $item->overtime_pay, $item->tips, $item->bonuses, $item->deductions, $item->net,
        ]);
        $this->assertSame('125.00', $item->toArray()['net']);
        $this->assertSame('125.00', $run->fresh()->total_net);
        $this->assertSame('125.00', $run->fresh()->toArray()['total_net']);

        $engine = new PayrollEngine;
        $engine->adjustItem($item->fresh(), 'Bonus', 10.25);
        $engine->adjustItem($item->fresh(), 'Advance', -5);
        $item = $item->fresh();
        $this->assertSame(['10.25', '5.00', '130.25'], [$item->bonuses, $item->deductions, $item->net]);
        $this->assertSame('130.25', $run->fresh()->total_net);

        // No role rate for the role → falls back to the staff member's base wage.
        $roleRate->delete();
        $run2 = (new PayrollEngine)->build($store->id, $start->copy()->addWeeks(-1)->toDateString(), $start->copy()->addDays(6)->toDateString());
        $this->assertSame('15.00', $run2->items->firstWhere('staff_id', $staff->id)->rate);
        $this->assertSame('150.00', $run2->items->firstWhere('staff_id', $staff->id)->base_pay);
    }

    public function test_three_decimal_payroll_keeps_baisa(): void
    {
        [$owner, $store] = $this->store('OMR');
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Sam', 'email' => 's'.Str::random(5).'@x.co',
            'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => 2.125,
        ]);
        $this->assertSame('2.125', $staff->fresh()->base_wage);
        $start = now()->startOfWeek();
        StaffTimeClock::create(['staff_id' => $staff->id, 'store_id' => $store->id, 'clock_in_at' => $start->copy()->addDay()->setTime(8, 0), 'clock_out_at' => $start->copy()->addDay()->setTime(18, 0), 'method' => 'manual']);
        $run = (new PayrollEngine)->build($store->id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());
        $item = $run->items->first();
        // On the decimal schema the engine read the base wage with value(), which went through the old StoreMoney cast
        // WITHOUT the row's store_id → 2 decimals: a 2.125 OMR wage paid 2.13/h ('2.130', '21.300'). P1-05 slice 8b
        // reads the exact stored wage (behaviour change, logged): the 3-decimal wage is paid as entered.
        $this->assertSame(['2.125', '21.250', '21.250'], [$item->rate, $item->base_pay, $item->net]);
        $this->assertSame('21.250', $run->fresh()->total_net);
    }
}
