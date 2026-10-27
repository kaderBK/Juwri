<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyHistoryFixture as History;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\HistoryImporter;
use Juwri\Modules\LegacyCompat\Import\OpeningBalances;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyHistoryReadOnly;
use Tests\TestCase;

/**
 * J52b M5 money review fixes (SF1–SF6 + notes) against a SYNTHETIC source (invented values; J19): order payments in
 * the store currency, billing unique keys, wallet ledgers that cannot be carried and opening balances, frozen totals of
 * imported orders, delivery fees in derived totals, cross-store order payments and receipts, the friendly read-only
 * answer and report counts after a rolled-back store.
 */
class LegacyHistoryReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_review_source';

    private Source $src;

    private History $hist;

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
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        $this->hist = (new History(DB::connection(self::SOURCE)))->createSchema();
        $this->src->platformOption('currency_code', 'USD');
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

    private function store(int $id, int $owner, string $currency): void
    {
        $this->src->store($id, $owner);
        $this->src->storeOption($id, 'currency_code', $currency);
    }

    /** @return list<string> */
    private function marks(string $table, int $id): array
    {
        return DB::table('legacy_import_marks')->where('target_table', $table)->where('target_id', (string) $id)
            ->orderBy('mark')->pluck('mark')->all();
    }

    public function test_sf1_an_order_payment_without_a_currency_is_in_the_store_currency(): void
    {
        $this->src->user(1);
        $this->store(10, 1, 'KWD');
        $this->src->order(5000, 10);
        $this->hist->billingTransaction(7, 10, 12.5, null, ['transaction_method' => 'order', 'product_id' => 5000]);
        $this->hist->billingReceipt(8, 10, 7, 12.5, null, ['transaction_method' => 'order', 'product_id' => 5000]);
        $this->hist->billingTransaction(9, 1, 4.5, null); // a user's billing: the platform currency (USD)

        $this->import();

        $payment = DB::table('transaction')->where('id', 7)->first();
        $this->assertSame([12500, 'KWD'], [(int) $payment->amount_minor, $payment->currency_code]);
        $receipt = DB::table('billing_receipts')->where('id', 8)->first();
        $this->assertSame([12500, 'KWD'], [(int) $receipt->amount_minor, $receipt->currency_code]);
        $plan = DB::table('transaction')->where('id', 9)->first();
        $this->assertSame([450, 'USD'], [(int) $plan->amount_minor, $plan->currency_code]);
    }

    public function test_sf2_empty_billing_keys_are_none_and_a_duplicate_key_blocks_only_that_row(): void
    {
        $this->src->user(1);
        $this->store(10, 1, 'SAR');
        $this->hist->billingTransaction(1, 1, 1.0, 'USD', ['payment_id' => '', 'transaction_gatway' => 'offline', 'invoice_number' => '']);
        $this->hist->billingTransaction(2, 1, 2.0, 'USD', ['payment_id' => '', 'transaction_gatway' => 'offline', 'invoice_number' => '']);
        $this->hist->billingTransaction(3, 1, 3.0, 'USD', ['payment_id' => 'PAY-X', 'transaction_gatway' => 'stripe']);
        $this->hist->billingTransaction(4, 1, 3.0, 'USD', ['payment_id' => 'pay-x ', 'transaction_gatway' => 'Stripe']); // same key
        $this->hist->billingTransaction(5, 1, 5.0, 'USD', ['invoice_number' => 'INV-1']);
        $this->hist->billingTransaction(6, 1, 6.0, 'USD', ['invoice_number' => 'INV-1']);
        $this->hist->billingReceipt(1, 1, 3, 3.0, 'USD');
        $this->hist->billingReceipt(2, 1, 3, 3.0, 'USD'); // a second receipt of the same payment

        $report = $this->import([], 1);

        $this->assertSame([1, 2, 3, 5], DB::table('transaction')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertNull(DB::table('transaction')->where('id', 1)->value('payment_id'));
        $this->assertNull(DB::table('transaction')->where('id', 2)->value('invoice_number'));
        $this->assertSame(['transaction#4'], $report['conflicts']['blocked: transaction: duplicate payment id and gateway (first: transaction#3)']['samples']);
        $this->assertSame(['transaction#6'], $report['conflicts']['blocked: transaction: duplicate invoice number (first: transaction#5)']['samples']);
        $this->assertSame(['billing_receipts#2'], $report['conflicts']['blocked: billing_receipts: duplicate payment (transaction_id) (first: billing_receipts#1)']['samples']);
        // The run went on: the store and the rest of the history are in.
        $this->assertTrue(DB::table('stores')->where('id', 10)->exists());
        $this->assertSame([1], DB::table('billing_receipts')->pluck('id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_sf3_a_wallet_row_without_a_balance_snapshot_blocks_the_user(): void
    {
        $this->src->user(1, ['balance' => 3.0]);
        $this->hist->walletEntry(1, 1, 3.0, 3.0);
        $this->src->user(2, ['balance' => 2.0]);
        $this->hist->walletEntry(2, 2, 2.0, 'n/a');
        $this->store(20, 2, 'SAR');

        $report = $this->import([], 1);

        $this->assertSame([1], DB::table('users')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['user#2'], $report['conflicts']['blocked: users: wallet ledger row without a numeric balance_after']['samples']);
        $this->assertFalse(DB::table('stores')->where('id', 20)->exists());
    }

    public function test_sf3_without_a_source_wallet_ledger_the_balance_becomes_one_marked_opening_row(): void
    {
        $this->src->db->getSchemaBuilder()->drop('wallet_transactions');
        $this->src->user(1, ['balance' => 7.5]);
        $this->src->user(2, ['balance' => 0]);
        $this->src->user(3, ['balance' => -1.25]);
        $this->store(10, 1, 'SAR');

        $this->import();
        $this->import(); // a resumed run writes no second opening row

        $rows = DB::table('wallet_transactions')->orderBy('user_id')->get();
        $this->assertSame([1, 3], $rows->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([750, -125], $rows->pluck('amount_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([750, -125], $rows->pluck('balance_after_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['credit', 'debit'], $rows->pluck('type')->all());
        $this->assertSame(['USD', 'USD'], $rows->pluck('currency_code')->all());
        $this->assertSame(OpeningBalances::REASON, $rows[0]->reason);
        $this->assertSame(1, (int) $rows[0]->account_id);
        $this->assertSame([OpeningBalances::MARK], $this->marks('wallet_transactions', (int) $rows[0]->id));
        $this->assertSame(2, DB::table('legacy_import_rows')->where('target_table', 'wallet_transactions')->count());
    }

    public function test_sf4_sf5_imported_orders_keep_their_stored_totals_on_every_path(): void
    {
        $this->src->user(1);
        $this->store(10, 1, 'SAR');
        $this->src->item(100, 10, null, 3.00);
        $this->src->order(5000, 10);
        $this->src->orderItem(9000, 5000, 100, 1);
        $this->import();
        // What the source showed is the stored total; anything a live re-total would give differs from it.
        DB::table('orders')->where('id', 5000)->update(['subtotal_minor' => 12000, 'grand_total_minor' => 12345, 'tax_total_minor' => 345]);
        LegacyCompatServiceProvider::activate($this->app);

        $order = Order::withoutGlobalScopes()->findOrFail(5000);
        $this->assertTrue($order->isFrozenHistory());
        $money = $order->computeMoney();
        $this->assertEqualsWithDelta(120.0, $money['subtotal'], 0.0001);
        $this->assertEqualsWithDelta(3.45, $money['tax'], 0.0001);
        $this->assertEqualsWithDelta(123.45, $money['grand'], 0.0001);
        $this->assertEqualsWithDelta(123.45, (float) $order->total, 0.0001);
        $order->snapshotTotals();
        Artisan::call('orders:snapshot-totals', ['--force' => true]);
        $this->assertSame(12345, (int) DB::table('orders')->where('id', 5000)->value('grand_total_minor'));
        $this->assertSame(345, (int) DB::table('orders')->where('id', 5000)->value('tax_total_minor'));

        // Module off: the slot answers nothing and the order derives live again (unchanged core behaviour).
        config(['legacy_compat.enabled' => false]);
        $fresh = Order::withoutGlobalScopes()->findOrFail(5000);
        $this->assertFalse($fresh->isFrozenHistory());
        $this->assertNotEqualsWithDelta(123.45, (float) $fresh->total, 0.0001);
    }

    public function test_sf5_a_derived_delivery_total_includes_the_store_delivery_charge(): void
    {
        $this->src->user(1);
        $this->store(10, 1, 'KWD');
        $this->src->storeOption(10, HistoryImporter::DELIVERY_CHARGE_OPTION, '0.750');
        $this->store(20, 1, 'SAR');
        $this->src->storeOption(20, HistoryImporter::DELIVERY_CHARGE_OPTION, 'free');
        $this->src->item(100, 10, null, 2.000);
        $this->src->item(200, 20, null, 2.00);
        $this->src->order(5000, 10, ['type' => 'delivery']);
        $this->src->orderItem(9000, 5000, 100, 1);
        $this->src->order(5001, 10, ['type' => 'takeaway']);
        $this->src->orderItem(9001, 5001, 100, 1);
        $this->src->order(6000, 20, ['type' => 'delivery']);
        $this->src->orderItem(9100, 6000, 200, 1);

        $this->import();

        $delivery = DB::table('orders')->where('id', 5000)->first();
        $this->assertSame([2000, 750, 2750], [(int) $delivery->subtotal_minor, (int) $delivery->delivery_fee_minor, (int) $delivery->grand_total_minor]);
        $this->assertContains(HistoryImporter::DELIVERY_SNAPSHOT, $this->marks('orders', 5000));
        $this->assertSame(2000, (int) DB::table('orders')->where('id', 5001)->value('grand_total_minor'));
        $this->assertNull(DB::table('orders')->where('id', 5001)->value('delivery_fee_minor'));
        $this->assertSame(200, (int) DB::table('orders')->where('id', 6000)->value('grand_total_minor'));
        $this->assertContains(HistoryImporter::DELIVERY_NOT_RECORDED, $this->marks('orders', 6000));
    }

    public function test_sf6_payments_and_receipts_never_link_across_stores_or_owners(): void
    {
        $this->src->user(1);
        $this->src->user(2);
        $this->store(10, 1, 'SAR');
        $this->store(20, 2, 'SAR');
        $this->src->order(5000, 10);
        $this->src->order(6000, 20);
        // Under store 10, a payment naming store 20's order: refused (it carries store 20's customer billing data).
        $this->hist->billingTransaction(7, 10, 1.0, 'SAR', ['transaction_method' => 'order', 'product_id' => 6000, 'payment_id' => 'p7']);
        // Under store 10, a payment of an order that no longer exists: kept as history.
        $this->hist->billingTransaction(8, 10, 2.0, 'SAR', ['transaction_method' => 'order', 'product_id' => 4242, 'payment_id' => 'p8']);
        // A user's plan payment, and a store-10 order receipt that names it: the link is dropped.
        $this->hist->billingTransaction(9, 1, 3.0, 'USD', ['payment_id' => 'p9']);
        $this->hist->billingReceipt(20, 10, 9, 3.0, 'SAR', ['transaction_method' => 'order']);
        // Store 20's own payment; a USER receipt naming it (platform scope): the link is dropped on a resumed run too.
        $this->hist->billingTransaction(10, 20, 4.0, 'SAR', ['transaction_method' => 'order', 'product_id' => 6000, 'payment_id' => 'p10']);
        $this->hist->billingReceipt(21, 2, 10, 4.0, 'SAR');

        $report = $this->import(['--store' => ['10', '20']]);

        $this->assertFalse(DB::table('transaction')->where('id', 7)->exists());
        $this->assertSame(1, $report['conflicts']["transaction: order payment names another store's order"]['count']);
        $this->assertSame(10, (int) DB::table('transaction')->where('id', 8)->value('store_id'));
        $this->assertNull(DB::table('billing_receipts')->where('id', 20)->value('transaction_id'));
        $this->assertSame(10, (int) DB::table('billing_receipts')->where('id', 20)->value('store_id'));
        $this->assertNull(DB::table('billing_receipts')->where('id', 21)->value('transaction_id'));
        $this->assertSame(20, (int) DB::table('transaction')->where('id', 10)->value('store_id'));
    }

    public function test_note_a_refused_write_answers_read_only_not_a_server_error(): void
    {
        $e = LegacyHistoryReadOnly::for('orders', 5000, 'update', ['status']);

        $json = $e->render(Request::create('/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
        $this->assertSame(409, $json->getStatusCode());
        $this->assertFalse($json->getData(true)['success']);
        $this->assertStringContainsString('read-only', $json->getData(true)['message']);

        $page = $e->render(Request::create('/x', 'POST'));
        $this->assertSame(302, $page->getStatusCode());
        $this->assertTrue($e->report());
    }

    public function test_note_a_rolled_back_store_leaves_no_counts_behind_and_base_amount_errors_are_reported(): void
    {
        $this->src->db->getSchemaBuilder()->table('orders', fn ($t) => $t->string('client_uuid')->nullable());
        $this->src->user(1);
        $this->store(10, 1, 'SAR');
        $this->src->item(100, 10, null, 1.00);
        // Two orders with one client uuid: the target's unique key fails the store, which rolls back.
        $this->src->order(5000, 10, ['client_uuid' => 'u-1']);
        $this->src->orderItem(9000, 5000, 100, 1);
        $this->src->order(5001, 10, ['client_uuid' => 'u-1']);
        $this->hist->billingTransaction(1, 1, 5.0, 'USD', ['base_amount' => 'n/a']);

        $report = $this->import();

        $this->assertFalse(DB::table('stores')->where('id', 10)->exists());
        $this->assertSame(1, $report['conflicts'][$this->rolledBackKind($report)]['count']);
        foreach (['stores', 'items', 'orders', 'order_items'] as $table) {
            $this->assertSame(0, $report['tables'][$table]['written'] ?? 0, $table);
        }
        $this->assertArrayNotHasKey('mark:order_items:price_snapshot', $report['counters']);
        $this->assertSame(1, $report['tables']['stores']['source']);
        $this->assertNull(DB::table('transaction')->where('id', 1)->value('base_amount_minor'));
        $this->assertSame(1, $report['conflicts']['billing: base amount not numeric, set NULL']['count']);
    }

    /** @param array<string, mixed> $report */
    private function rolledBackKind(array $report): string
    {
        foreach (array_keys($report['conflicts']) as $kind) {
            if (str_starts_with((string) $kind, 'stores: store failed and was rolled back (')) {
                return (string) $kind;
            }
        }
        $this->fail('no rolled-back store reported: '.json_encode(array_keys($report['conflicts'])));
    }
}
