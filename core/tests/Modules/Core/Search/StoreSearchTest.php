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
use Juwri\Core\Search\DatabaseSearchEngine;
use Juwri\Modules\Menu\Models\Menu;
use Laravel\Scout\EngineManager;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-17 part 2c: Scout database search for menu items and customers (DatabaseSearchEngine + StoreSearchable).
 * Runs on sqlite, MySQL and PostgreSQL; the MySQL full-text branch (which needs committed rows) is
 * MysqlLaneFullTextSearchTest.
 */
class StoreSearchTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    private function item(Store $store, string $name): Menu
    {
        return Menu::create(['store_id' => $store->id, 'name' => $name, 'description' => 'd', 'price' => 5, 'active' => '1']);
    }

    private function customer(Store $store, string $name, ?string $phone = null, ?string $email = null): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => $name, 'phone' => $phone, 'email' => $email]);
    }

    /** @return list<int> */
    private function ids(iterable $models): array
    {
        return collect($models)->pluck('id')->sort()->values()->all();
    }

    public function test_the_database_driver_is_juwris_engine_for_both_models(): void
    {
        $this->assertSame('database', config('scout.driver'));
        $this->assertInstanceOf(DatabaseSearchEngine::class, app(EngineManager::class)->engine());
        $this->assertInstanceOf(DatabaseSearchEngine::class, (new Menu)->searchableUsing());
        $this->assertInstanceOf(DatabaseSearchEngine::class, (new Customer)->searchableUsing());
    }

    public function test_a_store_never_finds_another_stores_rows_sharing_the_words(): void
    {
        $a = $this->store();
        $b = $this->store();
        $mineItem = $this->item($a, 'Chicken Shawarma');
        $this->item($b, 'Chicken Shawarma');
        $this->item($b, 'Shawarma Chicken');
        $mineCustomer = $this->customer($a, 'Ahmed Saleh', '0790000001', 'ahmed@a.test');
        $this->customer($b, 'Ahmed Saleh', '0790000001', 'ahmed@a.test');
        $this->customer($b, 'Saleh Ahmed');

        foreach (['shawarma', 'chicken shawarma', 'CHICKEN'] as $q) {
            $this->assertSame([$mineItem->id], $this->ids(Menu::searchInStores($q, $a->id)->get()), $q);
        }
        foreach (['ahmed', 'ahmed saleh', '0790000001', 'ahmed@a.test'] as $q) {
            $this->assertSame([$mineCustomer->id], $this->ids(Customer::searchInStores($q, [$a->id])->get()), $q);
            $this->assertSame([$mineCustomer->id], $this->ids(Customer::whereIn('store_id', [$a->id])->whereSearch($q)->get()), $q);
        }
        // Reversed words: full-text (MySQL/PostgreSQL) finds this store's row, sqlite's LIKE finds none — never theirs.
        $this->assertSame([], array_diff($this->ids(Menu::searchInStores('shawarma chicken', $a->id)->get()), [$mineItem->id]));
        $this->assertSame([], array_diff($this->ids(Customer::searchInStores('saleh ahmed', $a->id)->get()), [$mineCustomer->id]));
    }

    public function test_an_empty_store_list_finds_nothing(): void
    {
        $this->customer($this->store(), 'Ahmed');

        $this->assertCount(0, Customer::searchInStores('ahmed', [])->get());
    }

    public function test_an_arabic_word_finds_arabic_names(): void
    {
        $store = $this->store();
        $item = $this->item($store, 'شاورما دجاج');
        $this->item($store, 'فلافل');
        $customer = $this->customer($store, 'أحمد العلي');
        $this->customer($store, 'سارة');

        $this->assertSame([$item->id], $this->ids(Menu::searchInStores('دجاج', $store->id)->get()));
        $this->assertSame([$item->id], $this->ids(Menu::searchInStores('شاورما', $store->id)->get()));
        $this->assertSame([$customer->id], $this->ids(Customer::searchInStores('العلي', $store->id)->get()));
    }

    public function test_arabic_spelling_variants_find_the_same_item(): void
    {
        $store = $this->store();
        $alef = $this->item($store, 'أكل');             // alef with hamza
        $ya = $this->item($store, 'على');               // alef-maqsura
        $ta = $this->item($store, 'قهوة');              // ta-marbuta
        $tashkeel = $this->item($store, 'شَاوِرْمَا'); // fully vowelled
        $digit = $this->item($store, 'برجر ٣ قطع');    // Arabic-Indic digit

        $this->assertSame([$alef->id], $this->ids(Menu::searchInStores('اكل', $store->id)->get()));
        $this->assertSame([$ya->id], $this->ids(Menu::searchInStores('علي', $store->id)->get()));
        $this->assertSame([$ta->id], $this->ids(Menu::searchInStores('قهوه', $store->id)->get()));
        $this->assertSame([$tashkeel->id], $this->ids(Menu::searchInStores('شاورما', $store->id)->get()));
        $this->assertSame([$digit->id], $this->ids(Menu::searchInStores('برجر 3 قطع', $store->id)->get()));
    }

    public function test_english_item_names_are_unaffected_by_normalisation(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Chicken Burger');
        $this->item($store, 'Falafel Wrap');

        $this->assertSame([$burger->id], $this->ids(Menu::searchInStores('burger', $store->id)->get()));
        $this->assertSame([$burger->id], $this->ids(Menu::searchInStores('BURGER', $store->id)->get()));
        $this->assertSame([$burger->id], $this->ids(Menu::searchInStores('chicken', $store->id)->get()));
    }

    public function test_migration_backfill_normalises_existing_item_names(): void
    {
        $store = $this->store();
        $item = $this->item($store, 'أكل');
        // Simulate a row written before the column existed.
        DB::table('items')->where('id', $item->id)->update(['name_search' => null]);

        $migration = require base_path('app/Modules/Menu/Database/Migrations/2026_10_19_120000_add_name_search_to_items.php');
        $migration->backfill();

        $this->assertSame('اكل', DB::table('items')->where('id', $item->id)->value('name_search'));
    }

    public function test_migration_can_be_re_run_and_backfill_groups_equal_names(): void
    {
        $store = $this->store();
        $a = $this->item($store, 'أكل');
        $b = $this->item($store, 'اكل');
        DB::table('items')->whereIn('id', [$a->id, $b->id])->update(['name_search' => null]);

        $migration = require base_path('app/Modules/Menu/Database/Migrations/2026_10_19_120000_add_name_search_to_items.php');
        // The column already exists (a run cut off mid-backfill): up() must not die on a duplicate column.
        $migration->up();

        $this->assertSame(['اكل', 'اكل'], DB::table('items')->whereIn('id', [$a->id, $b->id])->orderBy('id')->pluck('name_search')->all());
    }

    public function test_backfill_command_fills_missing_rows(): void
    {
        $store = $this->store();
        $item = $this->item($store, 'أكل');
        DB::table('items')->where('id', $item->id)->update(['name_search' => null]);

        $this->artisan('juwri:items:backfill-name-search')->assertSuccessful();

        $this->assertSame('اكل', DB::table('items')->where('id', $item->id)->value('name_search'));
    }

    public function test_like_wildcards_in_the_term_are_literal(): void
    {
        $store = $this->store();
        $percent = $this->customer($store, '50% Club');
        $this->customer($store, '500 Club');
        $underscore = $this->customer($store, 'Team a_b');
        $this->customer($store, 'Team axb');
        $bang = $this->customer($store, 'Yes! Burgers');

        $this->assertSame([$percent->id], $this->ids(Customer::searchInStores('50%', $store->id)->get()));
        $this->assertSame([$underscore->id], $this->ids(Customer::searchInStores('a_b', $store->id)->get()));
        $this->assertSame([$bang->id], $this->ids(Customer::searchInStores('yes!', $store->id)->get()));
    }

    public function test_soft_deleted_items_are_not_found(): void
    {
        $store = $this->store();
        $kept = $this->item($store, 'Falafel Wrap');
        $this->item($store, 'Falafel Plate')->delete();

        $this->assertSame([$kept->id], $this->ids(Menu::searchInStores('falafel', $store->id)->get()));
    }

    public function test_description_is_not_searched(): void
    {
        $store = $this->store();
        Menu::create(['store_id' => $store->id, 'name' => 'Soup', 'description' => 'saffron', 'price' => 5, 'active' => '1']);

        $this->assertCount(0, Menu::searchInStores('saffron', $store->id)->get());
    }

    public function test_full_text_indexes_exist_where_the_database_has_full_text(): void
    {
        $fullText = in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);

        $this->assertSame($fullText, Schema::hasIndex('items', 'items_name_fulltext'));
        $this->assertSame($fullText, Schema::hasIndex('customers', 'customers_name_fulltext'));

        if (DB::getDriverName() === 'pgsql') {
            $def = (string) DB::table('pg_indexes')->where('indexname', 'items_name_fulltext')->value('indexdef');
            $this->assertStringContainsString("to_tsvector('simple'", $def);
            $this->assertStringContainsString('gin', strtolower($def));
        }
    }

    /**
     * Full-text (PostgreSQL here; MySQL in MysqlLaneFullTextSearchTest) finds the words in any order; sqlite stays a
     * LIKE substring search, as before.
     */
    public function test_words_in_any_order_on_postgresql_substring_on_sqlite(): void
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            $this->markTestSkipped('MySQL full-text needs committed rows: MysqlLaneFullTextSearchTest.');
        }
        $store = $this->store();
        $burger = $this->item($store, 'Chicken Burger');
        $arabic = $this->item($store, 'شاورما دجاج حار');
        $customer = $this->customer($store, 'Maya Haddad');

        $expectFound = DB::getDriverName() === 'pgsql';

        $this->assertSame($expectFound ? [$burger->id] : [], $this->ids(Menu::searchInStores('burger chicken', $store->id)->get()));
        $this->assertSame($expectFound ? [$arabic->id] : [], $this->ids(Menu::searchInStores('دجاج شاورما', $store->id)->get()));
        $this->assertSame($expectFound ? [$customer->id] : [], $this->ids(Customer::searchInStores('haddad maya', $store->id)->get()));
    }

    /** When full-text adds rows, the rows the LIKE search found come first — a limit never drops them. */
    public function test_like_matches_sort_before_full_text_only_matches(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL in-transaction full-text; MySQL: MysqlLaneFullTextSearchTest; sqlite has no full-text.');
        }
        $store = $this->store();
        $this->item($store, 'Burger with Chicken');
        $like = $this->item($store, 'Chicken Burger Deluxe');
        $this->item($store, 'Chicken Burger Box')->delete();

        $first = Menu::searchInStores('chicken burger', $store->id)->take(1)->get();

        $this->assertSame([$like->id], $this->ids($first));
        $this->assertCount(2, Menu::searchInStores('chicken burger', $store->id)->get());
    }

    public function test_full_text_term_per_driver(): void
    {
        $this->assertSame('+chicken +burger', DatabaseSearchEngine::fullTextTerm('chicken  burger', 'mysql'));
        $this->assertSame('+chicken', DatabaseSearchEngine::fullTextTerm('chicken ab', 'mysql'), 'MySQL drops words under 3 characters');
        $this->assertNull(DatabaseSearchEngine::fullTextTerm('ab +-"()~*<>@', 'mysql'), 'boolean operators never reach MATCH');
        $this->assertSame('+دجاج +حار', DatabaseSearchEngine::fullTextTerm('دجاج، حار', 'mariadb'));
        $this->assertSame('ab chicken', DatabaseSearchEngine::fullTextTerm('ab & chicken!', 'pgsql'));
        $this->assertNull(DatabaseSearchEngine::fullTextTerm('%%', 'pgsql'));
        $this->assertSame('50!% a!_b !!', DatabaseSearchEngine::escapeLike('50% a_b !'));
    }
}
