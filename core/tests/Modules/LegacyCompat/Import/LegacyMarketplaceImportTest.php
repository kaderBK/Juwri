<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyMarketplaceFixture as Marketplace;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\ImportGuard;
use Juwri\Modules\LegacyCompat\Import\ShopperWallets;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyHistoryReadOnly;
use Tests\TestCase;

/**
 * J52b M4c / J58d: the marketplace in `legacy:import` against a SYNTHETIC source (J19): shoppers with their wallet
 * ledger (exact sum-vs-balance check per shopper; a mismatch blocks that shopper, nothing else), addresses, store links
 * of the same store only, page sections; resumable and idempotent; imported wallet rows read-only.
 */
class LegacyMarketplaceImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_marketplace_source';

    private Source $src;

    private Marketplace $mk;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'UTC',
            'legacy_compat.import.source_app_key' => '',
        ]);
        DB::purge(self::SOURCE);
        $db = DB::connection(self::SOURCE);
        $this->src = (new Source($db))->createSchema();
        $this->mk = (new Marketplace($db))->createSchema();
        $this->src->platformOption('currency_code', 'SAR');
        $this->src->user(1);
        $this->src->user(2);
        foreach ([10 => 1, 20 => 2] as $store => $owner) {
            $this->src->store($store, $owner);
            $this->src->storeOption($store, 'currency_code', 'SAR');
        }
        $this->src->customer(500, 10);
        $this->src->customer(600, 20);
        $this->mk->country(3, 'sa');
        $this->mk->country(4, 'ZZ'); // no such country on this install
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
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

    /** @return list<int> */
    private function ids(string $table): array
    {
        return DB::table($table)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function scenario(): void
    {
        $this->mk->shopper(1, 3);
        $this->mk->walletEntry(1, 1, '10.000', 'SAR', '10.000');
        $this->mk->walletEntry(2, 1, '-2.500', 'SAR', '7.500');
        $this->mk->walletEntry(3, 1, '1.250', 'KWD', '1.250'); // second currency: its own exponent and running sum
        $this->mk->shopper(2, 3);
        $this->mk->walletEntry(4, 2, '5.000', 'SAR', '5.000');
        $this->mk->walletEntry(5, 2, '1.000', 'SAR', '7.000'); // snapshot does not add up: shopper 2 blocked
        $this->mk->shopper(3, 4);                               // country not on this install
        $this->mk->shopper(4, 3, ['reseller_id' => 9]);         // reseller not on this install
        $this->mk->address(1, 1, ['city_id' => 77]);
        $this->mk->address(2, 2);
        $this->mk->storeLink(1, 1, 10, 500);
        $this->mk->storeLink(2, 1, 20, 500);                    // store 20 naming store 10's customer: refused
        $this->mk->favorite(1, 1, 10);
        $this->mk->favorite(2, 2, 10);                          // blocked shopper: not imported
        $this->mk->follow(1, 1, 20);
        $this->mk->section(1, 'featured');
        $this->mk->section(2, 'featured', ['reseller_id' => 9]);
        $this->mk->section(3, 'featured', ['city_id' => 5]);
    }

    public function test_shoppers_and_wallets_land_with_exact_balances_and_a_mismatch_blocks_only_that_shopper(): void
    {
        $this->scenario();

        $report = $this->import([], 1);

        $this->assertSame([1], $this->ids('shoppers'));
        $shopper = DB::table('shoppers')->where('id', 1)->first();
        $this->assertSame((int) DB::table('countries')->where('code', 'SA')->value('id'), (int) $shopper->country_id);
        $this->assertSame(0, (int) $shopper->reseller_id);
        $wallet = DB::table('shopper_wallet_ledger_entries')->orderBy('id')->get();
        $this->assertSame([1, 2, 3], $wallet->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1000, -250, 1250], $wallet->pluck('amount_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1000, 750, 1250], $wallet->pluck('balance_after_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['SAR', 'SAR', 'KWD'], $wallet->pluck('currency')->all());
        $this->assertSame(750, $report['counters']['shopper_wallet_balance_minor:SAR']);

        $this->assertSame(['shopper#2'], $report['conflicts']['blocked: shoppers: '.ShopperWallets::REASON]['samples']);
        $conflicts = implode("\n", array_keys($report['conflicts']));
        $this->assertStringContainsString('shoppers: shopper country not on this install', $conflicts);
        $this->assertStringContainsString('shoppers: shopper of a reseller marketplace', $conflicts);

        // Addresses of the imported shopper only (the city is not imported yet).
        $this->assertSame([1], $this->ids('shopper_addresses'));
        $this->assertNull(DB::table('shopper_addresses')->where('id', 1)->value('city_id'));

        // Store links: tenancy from the store, the customer of the same store only.
        $link = DB::table('shopper_customers')->where('id', 1)->first();
        $this->assertSame([10, 500, 1], [(int) $link->store_id, (int) $link->customer_id, (int) $link->account_id]);
        $this->assertSame([1], $this->ids('shopper_customers'));
        $this->assertSame([1], $this->ids('shopper_favorites'));
        $this->assertSame([[1, 20]], DB::table('shopper_follows')->get()->map(fn ($r) => [(int) $r->id, (int) $r->store_id])->all());

        $this->assertSame([1], $this->ids('marketplace_sections'));
        $this->assertArrayHasKey('marketplace_media', $report['skipped_by_decision']);
        $this->assertArrayHasKey('mk_dispatch_offers', $report['skipped_by_decision']);
        $this->assertContains('shopper_push_subscriptions', $report['unmapped_source_tables']);
    }

    public function test_a_resumed_run_imports_a_corrected_shopper_once_and_duplicates_nothing(): void
    {
        $this->scenario();
        $this->import([], 1);

        DB::connection(self::SOURCE)->table('shopper_wallet_ledger')->where('id', 5)->update(['balance_after' => '6.000']);
        $report = $this->import();

        $this->assertSame([1, 2], $this->ids('shoppers'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids('shopper_wallet_ledger_entries'));
        $this->assertSame(600, (int) DB::table('shopper_wallet_ledger_entries')->where('shopper_id', 2)->sum('amount_minor'));
        $this->assertSame(1, $report['counters']['shoppers_already_imported']);
        $this->assertSame([], ImportGuard::targetReasons(DB::connection()));
        // The blocked shopper's address comes with it now; its favourite belongs to an already imported store (listed).
        $this->assertSame([1, 2], $this->ids('shopper_addresses'));
    }

    public function test_a_shopper_that_cannot_be_written_rolls_back_alone_with_its_wallet(): void
    {
        $this->mk->country(5, 'SA'); // a second source country with the same code: both shoppers land in one country
        $this->mk->shopper(5, 3, ['phone' => '+15550001']);
        $this->mk->walletEntry(10, 5, '1.000', 'SAR', '1.000');
        $this->mk->shopper(6, 5, ['phone' => '+15550001']);
        $this->mk->walletEntry(11, 6, '2.000', 'SAR', '2.000');
        $this->mk->shopper(7, 3);

        $report = $this->import();

        $this->assertSame([5, 7], $this->ids('shoppers'));
        $this->assertSame([10], $this->ids('shopper_wallet_ledger_entries'));
        $failed = array_values(array_filter($report['conflicts'], fn ($k) => str_starts_with($k, 'shoppers: shopper failed'), ARRAY_FILTER_USE_KEY));
        $this->assertCount(1, $failed);
        $this->assertSame(['shopper#6'], $failed[0]['samples']);
    }

    public function test_a_skipped_shopper_reports_the_wallet_money_it_leaves_behind(): void
    {
        $this->mk->shopper(3, 4); // country not on this install
        $this->mk->walletEntry(20, 3, '10.000', 'SAR', '10.000');
        $this->mk->walletEntry(21, 3, '-2.500', 'SAR', '7.500');
        $this->mk->walletEntry(22, 3, '1.250', 'KWD', '1.250');

        $report = $this->import();

        $this->assertSame(0, DB::table('shoppers')->count());
        $this->assertSame(['shopper#3 KWD:1250 SAR:750'], $report['conflicts']['shoppers: wallet balance of a shopper not imported (minor units per currency)']['samples']);
        $this->assertSame(750, $report['counters']['shopper_wallet_not_imported_minor:SAR']);
    }

    public function test_a_resume_blocks_an_imported_shopper_whose_source_ledger_changed(): void
    {
        $this->mk->shopper(1, 3);
        $this->mk->walletEntry(1, 1, '10.000', 'SAR', '10.000');
        $this->import();
        $this->mk->walletEntry(2, 1, '-1.000', 'SAR', '9.000'); // spent on the old platform after the import

        $report = $this->import([], 1);

        $this->assertSame(['shopper#1'], $report['conflicts']['blocked: shoppers: shopper wallet ledger changed since its import (source rows 2, imported 1)']['samples']);
        $this->assertSame([1], $this->ids('shopper_wallet_ledger_entries'));
    }

    public function test_a_shopper_never_links_to_a_store_of_another_marketplace(): void
    {
        DB::connection(self::SOURCE)->getSchemaBuilder()->table('posts', fn ($t) => $t->unsignedBigInteger('mk_reseller_id')->default(0));
        DB::connection(self::SOURCE)->table('posts')->where('id', 20)->update(['mk_reseller_id' => 9]);
        $this->mk->shopper(1, 3);
        $this->mk->follow(1, 1, 10);
        $this->mk->follow(2, 1, 20);

        $report = $this->import();

        $this->assertSame([1], $this->ids('shopper_follows'));
        $this->assertArrayHasKey('shopper_follows: shopper of another marketplace than the store (reseller#0 vs reseller#9)', $report['conflicts']);
    }

    public function test_dry_run_writes_no_shopper_or_wallet_row(): void
    {
        $this->scenario();

        $report = $this->import(['--dry-run' => true], 1);

        $this->assertSame(1, $report['tables']['shoppers']['written']);
        $this->assertSame(0, DB::table('shoppers')->count());
        $this->assertSame(0, DB::table('shopper_wallet_ledger_entries')->count());
    }

    public function test_imported_wallet_rows_are_read_only_history(): void
    {
        $this->mk->shopper(1, 3);
        $this->mk->walletEntry(1, 1, '10.000', 'SAR', '10.000');
        $this->import();
        LegacyCompatServiceProvider::activate($this->app);

        $entry = new class extends Model
        {
            protected $table = 'shopper_wallet_ledger_entries';

            public $timestamps = false;
        };
        $row = $entry->newQuery()->findOrFail(1);
        $row->setAttribute('amount_minor', 1);

        $this->expectException(LegacyHistoryReadOnly::class);
        $row->save();
    }
}
