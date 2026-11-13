<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Models\GlJournalLine;
use Juwri\Modules\Accounting\Services\Accounting\ExpenseService;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use Juwri\Modules\Fiscal\Models\Tax;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 8d: Fiscal and Accounting money in `*_minor` — document currency stamping and the sealed body
 * (amounts + currency), the billing-tax split (percentage stays a rate, fixed is money), journal legs in one
 * currency balancing in minor units, expenses keeping their currency, per-row SQL sums, legacy rows.
 */
class FiscalAccountingMinorMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'm-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    public function test_fiscal_documents_stamp_and_seal_their_currency(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store('KWD');
        $doc = FiscalChain::issue($store->id, 'pos', ['country' => 'KW', 'type' => 'simplified', 'total' => 1.235, 'tax_total' => 0.105]);
        $this->assertSame(['KWD', 1235, 105], [$doc->currency, $doc->total_minor, $doc->tax_total_minor]);
        $sar = FiscalChain::issue($store->id, 'pos', ['country' => 'SA', 'type' => 'simplified', 'total' => 115.5, 'tax_total' => 15.065, 'currency' => 'SAR']);
        $this->assertSame([11550, 1507], [$sar->total_minor, $sar->tax_total_minor], 'SAR stores halalas (a 4th-decimal tax rounds half up)');

        $doc->fresh()->transitionTo('submitted');
        try {
            $doc->fresh()->update(['currency' => 'USD']);
            $this->fail('the currency of a submitted document is sealed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot modify currency', $e->getMessage());
        }
        // A legacy submitted row without a currency gets one on its next write (first-time sealing is allowed).
        DB::table('fiscal_documents')->where('id', $doc->id)->update(['currency' => null]);
        $this->assertTrue($doc->fresh()->transitionTo('accepted'));
        $this->assertSame(['KWD', 1235], [$doc->fresh()->currency, $doc->fresh()->total_minor]);
    }

    public function test_billing_tax_split(): void
    {
        config(['settings.currency_code' => 'KWD']);
        $fixed = Tax::create(['name' => 'Fee', 'value' => 1.125, 'value_type' => 'fixed', 'type' => 'exclusive', 'billing_type' => 'both']);
        $row = DB::table('taxes')->where('id', $fixed->id)->first();
        $this->assertSame([null, 1125, 'KWD'], [$row->value, (int) $row->value_amount_minor, $row->currency_code]);
        $this->assertSame(1.125, $fixed->fresh()->value);
        $pct = Tax::create(['name' => 'VAT', 'value' => 5, 'value_type' => 'percentage', 'type' => 'exclusive', 'billing_type' => 'both']);
        $this->assertSame([5.0, null], [(float) DB::table('taxes')->where('id', $pct->id)->value('value'), DB::table('taxes')->where('id', $pct->id)->value('value_amount_minor')]);

        $fixed->fresh()->update(['value_type' => 'percentage', 'value' => 2]);
        $this->assertSame([2.0, null], [(float) DB::table('taxes')->where('id', $fixed->id)->value('value'), DB::table('taxes')->where('id', $fixed->id)->value('value_amount_minor')]);
        // An archived fixed tax whose amount still sits in `value` reads as before and moves on its next save.
        DB::table('taxes')->where('id', $pct->id)->update(['value_type' => 'fixed', 'value' => 0.75]);
        $this->assertSame(0.75, $pct->fresh()->value);
        $pct->fresh()->update(['name' => 'Moved']);
        $this->assertSame([null, 750], [DB::table('taxes')->where('id', $pct->id)->value('value'), (int) DB::table('taxes')->where('id', $pct->id)->value('value_amount_minor')]);
        // The invoice screens assign a computed amount for display without saving it.
        $shown = $pct->fresh();
        $shown->value = 3.3;
        $this->assertSame(3.3, $shown->value);
        $this->assertSame(3.3, $shown['value']);
    }

    public function test_journals_balance_in_minor_units_and_books_keep_their_currency(): void
    {
        config(['settings.currency_code' => 'USD', 'settings.admin_allow_accounting_gl' => true]);
        $store = $this->store('OMR');
        $expense = (new ExpenseService)->create($store->id, ['description' => 'Gas', 'amount' => 10.13, 'tax' => 0.5]);
        $this->assertSame(['OMR', 10130, 500], [$expense->currency_code, $expense->amount_minor, $expense->tax_minor]);
        $journal = GlJournal::where('store_id', $store->id)->firstOrFail();
        $legs = GlJournalLine::where('journal_id', $journal->id)->get();
        $this->assertSame(['OMR'], $legs->pluck('currency_code')->unique()->values()->all());
        $this->assertSame((int) $legs->sum('debit_minor'), (int) $legs->sum('credit_minor'));
        $this->assertSame(10630, (int) $legs->sum('credit_minor'));

        // The store moves to USD: posted books and expenses keep OMR; new journals post in USD.
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $expense->fresh()->update(['description' => 'Gas (edited)']);
        $this->assertSame(['OMR', 10130, '10.130'], [$expense->fresh()->currency_code, $expense->fresh()->amount_minor, $expense->fresh()->amount]);
        $j2 = JournalEngine::post($store->id, [
            ['account' => JournalEngine::account($store->id, '1110'), 'debit' => 5.25, 'credit' => 0],
            ['account' => JournalEngine::account($store->id, '4100'), 'debit' => 0, 'credit' => 5.25],
        ], 'Order', 1, 'sales', 'Sale');
        $this->assertSame(['USD'], GlJournalLine::where('journal_id', $j2->id)->pluck('currency_code')->unique()->values()->all());
        $this->assertSame(['5.25', '5.25'], [$j2->lines()->orderBy('id')->first()->debit, $j2->lines()->orderBy('id')->get()[1]->credit]);
        // SQL sums add each leg at its own exponent: cash = 5.25 USD in − 10.63 OMR out.
        $this->assertSame(-5.38, JournalEngine::account($store->id, '1110')->balance());
    }

    public function test_legacy_rows(): void
    {
        $this->assertSame(['currency' => 'JOD', 'total_minor' => 11125, 'tax_total_minor' => 1535],
            LegacyMoneyColumns::upgradeRow('fiscal_documents', ['currency' => 'JOD', 'total' => '11.1250', 'tax_total' => '1.5350'], 'USD'));
        $this->assertSame(['amount_minor' => 10000, 'tax_minor' => 1500, 'currency_code' => 'USD'],
            LegacyMoneyColumns::upgradeRow('expenses', ['amount' => '100.000', 'tax' => '15.000'], 'USD'));
        $this->assertSame(['debit_minor' => 0, 'credit_minor' => 10630, 'currency_code' => 'OMR'],
            LegacyMoneyColumns::upgradeRow('gl_journal_lines', ['debit' => '0.000', 'credit' => '10.630'], 'OMR'));
        $this->assertSame(['value' => '2.50', 'value_type' => 'fixed'], LegacyMoneyColumns::upgradeRow('taxes', ['value' => '2.50', 'value_type' => 'fixed'], 'USD'));
    }
}
