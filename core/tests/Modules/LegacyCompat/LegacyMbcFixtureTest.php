<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyMbcFixture;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomTokenKeys;
use Tests\TestCase;

/**
 * M7-A: the shape of the synthetic legacy source set — what the fixture writes (30 stores, the exact slugs, the
 * hotel-room tokens, unique table keys, determinism) and what the importer makes of it (the stores that have an
 * owner land with their slugs byte-for-byte; the two whose owner does not exist do not land at all).
 */
class LegacyMbcFixtureTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_test_source';

    private const SOURCE_B = 'legacy_test_source_b';

    /** The slugs of the set, in store-id order (101..130). */
    private const EXPECTED_SLUGS = [
        'cafe-one', 'Mixed-Case-Cafe', '12345', 'مطعم-x', 'admin',
        'r', 'ar', 'store with-dash_1', 'pizza-place', 'sushi-bar',
        'shop-11', 'shop-12', 'shop-13', 'shop-14', 'shop-15',
        'shop-16', 'shop-17', 'shop-18', 'shop-19', 'shop-20',
        'shop-21', 'shop-22', 'shop-23', 'shop-24', 'shop-25',
        'shop-26', 'shop-27', 'shop-28', 'shop-29', 'shop-30',
    ];

    private Source $src;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'Asia/Riyadh',
            'legacy_compat.import.source_app_key' => '',
            'legacy_compat.import.enabled_gateways' => ['stripe', 'paypal'],
        ]);
        DB::purge(self::SOURCE);
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        DB::purge(self::SOURCE_B);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed> the decoded report
     */
    private function import(array $args = [], int $expectedExit = 0): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE] + $args);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    /** A second, empty source, on its own connection. */
    private function emptySource(): Source
    {
        config([
            'database.connections.'.self::SOURCE_B => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge(self::SOURCE_B);

        return (new Source(DB::connection(self::SOURCE_B)))->createSchema();
    }

    public function test_populate_writes_the_documented_shape(): void
    {
        $manifest = LegacyMbcFixture::populate($this->src);

        $this->assertSame(LegacyMbcFixture::OLD_APP_KEY, $manifest['old_app_key']);
        $this->assertCount(30, $manifest['stores']);
        $this->assertSame(range(101, 130), array_column($manifest['stores'], 'id'));
        $this->assertSame(self::EXPECTED_SLUGS, array_column($manifest['stores'], 'slug'));

        // Three stores per owner; the two stores whose owner does not exist are marked inactive.
        $expectedOwners = [];
        for ($id = 101; $id <= 130; $id++) {
            $expectedOwners[$id] = 1 + intdiv($id - 101, 3);
        }
        $expectedOwners[125] = LegacyMbcFixture::MISSING_OWNER_ID;
        $expectedOwners[126] = LegacyMbcFixture::MISSING_OWNER_ID;
        $this->assertSame($expectedOwners, array_column($manifest['stores'], 'owner_id', 'id'));

        $active = array_values(array_filter($manifest['stores'], static fn (array $store): bool => $store['active']));
        $orphans = array_values(array_filter($manifest['stores'], static fn (array $store): bool => ! $store['active']));
        $this->assertCount(28, $active);
        $this->assertSame([125, 126], array_column($orphans, 'id'));
        $this->assertSame(
            [LegacyMbcFixture::MISSING_OWNER_ID, LegacyMbcFixture::MISSING_OWNER_ID],
            array_column($orphans, 'owner_id')
        );

        // The hotel rooms: the first ten stores only, three each, with tokens that verify under the old app key.
        $withRooms = array_values(array_filter($manifest['stores'], static fn (array $store): bool => $store['rooms'] !== []));
        $this->assertSame(range(101, 110), array_column($withRooms, 'id'));

        $keys = [];
        foreach ($manifest['stores'] as $store) {
            $this->assertCount($store['active'] ? 5 + ($store['id'] % 16) : 1, $store['tables']);
            $this->assertCount($store['id'] <= 110 ? 3 : 0, $store['rooms']);

            $tableIds = array_column($store['tables'], 'id');
            foreach ($store['tables'] as $table) {
                $keys[] = $table['key'];
                $this->assertSame('table', $table['area_type']);
                $this->assertSame(
                    sprintf('K-%s-%d', substr(hash('sha256', 't'.$table['id']), 0, 10), $store['id']),
                    $table['key']
                );
            }
            foreach ($store['rooms'] as $room) {
                $keys[] = $room['key'];
                // A room is not a table: the two lists never share a row.
                $this->assertNotContains($room['id'], $tableIds);
                $this->assertSame(40, strlen($room['token']));
                $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/D', $room['token']);
                $this->assertSame(
                    LegacyRoomTokenKeys::sign($store['id'], $room['id'], LegacyMbcFixture::OLD_APP_KEY),
                    $room['token']
                );
            }
        }

        // Every table key and every room key is globally unique.
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_populate_is_deterministic(): void
    {
        $first = LegacyMbcFixture::populate($this->src);
        $second = LegacyMbcFixture::populate($this->emptySource());

        $this->assertSame($first, $second);
    }

    public function test_import_lands_the_stores_with_an_owner_and_refuses_the_orphans(): void
    {
        $manifest = LegacyMbcFixture::populate($this->src);
        $report = $this->import();

        $active = array_values(array_filter($manifest['stores'], static fn (array $store): bool => $store['active']));
        $orphans = array_values(array_filter($manifest['stores'], static fn (array $store): bool => ! $store['active']));

        // The stores that land: exactly the ones with an owner, with their slugs byte-for-byte.
        $stores = DB::table('stores')->orderBy('id')->get(['id', 'slug']);
        $this->assertCount(count($active), $stores);
        foreach ($active as $index => $store) {
            $this->assertSame($store['id'], (int) $stores[$index]->id);
            $this->assertSame($store['slug'], $stores[$index]->slug);
        }

        // The two stores whose owner does not exist are refused: nothing lands for them, and the report says so.
        $this->assertCount(2, $orphans);
        foreach ($orphans as $store) {
            $this->assertSame(0, DB::table('stores')->where('id', $store['id'])->count());
            $this->assertSame(0, DB::table('store_tables')->where('store_id', $store['id'])->count());
        }
        $this->assertSame(2, $report['tables']['stores']['skipped'] ?? 0);
        $this->assertSame(count($active), $report['tables']['stores']['written'] ?? 0);

        // Every table and every room of the manifest lands on its own store, with its area type.
        $written = DB::table('store_tables')->get(['store_id', 'key', 'area_type'])->keyBy('key');
        $expectedTables = 0;
        foreach ($active as $store) {
            foreach ($store['tables'] as $table) {
                $row = $written->get($table['key']);
                $this->assertNotNull($row, 'table key '.$table['key'].' did not land');
                $this->assertSame($store['id'], (int) $row->store_id);
                $this->assertSame('table', $row->area_type);
                $expectedTables++;
            }
            foreach ($store['rooms'] as $room) {
                $row = $written->get($room['key']);
                $this->assertNotNull($row, 'room key '.$room['key'].' did not land');
                $this->assertSame($store['id'], (int) $row->store_id);
                $this->assertSame('room', $row->area_type);
                $expectedTables++;
            }
        }
        $this->assertCount($expectedTables, $written);

        // The rows under the stores: items, orders, customers, loyalty accounts and gift cards.
        $items = DB::table('items')->selectRaw('store_id, count(*) as n')->groupBy('store_id')->pluck('n', 'store_id');
        $orders = DB::table('orders')->selectRaw('store_id, count(*) as n')->groupBy('store_id')->pluck('n', 'store_id');
        $customers = DB::table('customers')->selectRaw('store_id, count(*) as n')->groupBy('store_id')->pluck('n', 'store_id');
        $accounts = DB::table('loyalty_accounts')->selectRaw('store_id, count(*) as n')->groupBy('store_id')->pluck('n', 'store_id');
        $cards = DB::table('gift_cards')->selectRaw('store_id, count(*) as n')->groupBy('store_id')->pluck('n', 'store_id');

        $expectedItems = 0;
        $expectedOrders = 0;
        $expectedCustomers = 0;
        $expectedAccounts = 0;
        $expectedCards = 0;
        foreach ($active as $store) {
            $expectedItems += count($store['items']);
            $expectedOrders += 5;
            $expectedCustomers += 4;
            $expectedAccounts += 2;
            $expectedCards += $store['id'] <= LegacyMbcFixture::GIFT_CARD_STORE_LAST_ID ? 1 : 0;

            $this->assertSame(count($store['items']), (int) $items->get($store['id'], 0), 'items of store '.$store['id']);
            $this->assertSame(5, (int) $orders->get($store['id'], 0), 'orders of store '.$store['id']);
            $this->assertSame(4, (int) $customers->get($store['id'], 0), 'customers of store '.$store['id']);
            $this->assertSame(2, (int) $accounts->get($store['id'], 0), 'loyalty accounts of store '.$store['id']);
            $this->assertSame(
                $store['id'] <= LegacyMbcFixture::GIFT_CARD_STORE_LAST_ID ? 1 : 0,
                (int) $cards->get($store['id'], 0),
                'gift cards of store '.$store['id']
            );
        }

        $this->assertSame($expectedItems, DB::table('items')->count());
        $this->assertSame($expectedOrders, DB::table('orders')->count());
        $this->assertSame($expectedCustomers, DB::table('customers')->count());
        $this->assertSame($expectedAccounts, DB::table('loyalty_accounts')->count());
        $this->assertSame($expectedCards, DB::table('gift_cards')->count());
    }
}
