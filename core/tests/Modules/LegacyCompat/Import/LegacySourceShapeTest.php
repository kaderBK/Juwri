<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyProdShapeFixture as Shape;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\SourceClock;
use Juwri\Modules\LegacyCompat\Import\SourceReader;
use Tests\TestCase;

/**
 * J52b M4b step 1: reading a SYNTHETIC source shaped like the old platform's later schema (J19): a table prefix on
 * every source table, tables whose primary key is not `id`, and TIMESTAMP (instant) next to DATETIME (wall time)
 * columns.
 */
class LegacySourceShapeTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_shape_source';

    private const PREFIX = 'qrtest_';

    private Source $src;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => self::PREFIX, 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'Asia/Riyadh',
            'legacy_compat.import.source_app_key' => '',
        ]);
        DB::purge(self::SOURCE);
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        $this->src->platformOption('currency_code', 'USD');
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function import(): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    public function test_a_prefixed_source_imports_end_to_end_and_reports_bare_table_names(): void
    {
        (new Shape(DB::connection(self::SOURCE)))->foreignTable('other_app_sessions');
        $s = $this->src;
        $s->user(1);
        $s->store(10, 1, ['slug' => 'prefixed-store']);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->category(100, 10);
        $s->item(1000, 10, 100, 4.5);
        $s->table(700, 10, 'K-PFX-1');
        $s->order(5000, 10);
        $s->orderItem(9000, 5000, 1000, 2);
        $s->unmappedRow(1, 10);

        $reader = new SourceReader(DB::connection(self::SOURCE));
        $this->assertContains('posts', $reader->tables());
        $this->assertNotContains(self::PREFIX.'posts', $reader->tables());
        $this->assertNotContains('other_app_sessions', $reader->tables());

        $report = $this->import();

        $this->assertSame('prefixed-store', DB::table('stores')->where('id', 10)->value('slug'));
        $this->assertSame(450, (int) DB::table('items')->where('id', 1000)->value('price_minor'));
        $this->assertSame('K-PFX-1', DB::table('store_tables')->where('id', 700)->value('key'));
        $this->assertSame(900, (int) DB::table('orders')->where('id', 5000)->value('subtotal_minor'));
        $this->assertSame(self::PREFIX, $report['source']['table_prefix']);
        $this->assertContains(Source::UNMAPPED_TABLE, $report['unmapped_source_tables']);
        foreach ($report['unmapped_source_tables'] as $table) {
            $this->assertStringStartsNotWith(self::PREFIX, $table);
        }
        $this->assertNotContains('other_app_sessions', $report['unmapped_source_tables']);
    }

    public function test_tables_keyed_by_another_primary_key_are_chunked_listed_and_found_by_it(): void
    {
        $this->src->platformOption('site_title', 'Synthetic');
        $this->src->platformOption('timezone', 'UTC');
        $reader = new SourceReader(DB::connection(self::SOURCE), 50);

        $this->assertSame('option_id', $reader->key('options'));
        $this->assertSame('id', $reader->key('posts'));
        $seen = [];
        $reader->chunk('options', function (array $rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen[] = (string) $row['option_name'];
            }
        });
        $this->assertSame(['currency_code', 'site_title', 'timezone'], $seen);
        $ids = $reader->ids('options');
        $this->assertCount(3, $ids);
        $this->assertSame('site_title', $reader->find('options', $ids[1])['option_name'] ?? null);
        $this->assertSame('timezone', $reader->where('options', 'option_name', 'timezone')[0]['option_name'] ?? null);
    }

    public function test_timestamp_columns_are_utc_instants_and_datetime_columns_wall_times_of_the_source_zone(): void
    {
        (new Shape(DB::connection(self::SOURCE)))->instantColumn('orders', 'updated_at');
        $s = $this->src;
        $s->user(1);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        // created_at DATETIME = 20:15 Riyadh wall time; updated_at TIMESTAMP = an instant already read as UTC.
        $s->order(5000, 10, ['created_at' => '2025-03-01 20:15:00', 'updated_at' => '2025-03-01 17:20:00']);

        $reader = new SourceReader(DB::connection(self::SOURCE));
        $this->assertTrue($reader->isInstant('orders', 'updated_at'));
        $this->assertFalse($reader->isInstant('orders', 'created_at'));

        $report = $this->import();

        $order = DB::table('orders')->where('id', 5000)->first();
        $this->assertStringStartsWith('2025-03-01 17:15:00', (string) $order->created_at);
        $this->assertStringStartsWith('2025-03-01 17:20:00', (string) $order->updated_at);
        $this->assertSame('Asia/Riyadh', $report['source']['datetime_zone']);
        $this->assertNotContains('updated_at', $report['missing_source_columns']['orders'] ?? []);
    }

    public function test_never_migrated_tables_are_silent_and_back_office_tables_are_listed_as_skipped_by_decision(): void
    {
        $shape = new Shape(DB::connection(self::SOURCE));
        foreach (['waiter_call', 'table_merge_groups', 'ab_tests', 'whatsapp_log', 'ingredients', 'stock_movements', 'staff_shifts', 'payroll_runs', 'pos_terminals', 'fiscal_devices', 'webhook_endpoints'] as $table) {
            $shape->foreignTable(self::PREFIX.$table);
        }
        $this->src->user(1);

        $report = $this->import();

        foreach (['waiter_call', 'table_merge_groups', 'ab_tests', 'whatsapp_log', 'ingredients', 'staff_shifts', 'pos_terminals'] as $table) {
            $this->assertNotContains($table, $report['unmapped_source_tables']);
        }
        $this->assertSame(
            ['fiscal_devices', 'ingredients', 'payroll_runs', 'pos_terminals', 'staff_shifts', 'stock_movements', 'webhook_endpoints'],
            array_keys($report['skipped_by_decision']),
        );
        $this->assertStringStartsWith('J58c', $report['skipped_by_decision']['payroll_runs']);
        $this->assertArrayNotHasKey('waiter_call', $report['skipped_by_decision']);
    }

    /** Three orders never updated since they were written: DATETIME created_at and TIMESTAMP updated_at from one clock. */
    private function ordersWrittenTogether(string $timestampValue): void
    {
        (new Shape(DB::connection(self::SOURCE)))->instantColumn('orders', 'updated_at');
        $this->src->user(1);
        $this->src->store(10, 1);
        $this->src->storeOption(10, 'currency_code', 'SAR');
        foreach ([5000, 5001, 5002] as $i => $id) {
            $this->src->order($id, 10, ['created_at' => '2025-03-0'.($i + 1).' 20:15:00', 'updated_at' => '2025-03-0'.($i + 1).' '.$timestampValue]);
        }
    }

    public function test_the_clock_check_passes_when_timestamps_and_datetimes_agree(): void
    {
        $this->ordersWrittenTogether('17:15:00'); // 20:15 Riyadh = 17:15 UTC

        $report = $this->import();

        $this->assertSame('offset 0 h in 3 of 3 pairs', $report['source']['clock_check']);
        $this->assertSame(3, DB::table('orders')->count());
    }

    public function test_a_whole_hour_disagreement_between_the_clocks_refuses_the_import(): void
    {
        // The source server wrote its TIMESTAMPs in another zone than the app: every pair is 3 h apart.
        $this->ordersWrittenTogether('20:15:00');

        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame(['orders offset 3 h'], $report['conflicts'][SourceClock::BLOCKED]['samples']);
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('users')->count());
    }
}
