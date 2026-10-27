<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P6-352: the customer list must page stably on every database and rank top
 * spenders in a currency-safe way.
 *
 * The bug: only the no-segment branch ordered the query — new_this_month, lapsed
 * and consented paginated with no ORDER BY, so pages repeated/skipped rows on
 * MySQL/PostgreSQL. top_spenders ordered by total_spent_minor alone, mixing
 * currencies in the all-stores view and leaving ties unordered. The fix orders
 * every segment and ends every ORDER BY with `id desc`, and ranks top spenders by
 * the currency-neutral orders_count when the selected stores span more than one
 * currency.
 */
class CustomerListOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function ownerStore(?string $currency = null): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = $this->store($owner);
        if ($currency !== null) {
            StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        }

        return [$owner, $store];
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function customer(Store $store, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'store_id' => $store->id,
            'name' => 'Diner '.Str::random(6),
            'phone' => UniquePhone::next('55-'),
        ], $attributes));
    }

    /**
     * Page through the first three pages of the list and return every customer id
     * in page order.
     *
     * @param  array<string, mixed>  $params
     * @return list<int>
     */
    private function listIds(User $owner, array $params = []): array
    {
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $paginator = $this->actingAs($owner)
                ->get(route('customers.index', array_merge(['page' => $page], $params)))
                ->assertOk()
                ->viewData('customers');
            foreach ($paginator->items() as $customer) {
                $ids[] = (int) $customer->id;
            }
        }

        return $ids;
    }

    public function test_new_this_month_pages_stably_ordered_by_created_at_then_id(): void
    {
        [$owner, $store] = $this->ownerStore();
        $base = now()->startOfMonth()->addDay();
        $created = [];
        for ($i = 0; $i < 45; $i++) {
            $at = $base->copy()->addMinutes($i % 5);
            $customer = $this->customer($store);
            $customer->forceFill(['created_at' => $at])->save();
            $created[$customer->id] = $at->format('Y-m-d H:i:s');
        }

        $ids = $this->listIds($owner, ['segment' => 'new_this_month']);

        $this->assertCount(45, $ids);
        $this->assertCount(45, array_unique($ids), 'no customer may repeat across pages');
        $this->assertEqualsCanonicalizing(array_keys($created), $ids);

        $expected = array_keys($created);
        usort($expected, fn ($a, $b) => strcmp($created[$b], $created[$a]) ?: ($b <=> $a));
        $this->assertSame($expected, $ids);
    }

    public function test_lapsed_pages_stably_ordered_by_last_order_then_id(): void
    {
        [$owner, $store] = $this->ownerStore();
        $base = now()->subMonths(6);
        $last = [];
        for ($i = 0; $i < 45; $i++) {
            $at = $base->copy()->addHours($i % 5);
            $customer = $this->customer($store, ['last_order_at' => $at]);
            $last[$customer->id] = $at->format('Y-m-d H:i:s');
        }

        $ids = $this->listIds($owner, ['segment' => 'lapsed']);

        $this->assertCount(45, $ids);
        $this->assertCount(45, array_unique($ids), 'no customer may repeat across pages');
        $this->assertEqualsCanonicalizing(array_keys($last), $ids);

        $expected = array_keys($last);
        usort($expected, fn ($a, $b) => strcmp($last[$b], $last[$a]) ?: ($b <=> $a));
        $this->assertSame($expected, $ids);
    }

    public function test_consented_pages_stably_ordered_by_id_desc(): void
    {
        [$owner, $store] = $this->ownerStore();
        $created = [];
        for ($i = 0; $i < 45; $i++) {
            $created[] = $this->customer($store, ['marketing_consent' => 1])->id;
        }

        $ids = $this->listIds($owner, ['segment' => 'consented']);

        $this->assertCount(45, $ids);
        $this->assertCount(45, array_unique($ids), 'no customer may repeat across pages');
        $this->assertEqualsCanonicalizing($created, $ids);

        $expected = $created;
        rsort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_top_spenders_single_store_pages_stably_ordered_by_spend_then_id(): void
    {
        [$owner, $store] = $this->ownerStore();
        $spend = [];
        for ($i = 0; $i < 45; $i++) {
            $minor = ($i % 5) * 1000;
            $customer = $this->customer($store);
            $customer->forceFill(['total_spent_minor' => $minor])->save();
            $spend[$customer->id] = $minor;
        }

        $ids = $this->listIds($owner, ['segment' => 'top_spenders']);

        $this->assertCount(45, $ids);
        $this->assertCount(45, array_unique($ids), 'no customer may repeat across pages');
        $this->assertEqualsCanonicalizing(array_keys($spend), $ids);

        $expected = array_keys($spend);
        usort($expected, fn ($a, $b) => ($spend[$b] <=> $spend[$a]) ?: ($b <=> $a));
        $this->assertSame($expected, $ids);
    }

    public function test_top_spenders_equal_spend_breaks_ties_by_id_desc(): void
    {
        [$owner, $store] = $this->ownerStore();
        $first = $this->customer($store);
        $first->forceFill(['total_spent_minor' => 5000])->save();
        $second = $this->customer($store);
        $second->forceFill(['total_spent_minor' => 5000])->save();

        $page = $this->actingAs($owner)
            ->get(route('customers.index', ['segment' => 'top_spenders']))
            ->assertOk()
            ->viewData('customers');

        $this->assertSame([$second->id, $first->id], collect($page->items())->pluck('id')->all());
    }

    public function test_top_spenders_across_mixed_currencies_order_by_orders_count(): void
    {
        [$owner, $sar] = $this->ownerStore('SAR');
        $usd = $this->store($owner);
        StoreOption::updateStoreOption($usd->id, 'currency_code', 'USD');

        $bigSpender = $this->customer($sar);
        $bigSpender->forceFill(['total_spent_minor' => 999999, 'orders_count' => 1])->save();

        $frequent = $this->customer($usd);
        $frequent->forceFill(['total_spent_minor' => 100, 'orders_count' => 999])->save();

        $page = $this->actingAs($owner)
            ->get(route('customers.index', ['scope' => 'all', 'segment' => 'top_spenders']))
            ->assertOk()
            ->viewData('customers');

        $this->assertSame([$frequent->id, $bigSpender->id], collect($page->items())->pluck('id')->all());
    }

    public function test_export_rows_match_the_list_page_order(): void
    {
        [$owner, $store] = $this->ownerStore();
        for ($i = 0; $i < 45; $i++) {
            $customer = $this->customer($store);
            $customer->forceFill(['total_spent_minor' => ($i % 3) * 100])->save();
        }

        $ids = $this->listIds($owner, ['segment' => 'top_spenders']);

        $csv = $this->actingAs($owner)
            ->get(route('customers.export', ['segment' => 'top_spenders']))
            ->assertOk()
            ->streamedContent();

        $rows = array_values(array_filter(explode("\n", $csv)));
        array_shift($rows); // header
        $exportIds = array_map(fn ($line) => (int) str_getcsv($line)[0], $rows);

        $this->assertSame($ids, $exportIds);
    }
}
