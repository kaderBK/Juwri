<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Mutation;

use App\Models\StoreOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Data\ShippingOption;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Ordering\Models\ShippingZone;
use Juwri\Modules\Ordering\Services\Shipping\ShippingQuotes;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P3-08 — mutation killers for {@see ShippingQuotes} (zero coverage before this
 * file). Every assertion pins an EXACT value: the zone picked, the offered
 * methods in order, the fee in minor units, the delivery window (dates and
 * cut-off) off a fixed now, and the static minor() conversion.
 *
 * One fixed now is used throughout the date tests:
 *   $now = 2026-10-14 10:00:00 UTC — a Wednesday (dayOfWeek 3).
 */
class ShippingQuotesMutationTest extends TestCase
{
    use RefreshDatabase;

    /** A Wednesday 10:00 — the fixed now the date expectations are hand-computed from. */
    private const NOW = '2026-10-14 10:00:00';

    private function now(string $at = self::NOW): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'UTC');
    }

    /** A store whose currency is set through store_options (store_currency() reads it). */
    private function store(string $currency = 'SAR'): Store
    {
        $store = Store::create([
            'user_id' => User::factory()->create(['status' => 1])->id,
            'slug' => 'ship-'.substr(uniqid(), -8),
            'color' => '#111',
            'title' => 'Ship',
            'sub_title' => 'x',
            'main_image' => 'a.png',
            'cover_image' => 'a.png',
            'address' => '1 St',
            'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    /** @return array<string, string> an address the zones below (countries ['SA']) cover */
    private function address(): array
    {
        return ['country' => 'SA', 'city' => 'Riyadh'];
    }

    /** @param  array<string, mixed>  $attrs */
    private function zone(Store $store, array $attrs = []): ShippingZone
    {
        return ShippingZone::create(array_merge([
            'store_id' => $store->id,
            'code' => 'z-'.substr(uniqid(), -8),
            'name' => 'Zone',
            'countries' => ['SA'],
            'active' => true,
            'position' => 0,
        ], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    private function method(Store $store, array $attrs = []): ShippingMethod
    {
        return ShippingMethod::create(array_merge([
            'store_id' => $store->id,
            'kind' => 'standard',
            'name' => 'Method',
            'fee_minor' => 1500,
            'free_over_minor' => null,
            'currency_code' => 'SAR',
            'min_days' => 0,
            'max_days' => 0,
            'position' => 0,
            'active' => true,
        ], $attrs));
    }

    /**
     * The single offered option — a test with more (or fewer) than one fails.
     *
     * @param  array<string, mixed>  $address
     */
    private function option(Store $store, array $address, int $subtotal = 0, ?CarbonImmutable $now = null): ShippingOption
    {
        $options = (new ShippingQuotes)->options($store, $address, $subtotal, $now);
        $this->assertCount(1, $options);

        return $options[0];
    }

    /**
     * @param  list<ShippingOption>  $options
     * @return list<string> the public ids of the options, in offer order
     */
    private function publicIds(array $options): array
    {
        return array_map(fn (ShippingOption $o) => $o->publicId(), $options);
    }

    public function test_zone_for_lowest_position_wins(): void
    {
        $store = $this->store();
        $far = $this->zone($store, ['code' => 'far', 'position' => 5]);
        $near = $this->zone($store, ['code' => 'near', 'position' => 1]);

        $zone = (new ShippingQuotes)->zoneFor($store, $this->address());

        // Both cover the address; the lower position wins even though its id is higher.
        $this->assertSame('near', (string) $zone->code);
        $this->assertNotSame((int) $far->id, (int) $zone->id);
    }

    public function test_zone_for_tie_breaks_by_lowest_id(): void
    {
        $store = $this->store();
        $earlier = $this->zone($store, ['code' => 'earlier', 'position' => 0]);
        $later = $this->zone($store, ['code' => 'later', 'position' => 0]);

        $zone = (new ShippingQuotes)->zoneFor($store, $this->address());

        // Same position: the first-created zone (lowest id) wins the tie.
        $this->assertSame('earlier', (string) $zone->code);
        $this->assertSame((int) $earlier->id, (int) $zone->id);
        $this->assertNotSame((int) $later->id, (int) $zone->id);
    }

    public function test_zone_for_inactive_zone_is_skipped(): void
    {
        $store = $this->store();
        $this->zone($store, ['code' => 'sleeping', 'active' => false, 'position' => 0]);
        $awake = $this->zone($store, ['code' => 'awake', 'active' => true, 'position' => 5]);

        $zone = (new ShippingQuotes)->zoneFor($store, $this->address());

        $this->assertSame('awake', (string) $zone->code);
        $this->assertSame((int) $awake->id, (int) $zone->id);
    }

    public function test_zone_for_only_inactive_zones_returns_null(): void
    {
        $store = $this->store();
        $this->zone($store, ['code' => 'sleeping', 'active' => false, 'position' => 0]);

        $this->assertNull((new ShippingQuotes)->zoneFor($store, $this->address()));
    }

    public function test_zone_for_other_stores_zone_is_ignored(): void
    {
        $thisStore = $this->store();
        $other = $this->store();
        $mine = $this->zone($thisStore, ['code' => 'mine', 'position' => 0]);
        $this->zone($other, ['code' => 'theirs', 'position' => 0]);

        $zone = (new ShippingQuotes)->zoneFor($thisStore, $this->address());

        $this->assertSame((int) $mine->id, (int) $zone->id);
        $this->assertSame('mine', (string) $zone->code);
    }

    public function test_zone_for_no_match_returns_null(): void
    {
        $store = $this->store();
        $this->zone($store, ['countries' => ['AE']]);

        $this->assertNull((new ShippingQuotes)->zoneFor($store, ['country' => 'SA']));
        $this->assertNull((new ShippingQuotes)->zoneFor($store, []));
    }

    public function test_options_empty_address_returns_only_pickup(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['name' => 'Std', 'shipping_zone_id' => $zone->id]);
        $pickup = $this->method($store, ['kind' => 'pickup_in_store', 'name' => 'Pk']);

        $options = (new ShippingQuotes)->options($store, [], 0, $this->now());

        $this->assertSame([(string) $pickup->public_id], $this->publicIds($options));
    }

    public function test_options_unmatched_address_returns_only_pickup(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['name' => 'Std', 'shipping_zone_id' => $zone->id]);
        $pickup = $this->method($store, ['kind' => 'pickup_in_store', 'name' => 'Pk']);

        $options = (new ShippingQuotes)->options($store, ['country' => 'US'], 0, $this->now());

        $this->assertSame([(string) $pickup->public_id], $this->publicIds($options));
    }

    public function test_options_matched_orders_zone_methods_then_pickup_last(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $standard = $this->method($store, ['name' => 'Std', 'shipping_zone_id' => $zone->id, 'position' => 20, 'min_days' => 2, 'max_days' => 4]);
        $express = $this->method($store, ['name' => 'Exp', 'shipping_zone_id' => $zone->id, 'position' => 10, 'min_days' => 1, 'max_days' => 1]);
        $pickup = $this->method($store, ['kind' => 'pickup_in_store', 'name' => 'Pk', 'position' => 0]);

        $options = (new ShippingQuotes)->options($store, $this->address(), 0, $this->now());

        // Zone methods first (by position, then id — Std position 20 after Exp 10),
        // pickup last even with the lowest position.
        $this->assertSame([
            (string) $express->public_id,
            (string) $standard->public_id,
            (string) $pickup->public_id,
        ], $this->publicIds($options));
    }

    public function test_options_inactive_method_excluded(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $active = $this->method($store, ['name' => 'Live', 'shipping_zone_id' => $zone->id, 'position' => 10]);
        $this->method($store, ['name' => 'Dead', 'shipping_zone_id' => $zone->id, 'active' => false, 'position' => 0]);
        $pickup = $this->method($store, ['kind' => 'pickup_in_store', 'name' => 'Pk', 'position' => 20]);

        $options = (new ShippingQuotes)->options($store, $this->address(), 0, $this->now());

        $this->assertSame([(string) $active->public_id, (string) $pickup->public_id], $this->publicIds($options));
    }

    public function test_options_other_store_method_excluded(): void
    {
        $thisStore = $this->store();
        $other = $this->store();
        $mine = $this->zone($thisStore, ['code' => 'mine']);
        $theirs = $this->zone($other, ['code' => 'theirs']);
        $mineMethod = $this->method($thisStore, ['name' => 'Mine', 'shipping_zone_id' => $mine->id, 'position' => 1]);
        $this->method($other, ['name' => 'Theirs', 'shipping_zone_id' => $theirs->id, 'position' => 0]);

        $options = (new ShippingQuotes)->options($thisStore, $this->address(), 0, $this->now());

        $this->assertSame([(string) $mineMethod->public_id], $this->publicIds($options));
    }

    public function test_options_currency_mismatch_excluded_and_case_insensitive_offered(): void
    {
        $store = $this->store('SAR');
        $zone = $this->zone($store);
        $sar = $this->method($store, ['name' => 'Lowercase', 'shipping_zone_id' => $zone->id, 'currency_code' => 'sar', 'position' => 1]);
        $this->method($store, ['name' => 'Usd', 'shipping_zone_id' => $zone->id, 'currency_code' => 'USD', 'position' => 0]);

        $options = (new ShippingQuotes)->options($store, $this->address(), 0, $this->now());

        // 'sar' uppercases to the store currency 'SAR' and is offered; 'USD' never is.
        $this->assertSame([(string) $sar->public_id], $this->publicIds($options));
    }

    /** @return array<string, array{0: int, 1: int|null, 2: int, 3: int, 4: bool}> [fee_minor, free_over_minor, subtotal, expectedFee, expectedFree] */
    public static function feeProvider(): array
    {
        return [
            'below threshold charges in full' => [1500, 20000, 19999, 1500, false],
            'one minor unit below threshold still full' => [1500, 20000, 19998, 1500, false],
            'exactly at threshold is free' => [1500, 20000, 20000, 0, true],
            'past threshold is free' => [1500, 20000, 30000, 0, true],
            'null threshold never free' => [1500, null, 9999999, 1500, false],
            'negative fee clamps to zero' => [-500, null, 0, 0, false],
            'zero fee stays zero' => [0, null, 0, 0, false],
        ];
    }

    #[DataProvider('feeProvider')]
    public function test_fee_is_the_exact_minor(int $fee, ?int $freeOver, int $subtotal, int $expectedFee, bool $expectedFree): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'fee_minor' => $fee, 'free_over_minor' => $freeOver]);

        $option = $this->option($store, $this->address(), $subtotal, $this->now());

        $this->assertSame($expectedFree, $option->freeByThreshold);
        $this->assertSame($expectedFee, $option->feeMinor);
    }

    public function test_pickup_null_branch_is_offered_with_null_zone(): void
    {
        $store = $this->store();
        $pickup = $this->method($store, ['kind' => 'pickup_in_store', 'name' => 'Pk']);

        $option = $this->option($store, [], 0, $this->now());

        $this->assertSame((string) $pickup->public_id, $option->publicId());
        $this->assertNull($option->zone);
    }

    public function test_pickup_active_branch_of_the_store_is_offered(): void
    {
        $store = $this->store();
        $branch = Branch::where('store_id', $store->id)->firstOrFail();
        $this->assertTrue((bool) $branch->active);
        $pickup = $this->method($store, ['kind' => 'pickup_in_store', 'name' => 'Pk', 'pickup_branch_id' => (int) $branch->id]);

        $option = $this->option($store, [], 0, $this->now());

        $this->assertSame((string) $pickup->public_id, $option->publicId());
        $this->assertNull($option->zone);
    }

    public function test_pickup_branch_of_another_store_is_not_offered(): void
    {
        $thisStore = $this->store();
        $other = $this->store();
        $otherBranch = Branch::where('store_id', $other->id)->firstOrFail();
        $this->method($thisStore, ['kind' => 'pickup_in_store', 'name' => 'Pk', 'pickup_branch_id' => (int) $otherBranch->id]);

        $options = (new ShippingQuotes)->options($thisStore, [], 0, $this->now());

        $this->assertSame([], $options);
    }

    public function test_pickup_inactive_branch_is_not_offered(): void
    {
        $store = $this->store();
        $shut = Branch::create(['store_id' => $store->id, 'name' => 'Shut', 'active' => false]);
        $this->assertFalse((bool) $shut->active);
        $this->method($store, ['kind' => 'pickup_in_store', 'name' => 'Pk', 'pickup_branch_id' => (int) $shut->id]);

        $options = (new ShippingQuotes)->options($store, [], 0, $this->now());

        $this->assertSame([], $options);
    }

    public function test_pickup_option_keeps_null_zone_even_when_the_address_matched(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['kind' => 'standard', 'name' => 'Std', 'shipping_zone_id' => $zone->id]);
        $pickup = $this->method($store, ['kind' => 'pickup_in_store', 'name' => 'Pk']);

        $options = (new ShippingQuotes)->options($store, $this->address(), 0, $this->now());

        $this->assertCount(2, $options);
        [$std, $pk] = $options;
        $this->assertSame((int) $zone->id, (int) $std->zone->id);
        $this->assertNull($pk->zone);
        $this->assertSame((string) $pickup->public_id, $pk->publicId());
    }

    public function test_dates_min_max_offsets_are_the_exact_window(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'min_days' => 2, 'max_days' => 4]);

        $option = $this->option($store, $this->address(), 0, $this->now());

        // Wed 2026-10-14 + 2 = Fri 16, + 4 = Sun 18.
        $this->assertSame('2026-10-16', $option->earliest->format('Y-m-d'));
        $this->assertSame('2026-10-18', $option->latest->format('Y-m-d'));
        $this->assertNull($option->cutoffAt);
    }

    public function test_dates_max_days_below_min_days_latest_is_the_min_day(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'min_days' => 3, 'max_days' => 1]);

        $option = $this->option($store, $this->address(), 0, $this->now());

        // Late never before early: both Wed + 3 = Sat 2026-10-17.
        $this->assertSame('2026-10-17', $option->earliest->format('Y-m-d'));
        $this->assertSame('2026-10-17', $option->latest->format('Y-m-d'));
    }

    public function test_dates_negative_min_days_is_treated_as_zero(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('unsigned column on MySQL');
        }

        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'min_days' => -2, 'max_days' => 2]);

        $option = $this->option($store, $this->address(), 0, $this->now());

        $this->assertSame('2026-10-14', $option->earliest->format('Y-m-d'));
        $this->assertSame('2026-10-16', $option->latest->format('Y-m-d'));
    }

    public function test_dates_cutoff_before_the_time_counts_from_today(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'cutoff_time' => '21:00']);

        $option = $this->option($store, $this->address(), 0, $this->now('2026-10-14 20:59:00'));

        $this->assertSame('2026-10-14', $option->earliest->format('Y-m-d'));
        $this->assertSame('2026-10-14', $option->latest->format('Y-m-d'));
        $this->assertSame('2026-10-14 21:00:00', $option->cutoffAt->format('Y-m-d H:i:s'));
    }

    public function test_dates_cutoff_exactly_at_the_time_counts_from_tomorrow(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'cutoff_time' => '21:00']);

        $option = $this->option($store, $this->address(), 0, $this->now('2026-10-14 21:00:00'));

        $this->assertSame('2026-10-15', $option->earliest->format('Y-m-d'));
        $this->assertSame('2026-10-15', $option->latest->format('Y-m-d'));
        $this->assertSame('2026-10-15 21:00:00', $option->cutoffAt->format('Y-m-d H:i:s'));
    }

    public function test_dates_cutoff_0000_is_valid_and_counts_from_tomorrow(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'cutoff_time' => '00:00']);

        $option = $this->option($store, $this->address(), 0, $this->now());

        // 10:00 is already past tonight's 00:00 → starts tomorrow, cut-off tomorrow 00:00.
        $this->assertSame('2026-10-15', $option->earliest->format('Y-m-d'));
        $this->assertSame('2026-10-15 00:00:00', $option->cutoffAt->format('Y-m-d H:i:s'));
    }

    public function test_dates_cutoff_2359_is_valid_and_counts_from_tomorrow(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'cutoff_time' => '23:59']);

        $option = $this->option($store, $this->address(), 0, $this->now('2026-10-14 23:59:00'));

        $this->assertSame('2026-10-15', $option->earliest->format('Y-m-d'));
        $this->assertSame('2026-10-15 23:59:00', $option->cutoffAt->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array{0: string|null}> */
    public static function invalidCutoffProvider(): array
    {
        return [
            '24:00' => ['24:00'],
            '9:00' => ['9:00'],
            '21:60' => ['21:60'],
            'abc' => ['abc'],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidCutoffProvider')]
    public function test_dates_malformed_cutoff_is_ignored(?string $cutoff): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'cutoff_time' => $cutoff]);

        $option = $this->option($store, $this->address(), 0, $this->now());

        $this->assertNull($option->cutoffAt);
        $this->assertSame('2026-10-14', $option->earliest->format('Y-m-d'));
        $this->assertSame('2026-10-14', $option->latest->format('Y-m-d'));
    }

    /** @return array<string, array{0: array<int|string>|null, 1: string, 2: string, 3: string}> [delivery_days, now, earliest, latest] */
    public static function deliveryDaysProvider(): array
    {
        return [
            'empty array = every day' => [[], self::NOW, '2026-10-14', '2026-10-14'],
            'null = every day' => [null, self::NOW, '2026-10-14', '2026-10-14'],
            'monday moves a wednesday to the next monday' => [[1], self::NOW, '2026-10-19', '2026-10-19'],
            'same-day monday stays' => [[1], '2026-10-12 10:00:00', '2026-10-12', '2026-10-12'],
            'out-of-range values are ignored' => [[7, -1], self::NOW, '2026-10-14', '2026-10-14'],
            'string numerals are accepted' => [['1'], self::NOW, '2026-10-19', '2026-10-19'],
            'duplicates are harmless' => [[2, 2], self::NOW, '2026-10-20', '2026-10-20'],
            'a six-day-ahead weekday needs the full search' => [[2], self::NOW, '2026-10-20', '2026-10-20'],
            'sunday 0 is honoured' => [[0], self::NOW, '2026-10-18', '2026-10-18'],
            'a mixed set keeps the valid weekdays' => [[1, 8, '3'], self::NOW, '2026-10-14', '2026-10-14'],
        ];
    }

    /**
     * @param  array<int|string>|null  $days
     */
    #[DataProvider('deliveryDaysProvider')]
    public function test_delivery_days_pin_the_exact_window(?array $days, string $at, string $expectedEarliest, string $expectedLatest): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id, 'delivery_days' => $days]);

        $option = $this->option($store, $this->address(), 0, $this->now($at));

        $this->assertSame($expectedEarliest, $option->earliest->format('Y-m-d'));
        $this->assertSame($expectedLatest, $option->latest->format('Y-m-d'));
        $this->assertNull($option->cutoffAt);
    }

    public function test_find_returns_the_matching_option(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $method = $this->method($store, ['name' => 'Std', 'shipping_zone_id' => $zone->id, 'fee_minor' => 750]);

        $option = (new ShippingQuotes)->find($store, (string) $method->public_id, $this->address(), 0, $this->now());

        $this->assertNotNull($option);
        $this->assertSame((string) $method->public_id, $option->publicId());
        $this->assertSame(750, $option->feeMinor);
    }

    public function test_find_unknown_public_id_returns_null(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $this->method($store, ['shipping_zone_id' => $zone->id]);

        $this->assertNull((new ShippingQuotes)->find($store, 'unknown-public-id', $this->address(), 0, $this->now()));
    }

    public function test_find_inactive_method_returns_null(): void
    {
        $store = $this->store();
        $zone = $this->zone($store);
        $live = $this->method($store, ['name' => 'Live', 'shipping_zone_id' => $zone->id, 'position' => 1]);
        $dead = $this->method($store, ['name' => 'Dead', 'shipping_zone_id' => $zone->id, 'active' => false, 'position' => 0]);

        $quotes = new ShippingQuotes;
        $this->assertNotNull($quotes->find($store, (string) $live->public_id, $this->address(), 0, $this->now()));
        $this->assertNull($quotes->find($store, (string) $dead->public_id, $this->address(), 0, $this->now()));
    }

    public function test_find_other_currency_method_returns_null(): void
    {
        $store = $this->store('SAR');
        $zone = $this->zone($store);
        $usd = $this->method($store, ['name' => 'Usd', 'shipping_zone_id' => $zone->id, 'currency_code' => 'USD']);

        $this->assertNull((new ShippingQuotes)->find($store, (string) $usd->public_id, $this->address(), 0, $this->now()));
    }

    public function test_minor_sar_two_decimals(): void
    {
        $store = $this->store('SAR');

        $this->assertSame(1234, ShippingQuotes::minor($store, 12.34));
    }

    public function test_minor_kwd_three_decimals(): void
    {
        $store = $this->store('KWD');

        $this->assertSame(1500, ShippingQuotes::minor($store, 1.5));
    }

    public function test_minor_jpy_zero_decimals(): void
    {
        $store = $this->store('JPY');

        $this->assertSame(150, ShippingQuotes::minor($store, 150.0));
    }

    public function test_minor_lowercase_store_currency_still_works(): void
    {
        $store = $this->store('sar');

        $this->assertSame(1234, ShippingQuotes::minor($store, 12.34));
    }
}
