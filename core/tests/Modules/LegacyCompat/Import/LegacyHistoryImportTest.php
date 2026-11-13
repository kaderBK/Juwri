<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyHistoryFixture as History;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\HistoryImporter;
use Juwri\Modules\LegacyCompat\Import\LedgerChecks;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyHistoryReadOnly;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\TestCase;

/**
 * J52b M5: the importer's money and history (plan §4, §6.3) against a SYNTHETIC source (invented values; J19):
 * minor units per currency exponent, recorded prices vs marked menu snapshots, derived totals, ledger reconciliation
 * that blocks (never fixes), billing history, no side effects during the import, read-only imported history, and the
 * per-store totals for the cut-over checksum step.
 */
class LegacyHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_history_source';

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

    public function test_amounts_convert_with_the_exponent_of_their_currency(): void
    {
        $this->src->user(1, ['balance' => 10.5]);
        $this->hist->walletEntry(1, 1, '10.00', '10.00');
        $this->hist->walletEntry(2, 1, 0.5, 10.5);
        $this->store(10, 1, 'JPY');
        $this->store(20, 1, 'USD');
        $this->store(30, 1, 'KWD');
        $this->src->item(100, 10, null, 1250);
        $this->src->item(200, 20, null, 9.995);
        $this->src->item(300, 30, null, 1.2345);
        foreach ([10 => 100, 20 => 200, 30 => 300] as $store => $item) {
            $this->src->order($store * 100, $store);
            $this->src->orderItem($store * 1000, $store * 100, $item, 2);
        }
        $this->hist->billingTransaction(1, 1, 3.5, 'KWD');
        $this->hist->billingTransaction(2, 1, 1200, 'JPY');
        $this->hist->billingReceipt(1, 1, 1, '3.5000', 'KWD');
        $this->hist->billingReceipt(2, 1, 2, 1200, null); // no currency: the platform's (USD)

        $report = $this->import();

        // exponent 0 / 2 / 3: unit price, subtotal and grand total in minor units of the store currency
        foreach ([10 => [1250, 'JPY'], 20 => [1000, 'USD'], 30 => [1235, 'KWD']] as $store => [$unit, $currency]) {
            $this->assertSame($unit, (int) DB::table('order_items')->where('id', $store * 1000)->value('unit_price_minor'), (string) $store);
            $order = DB::table('orders')->where('id', $store * 100)->first();
            $this->assertSame($currency, $order->currency_code);
            $this->assertSame(2 * $unit, (int) $order->subtotal_minor);
            $this->assertSame(2 * $unit, (int) $order->grand_total_minor);
            $this->assertSame(0, (int) $order->tax_total_minor);
            $this->assertSame($currency, $report['store_totals'][(string) $store]['currency_code']);
            $this->assertSame(2 * $unit, $report['store_totals'][(string) $store]['grand_total_minor_sum']);
        }

        // Platform wallet: platform currency (USD, 2 decimals), account of the owner.
        $wallet = DB::table('wallet_transactions')->orderBy('id')->get();
        $this->assertSame([1000, 50], $wallet->pluck('amount_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1000, 1050], $wallet->pluck('balance_after_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['USD', 'USD'], $wallet->pluck('currency_code')->all());
        $this->assertSame(1, (int) $wallet[0]->account_id);
        $this->assertSame(1050, (int) DB::table('users')->where('id', 1)->value('balance_minor'));

        // Billing history: each row in its OWN currency.
        $this->assertSame(3500, (int) DB::table('transaction')->where('id', 1)->value('amount_minor'));
        $this->assertSame('KWD', DB::table('transaction')->where('id', 1)->value('currency_code'));
        $this->assertSame(1200, (int) DB::table('transaction')->where('id', 2)->value('amount_minor'));
        $this->assertSame(1, (int) DB::table('transaction')->where('id', 1)->value('account_id'));
        $this->assertSame(3500, (int) DB::table('billing_receipts')->where('id', 1)->value('amount_minor'));
        $this->assertSame(1, (int) DB::table('billing_receipts')->where('id', 1)->value('transaction_id'));
        $this->assertSame(120000, (int) DB::table('billing_receipts')->where('id', 2)->value('amount_minor'));
        $this->assertSame('USD', DB::table('billing_receipts')->where('id', 2)->value('currency_code'));
        foreach (['wallet_transactions', 'transaction', 'billing_receipts'] as $table) {
            $this->assertSame(DB::table($table)->count(), DB::table('legacy_import_rows')->where('target_table', $table)->count(), $table);
        }
    }

    public function test_order_payments_belong_to_their_store_never_to_the_user_with_the_same_id(): void
    {
        $this->src->user(1);
        $this->src->user(10); // a different person whose id equals the store id
        $this->store(10, 1, 'KWD');
        $this->src->order(5000, 10);
        // The source wrote an order payment with user_id = the STORE id and product_id = the order id.
        $this->hist->billingTransaction(7, 10, 12.5, 'KWD', ['transaction_method' => 'order', 'product_id' => 5000]);
        $this->hist->billingReceipt(8, 10, 7, 12.5, 'KWD', ['transaction_method' => 'order', 'product_id' => 5000]);
        $this->hist->billingTransaction(9, 10, 4.0, 'USD'); // user 10's own plan payment

        $this->import();

        $payment = DB::table('transaction')->where('id', 7)->first();
        $this->assertNull($payment->user_id);
        $this->assertSame([10, 1, 12500, 'KWD'], [(int) $payment->store_id, (int) $payment->account_id, (int) $payment->amount_minor, $payment->currency_code]);
        $receipt = DB::table('billing_receipts')->where('id', 8)->first();
        $this->assertNull($receipt->user_id);
        $this->assertSame([10, 1, 7, 12500], [(int) $receipt->store_id, (int) $receipt->account_id, (int) $receipt->transaction_id, (int) $receipt->amount_minor]);
        $plan = DB::table('transaction')->where('id', 9)->first();
        $this->assertSame(10, (int) $plan->user_id);
        $this->assertNull($plan->store_id);
        $this->assertNull($plan->account_id); // user 10 owns no store: no account
    }

    public function test_line_prices_are_recorded_or_snapshotted_from_the_menu_and_marked(): void
    {
        $this->hist->addSnapshotColumns();
        $this->src->user(1);
        $this->store(10, 1, 'SAR');
        $this->src->item(100, 10, null, 10.00);
        $this->src->item(101, 10, null, 4.00);
        $this->hist->variant(7, 100, 12.5);
        $this->hist->variant(8, 101, 99); // a variant of ANOTHER item: never applied to item 100
        $this->hist->extra(50, 100, 1.25);
        $this->src->order(5000, 10, ['tip_amount' => 1.00]);
        $this->src->orderItem(9000, 5000, 100, 2, ['variation' => 7]);
        $this->hist->orderItemExtra(9500, 9000, 50);
        $this->src->orderItem(9001, 5000, 100, 1, ['variation' => 8]);
        $this->src->orderItem(9002, 5000, 4242, 1); // deleted menu item
        // Later source version: the line price and the totals were recorded and win.
        $this->src->order(5001, 10, ['subtotal' => 8.75, 'tax_total' => 0.5, 'grand_total' => 9.25]);
        $this->src->orderItem(9100, 5001, 100, 1, ['unit_price' => 8.75]);

        $report = $this->import();

        $line = fn (int $id): ?int => ($v = DB::table('order_items')->where('id', $id)->value('unit_price_minor')) === null ? null : (int) $v;
        $this->assertSame(1250, $line(9000)); // variant price
        $this->assertSame(1000, $line(9001)); // item price
        $this->assertNull($line(9002));
        $this->assertSame(875, $line(9100)); // recorded
        $this->assertSame(125, (int) DB::table('order_item_extras')->where('id', 9500)->value('price_minor'));
        $this->assertSame('SAR', DB::table('order_item_extras')->where('id', 9500)->value('currency_code'));

        $this->assertSame([HistoryImporter::PRICE_SNAPSHOT], $this->marks('order_items', 9000));
        $this->assertSame('variant', json_decode((string) DB::table('legacy_import_marks')->where('target_id', '9000')->value('detail'), true)['basis']);
        $this->assertSame([HistoryImporter::PRICE_SNAPSHOT], $this->marks('order_items', 9001));
        $this->assertSame([HistoryImporter::PRICE_UNKNOWN], $this->marks('order_items', 9002));
        $this->assertSame([], $this->marks('order_items', 9100));
        $this->assertSame([HistoryImporter::PRICE_SNAPSHOT], $this->marks('order_item_extras', 9500));

        // Derived totals: lines (extras per unit of their line), tax 0 (not recorded), + tip; incomplete (a deleted item).
        $order = DB::table('orders')->where('id', 5000)->first();
        $this->assertSame(1250 * 2 + 125 * 2 + 1000, (int) $order->subtotal_minor);
        $this->assertSame(0, (int) $order->tax_total_minor);
        $this->assertSame(3750 + 100, (int) $order->grand_total_minor);
        $this->assertSame([HistoryImporter::TAX_NOT_RECORDED, HistoryImporter::TOTAL_DERIVED, HistoryImporter::TOTAL_INCOMPLETE], $this->marks('orders', 5000));
        // Recorded totals: kept as recorded, no mark.
        $recorded = DB::table('orders')->where('id', 5001)->first();
        $this->assertSame([875, 50, 925], [(int) $recorded->subtotal_minor, (int) $recorded->tax_total_minor, (int) $recorded->grand_total_minor]);
        $this->assertSame([], $this->marks('orders', 5001));

        $this->assertSame(2, $report['counters']['mark:order_items:price_snapshot']);
        $totals = $report['store_totals']['10'];
        $this->assertSame(2, $totals['orders']);
        $this->assertSame(2, $totals['orders_source']);
        $this->assertSame(4, $totals['order_items']);
        $this->assertSame(5, $totals['quantity_sum']);
        $this->assertSame(3750 + 875, $totals['subtotal_minor_sum']);
        $this->assertSame(3850 + 925, $totals['grand_total_minor_sum']);
        $this->assertSame(2, $totals['lines_price_snapshot']);
        $this->assertSame(1, $totals['lines_price_unknown']);
        $this->assertSame(1, $totals['orders_total_derived']);
    }

    public function test_a_recorded_subtotal_that_disagrees_with_its_lines_is_listed_never_changed(): void
    {
        $this->hist->addSnapshotColumns();
        $this->src->user(1);
        $this->store(10, 1, 'SAR');
        $this->src->item(100, 10, null, 10.00);
        $this->src->order(5000, 10, ['subtotal' => 30.00]);
        $this->src->orderItem(9000, 5000, 100, 2, ['unit_price' => 10.00]);
        $this->src->order(5001, 10, ['subtotal' => 10.01]);
        $this->src->orderItem(9001, 5001, 100, 1, ['unit_price' => 10.00]);

        $report = $this->import();

        $this->assertSame(3000, (int) DB::table('orders')->where('id', 5000)->value('subtotal_minor'));
        $this->assertSame(1, $report['conflicts'][HistoryImporter::REVIEW_KIND]['count']);
        $this->assertSame(['order#5000'], $report['conflicts'][HistoryImporter::REVIEW_KIND]['samples']);
        $this->assertSame(1, $report['counters']['orders_subtotal_rounding_1_minor']);
        $this->assertSame(1001, (int) DB::table('orders')->where('id', 5001)->value('subtotal_minor'));
        $this->assertContains(HistoryImporter::SUBTOTAL_DIFF, $this->marks('orders', 5000));
    }

    public function test_an_order_recorded_in_another_currency_is_imported_in_that_currency_and_reported(): void
    {
        // J58a: the order keeps its own currency (own exponent), is reported and stays out of the store's money sums.
        $this->hist->addSnapshotColumns();
        $this->src->user(1);
        $this->store(10, 1, 'SAR');
        $this->src->item(100, 10, null, 10.00);
        $this->src->order(5000, 10, ['currency_code' => 'KWD', 'grand_total' => 1.2345, 'tip_amount' => 0.5]);
        $this->src->orderItem(9000, 5000, 100, 1);
        $this->src->orderItem(9001, 5000, 100, 2, ['unit_price' => 0.25]);
        $this->src->order(5001, 10, ['currency_code' => 'sar']);
        $this->src->order(5002, 10, ['currency_code' => 'XYZ']);

        $report = $this->import();

        $order = DB::table('orders')->where('id', 5000)->first();
        $this->assertSame('KWD', $order->currency_code);
        $this->assertSame(1235, (int) $order->grand_total_minor);
        $this->assertSame(500, (int) $order->tip_amount_minor);
        // No menu snapshot can stand for a price paid in another currency; a recorded one is kept in it.
        $this->assertNull(DB::table('order_items')->where('id', 9000)->value('unit_price_minor'));
        $this->assertSame('KWD', DB::table('order_items')->where('id', 9000)->value('currency_code'));
        $this->assertSame(250, (int) DB::table('order_items')->where('id', 9001)->value('unit_price_minor'));
        $this->assertContains(HistoryImporter::OTHER_CURRENCY, $this->marks('orders', 5000));
        $this->assertSame('SAR', DB::table('orders')->where('id', 5001)->value('currency_code'));
        $this->assertFalse(DB::table('orders')->where('id', 5002)->exists());
        $this->assertSame(['order#5000 KWD'], $report['conflicts'][HistoryImporter::OTHER_CURRENCY_KIND]['samples']);
        $this->assertSame(1, $report['conflicts']['orders: order currency unknown to this install (not converted)']['count']);
        $this->assertSame(1, $report['store_totals']['10']['orders_other_currency']);
        $this->assertSame(0, $report['store_totals']['10']['grand_total_minor_sum']);
    }

    public function test_a_loyalty_ledger_that_does_not_sum_to_the_balance_blocks_the_store(): void
    {
        $this->src->user(1);
        $this->store(10, 1, 'SAR');
        $this->store(20, 1, 'SAR');
        $this->store(30, 1, 'SAR');
        $this->src->customer(300, 10);
        $this->src->loyaltyAccount(81, 10, 300, ['balance' => 7]);
        $this->src->ledger(83, 81, 5, 5);
        $this->src->customer(400, 20);
        $this->src->loyaltyAccount(82, 20, 400, ['balance' => 7]);
        $this->src->ledger(84, 82, 10, 10);
        $this->src->ledger(85, 82, -3, 7);
        $this->src->customer(500, 30);
        $this->src->loyaltyAccount(86, 30, 500, ['balance' => 4]); // a balance with no ledger rows

        $report = $this->import([], 1);

        $this->assertSame([20], DB::table('stores')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(7, (int) DB::table('loyalty_accounts')->where('id', 82)->value('balance'));
        $kind = 'blocked: stores: '.LedgerChecks::LOYALTY_REASON;
        $this->assertSame(2, $report['conflicts'][$kind]['count']);
        $this->assertSame(['store#10 loyalty_account#81', 'store#30 loyalty_account#86'], $report['conflicts'][$kind]['samples']);
        $this->assertFalse(DB::table('customers')->where('id', 300)->exists());
        $this->assertArrayNotHasKey('10', $report['store_totals']);

        // Corrected at the source: the next run imports the blocked stores (never "fixed" by the importer).
        DB::connection(self::SOURCE)->table('loyalty_accounts')->where('id', 81)->update(['balance' => 5]);
        DB::connection(self::SOURCE)->table('loyalty_accounts')->where('id', 86)->update(['balance' => 0]);
        $this->import();
        $this->assertEqualsCanonicalizing([10, 20, 30], DB::table('stores')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(5, (int) DB::table('loyalty_accounts')->where('id', 81)->value('balance'));
    }

    public function test_a_wallet_ledger_that_does_not_sum_to_the_balance_blocks_the_owner_and_their_stores(): void
    {
        $this->src->user(1, ['balance' => 5.00]);
        $this->hist->walletEntry(1, 1, 3.00, 3.00);
        $this->src->user(2, ['balance' => 2.5]);
        $this->hist->walletEntry(2, 2, 2.5, 2.5);
        $this->src->user(3, ['balance' => 1.0]);
        $this->hist->walletEntry(3, 3, 1.0, 1.0, ['currency_code' => 'EUR']); // another currency: cannot be summed
        $this->store(10, 1, 'SAR');
        $this->store(20, 2, 'SAR');
        $this->hist->billingTransaction(1, 1, 9.99, 'USD');

        $report = $this->import([], 1);

        $this->assertSame([2], DB::table('users')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([20], DB::table('stores')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([2], DB::table('wallet_transactions')->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertFalse(DB::table('transaction')->exists());
        $this->assertSame(['user#1'], $report['conflicts']['blocked: users: wallet ledger does not sum to the user balance']['samples']);
        $this->assertSame(['user#3'], $report['conflicts']['blocked: users: wallet ledger row in another currency than the platform currency']['samples']);
        $this->assertSame(['store#10'], $report['conflicts']['blocked: stores: owner blocked (wallet ledger does not sum to the user balance)']['samples']);
        $this->assertSame(0, DB::table('accounts')->where('id', 1)->count());

        DB::connection(self::SOURCE)->table('user')->where('id', 1)->update(['balance' => 3.00]);
        $this->import([], 1); // user 3 is still blocked
        $this->assertEqualsCanonicalizing([1, 2], DB::table('users')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertEqualsCanonicalizing([10, 20], DB::table('stores')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(999, (int) DB::table('transaction')->where('id', 1)->value('amount_minor'));
    }

    public function test_the_import_fires_no_model_events_jobs_mail_or_notifications(): void
    {
        $this->src->user(1, ['balance' => 1.0]);
        $this->hist->walletEntry(1, 1, 1.0, 1.0);
        $this->store(10, 1, 'SAR');
        $this->src->item(100, 10, null, 3.00);
        $this->src->customer(300, 10);
        $this->src->order(5000, 10, ['customer_id' => 300, 'is_paid' => true, 'status' => 'completed']);
        $this->src->orderItem(9000, 5000, 100, 1);
        $this->src->loyaltyAccount(81, 10, 300, ['balance' => 3]);
        $this->src->ledger(83, 81, 3, 3, ['order_id' => 5000]);
        $this->hist->billingTransaction(1, 1, 9.99, 'USD');
        $this->hist->billingReceipt(1, 1, 1, 9.99, 'USD');

        $events = [];
        Event::listen('eloquent.*', function (string $name) use (&$events): void {
            $events[] = $name;
        });
        Queue::fake();
        Mail::fake();
        Notification::fake();

        $this->import();

        $this->assertTrue(DB::table('orders')->where('id', 5000)->exists());
        $this->assertSame([], $events);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        foreach (['outbox_messages', 'fiscal_documents', 'webhook_deliveries'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        }
    }

    public function test_imported_history_is_read_only_through_the_application_models(): void
    {
        $this->src->user(1);
        $this->store(10, 1, 'SAR');
        $this->src->item(100, 10, null, 3.00);
        $this->src->customer(300, 10);
        $this->src->order(5000, 10, ['customer_id' => 300]);
        $this->src->orderItem(9000, 5000, 100, 1);
        $this->src->loyaltyAccount(81, 10, 300, ['balance' => 3]);
        $this->src->ledger(83, 81, 3, 3, ['note' => 'synthetic']);
        $this->hist->billingTransaction(1, 1, 9.99, 'USD');
        $this->import();
        LegacyCompatServiceProvider::activate($this->app);

        $order = Order::withoutGlobalScopes()->findOrFail(5000);
        $this->assertRefused(fn () => $order->forceFill(['status' => 'completed'])->save(), 'orders#5000', 'status');
        $this->assertRefused(fn () => Order::withoutGlobalScopes()->findOrFail(5000)->forceFill(['grand_total_minor' => 1])->save(), 'orders#5000', 'grand_total_minor');
        $this->assertRefused(fn () => Order::withoutGlobalScopes()->findOrFail(5000)->delete(), 'orders#5000', 'delete');
        $this->assertRefused(fn () => OrderItem::withoutGlobalScopes()->findOrFail(9000)->forceFill(['quantity' => 5])->save(), 'order_items#9000', 'quantity');
        $this->assertRefused(fn () => LoyaltyLedger::query()->findOrFail(83)->forceFill(['delta' => 30])->save(), 'loyalty_ledger_entries#83', 'delta');
        $this->assertRefused(fn () => Transaction::withoutGlobalScopes()->findOrFail(1)->forceFill(['status' => 'refunded'])->save(), 'transaction#1', 'status');
        $this->assertSame('pending', DB::table('orders')->where('id', 5000)->value('status'));
        $this->assertSame(1, (int) DB::table('order_items')->where('id', 9000)->value('quantity'));

        // Allowed: display flags / notes, and the privacy erasure's query-builder blanking.
        LoyaltyLedger::query()->findOrFail(83)->forceFill(['note' => null])->save();
        $this->assertNull(DB::table('loyalty_ledger_entries')->where('id', 83)->value('note'));
        DB::table('orders')->where('id', 5000)->update(['customer_name' => null, 'phone_number' => null, 'customer_id' => null]);
        $this->assertNull(DB::table('orders')->where('id', 5000)->value('customer_name'));

        // A row created on this install is not history.
        DB::table('loyalty_ledger_entries')->insert(['id' => 2000, 'account_id' => 1, 'store_id' => 10, 'loyalty_account_id' => 81, 'type' => 'earn', 'delta' => 1, 'balance_after' => 4]);
        LoyaltyLedger::query()->findOrFail(2000)->forceFill(['delta' => 2])->save();
        $this->assertSame(2, (int) DB::table('loyalty_ledger_entries')->where('id', 2000)->value('delta'));

        // Module switched off: the guard steps aside (the switch is re-read per write).
        config(['legacy_compat.enabled' => false]);
        LoyaltyLedger::query()->findOrFail(83)->forceFill(['delta' => 4])->save();
        $this->assertSame(4, (int) DB::table('loyalty_ledger_entries')->where('id', 83)->value('delta'));
    }

    private function assertRefused(callable $write, string $row, string $what): void
    {
        try {
            $write();
            $this->fail("{$row}: {$what} was not refused");
        } catch (LegacyHistoryReadOnly $e) {
            $this->assertStringContainsString($row, $e->getMessage());
            $this->assertStringContainsString($what, $e->getMessage());
        }
    }

    public function test_dry_run_reports_totals_and_writes_no_history_or_marks(): void
    {
        $this->src->user(1, ['balance' => 2.0]);
        $this->hist->walletEntry(1, 1, 2.0, 2.0);
        $this->store(10, 1, 'SAR');
        $this->src->item(100, 10, null, 3.00);
        $this->src->order(5000, 10);
        $this->src->orderItem(9000, 5000, 100, 2);
        $this->hist->billingTransaction(1, 1, 9.99, 'USD');

        $report = $this->import(['--dry-run' => true]);

        $this->assertSame(600, $report['store_totals']['10']['grand_total_minor_sum']);
        $this->assertSame(1, $report['counters']['mark:order_items:price_snapshot']);
        foreach (['orders', 'order_items', 'wallet_transactions', 'transaction', 'legacy_import_marks', 'legacy_import_rows'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
