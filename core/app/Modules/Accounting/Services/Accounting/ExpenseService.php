<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;

use App\Services\FeatureRegistry;
use Illuminate\Support\Carbon;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Accounting\Models\Expense;
use Juwri\Modules\Accounting\Models\GlAccount;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;

/**
 * SYSTEMS-RUN A-P2: expense create/update + GL posting + recurring generation.
 * Posting (no-op unless accounting_gl on) is idempotent per (Expense, rule).
 */
class ExpenseService
{
    public function create(int $storeId, array $data): Expense
    {
        ChartOfAccounts::ensure($storeId);

        $paymentSource = ($data['payment_source'] ?? 'cash');
        $paymentSource = in_array($paymentSource, ['cash', 'payable'], true) ? $paymentSource : 'cash';
        $recurInterval = in_array(($data['recur_interval'] ?? null), ['weekly', 'monthly'], true) ? $data['recur_interval'] : null;

        // P1-05: amounts HALF_UP to the store currency's exponent (PHP-version independent).
        $exponent = Currencies::exponent(store_currency($storeId));
        $expense = Expense::create([
            'store_id' => $storeId,
            'branch_id' => $data['branch_id'] ?? null,
            'gl_account_id' => $data['gl_account_id'] ?? $this->defaultAccountId($storeId),
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'amount' => Money::round((float) ($data['amount'] ?? 0), $exponent),
            'tax' => Money::round((float) ($data['tax'] ?? 0), $exponent),
            'date' => $data['date'] ?? Carbon::now()->toDateString(),
            'supplier_id' => $data['supplier_id'] ?? null,
            'payment_source' => $paymentSource,
            'receipt_path' => $data['receipt_path'] ?? null,
            'recur_interval' => $recurInterval,
            'recur_active' => (bool) $recurInterval,
            'recur_next' => $recurInterval ? $this->advance($data['date'] ?? Carbon::now()->toDateString(), $recurInterval) : null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        $this->postJournal($expense);

        return $expense;
    }

    /** DR expense account / DR input-VAT (1400) / CR cash (1110) or AP (2100). */
    public function postJournal(Expense $expense): void
    {
        if (! FeatureRegistry::isEnabled('accounting_gl')) {
            return;
        }
        $rid = (int) $expense->store_id;
        // P1-05: the stored amounts (minor units in the expense's currency); gross = exact minor sum.
        $currency = $expense->minorMoneyCurrency();
        $netMinor = (int) $expense->amount_minor;
        $taxMinor = (int) $expense->tax_minor;
        if ($netMinor + $taxMinor <= 0) {
            return;
        }
        $net = Money::majorFromMinor($netMinor, $currency);
        $tax = Money::majorFromMinor($taxMinor, $currency);
        $gross = Money::majorFromMinor($netMinor + $taxMinor, $currency);

        $expenseAccount = $expense->gl_account_id
            ? JournalEngine::account($rid, GlAccount::find($expense->gl_account_id)->code ?? '6900')
            : JournalEngine::account($rid, '6900');

        $lines = [
            ['account' => $expenseAccount, 'debit' => $net, 'credit' => 0, 'memo' => $expense->description ?: 'Expense'],
        ];
        if ($tax > 0) {
            $lines[] = ['account' => JournalEngine::account($rid, '1400'), 'debit' => $tax, 'credit' => 0, 'memo' => 'Input VAT'];
        }
        $creditCode = $expense->payment_source === 'payable' ? '2100' : '1110';
        $lines[] = ['account' => JournalEngine::account($rid, $creditCode), 'debit' => 0, 'credit' => $gross, 'memo' => 'Expense paid'];

        JournalEngine::post($rid, $lines, 'Expense', (int) $expense->id, 'expense', $expense->description ?: 'Expense', $expense->date, $expense->branch_id ? (int) $expense->branch_id : null);
    }

    /** Generate due recurring expenses (idempotent per day: advances recur_next past today). */
    public function generateRecurring($today = null): int
    {
        $today = $today ? Carbon::parse($today) : Carbon::now();
        $created = 0;

        Expense::where('recur_active', true)
            ->whereNotNull('recur_interval')
            ->whereDate('recur_next', '<=', $today->toDateString())
            ->chunkById(100, function ($due) use (&$created, $today) {
                foreach ($due as $tpl) {
                    // Guard against generating multiple in one run: loop until next > today.
                    while ($tpl->recur_next && Carbon::parse($tpl->recur_next)->lte($today)) {
                        $runDate = Carbon::parse($tpl->recur_next)->toDateString();
                        $child = Expense::create([
                            'store_id' => $tpl->store_id,
                            'branch_id' => $tpl->branch_id,
                            'gl_account_id' => $tpl->gl_account_id,
                            'category' => $tpl->category,
                            'description' => $tpl->description,
                            'amount' => $tpl->amount,
                            'tax' => $tpl->tax,
                            'date' => $runDate,
                            'supplier_id' => $tpl->supplier_id,
                            'payment_source' => $tpl->payment_source,
                            'recur_parent_id' => $tpl->id,
                            'recur_active' => false,
                            'created_by' => $tpl->created_by,
                        ]);
                        $this->postJournal($child);
                        $created++;
                        $tpl->recur_next = $this->advance($tpl->recur_next, $tpl->recur_interval);
                    }
                    $tpl->save();
                }
            });

        return $created;
    }

    private function advance($date, string $interval): string
    {
        $d = Carbon::parse($date);

        // A monthly recurrence created on the 29th–31st must remain in the
        // following calendar month. Carbon's overflowing addMonth() can jump
        // from May 31 to July 1 and silently skip June.
        return ($interval === 'weekly' ? $d->addWeek() : $d->addMonthNoOverflow())->toDateString();
    }

    private function defaultAccountId(int $storeId): ?int
    {
        return GlAccount::where('store_id', $storeId)->where('code', '6900')->value('id');
    }
}
