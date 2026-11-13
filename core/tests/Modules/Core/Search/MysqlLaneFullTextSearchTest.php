<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Search;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-17 part 2c: the MySQL full-text branch of the Scout database search.
 *
 * InnoDB applies full-text index changes at COMMIT, so MATCH ... AGAINST cannot see rows inserted inside
 * RefreshDatabase's per-test transaction. This class turns that transaction off on MySQL and deletes exactly the rows
 * it made (pattern: MysqlLanePosRefundLockTest). MySQL only (TestCase's #[Group('mysql')] guard).
 */
#[Group('mysql')]
class MysqlLaneFullTextSearchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string|null> */
    protected function connectionsToTransact(): array
    {
        return $this->usingInMemoryDatabase() ? [config('database.default')] : [];
    }

    /** @var array{plan: list<object>, user: list<int>, store: list<int>} */
    private array $created = ['plan' => [], 'user' => [], 'store' => []];

    protected function tearDown(): void
    {
        $c = $this->created;
        $this->created = ['plan' => [], 'user' => [], 'store' => []];

        try {
            Schema::disableForeignKeyConstraints();
            if ($c['store'] !== []) {
                DB::table('items')->whereIn('store_id', $c['store'])->delete();
                DB::table('customers')->whereIn('store_id', $c['store'])->delete();
                if (Schema::hasTable('branches')) {
                    DB::table('branches')->whereIn('store_id', $c['store'])->delete();
                }
                DB::table('stores')->whereIn('id', $c['store'])->delete();
            }
            if ($c['user'] !== []) {
                DB::table('accounts')->whereIn('owner_user_id', $c['user'])->delete();
                DB::table('users')->whereIn('id', $c['user'])->delete();
            }
            TestPlans::delete(...$c['plan']);
        } finally {
            Schema::enableForeignKeyConstraints();
            parent::tearDown();
        }
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $this->created['plan'][] = $plan;
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->created['user'][] = $owner->id;
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'ft-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        $this->created['store'][] = $store->id;

        return $store;
    }

    private function item(Store $store, string $name): Menu
    {
        return Menu::create(['store_id' => $store->id, 'name' => $name, 'description' => 'd', 'price' => 5, 'active' => '1']);
    }

    /** @return list<int> */
    private function ids(iterable $models): array
    {
        return collect($models)->pluck('id')->sort()->values()->all();
    }

    public function test_words_in_any_order_are_found_in_this_store_only(): void
    {
        $a = $this->store();
        $b = $this->store();
        $burger = $this->item($a, 'Chicken Burger');
        $arabic = $this->item($a, 'شاورما دجاج حار');
        $this->item($a, 'Beef Burger');
        $this->item($b, 'Chicken Burger');
        $maya = Customer::create(['store_id' => $a->id, 'name' => 'Maya Haddad']);
        Customer::create(['store_id' => $b->id, 'name' => 'Maya Haddad']);

        $this->assertSame([$burger->id], $this->ids(Menu::searchInStores('burger chicken', $a->id)->get()));
        $this->assertSame([$arabic->id], $this->ids(Menu::searchInStores('دجاج شاورما', $a->id)->get()));
        $this->assertSame([$maya->id], $this->ids(Customer::searchInStores('haddad maya', $a->id)->get()));
        $this->assertSame([$maya->id], $this->ids(Customer::whereIn('store_id', [$a->id])->whereSearch('haddad maya')->get()));
    }

    public function test_like_matches_sort_before_full_text_only_matches(): void
    {
        $store = $this->store();
        $this->item($store, 'Burger with Chicken');
        $like = $this->item($store, 'Chicken Burger Deluxe');

        $this->assertSame([$like->id], $this->ids(Menu::searchInStores('chicken burger', $store->id)->take(1)->get()));
        $this->assertCount(2, Menu::searchInStores('chicken burger', $store->id)->get());
    }

    public function test_boolean_operators_in_the_term_do_not_break_the_query(): void
    {
        $store = $this->store();
        $hit = $this->item($store, 'Fish (grilled) "special"');

        $this->assertSame([$hit->id], $this->ids(Menu::searchInStores('(grilled) "special"', $store->id)->get()));
        $this->assertSame([], $this->ids(Menu::searchInStores('+-~*<>@', $store->id)->get()));
    }
}
