<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting\Gl;

use Illuminate\Support\Facades\DB;
use Juwri\Modules\Accounting\Models\GlAccount;

/**
 * SYSTEMS-RUN S0: the default chart-of-accounts template (KSA-flavored,
 * bilingual en/ar) and its idempotent per-restaurant seeder. Posting rules
 * (PostingRules) resolve accounts by these stable system CODES, so the codes
 * are a contract — do not renumber a shipped code.
 */
class ChartOfAccounts
{
    /** J32: pre-VAT levies collected for a third party (municipality / tourism fee, table tax, stamp duty). */
    public const LEVIES_PAYABLE = '2210';

    /**
     * Default template: [code, type, en, ar, parent_code|null].
     * Hierarchical: parents first so children can resolve parent_id.
     */
    public static function template(): array
    {
        return [
            // Assets (1xxx)
            ['1000', 'asset', 'Assets', 'الأصول', null],
            ['1100', 'asset', 'Cash & Bank', 'النقد والبنك', '1000'],
            ['1110', 'asset', 'Cash on Hand', 'النقد في الصندوق', '1100'],
            ['1120', 'asset', 'Bank Account', 'الحساب البنكي', '1100'],
            ['1130', 'asset', 'Payment Gateway Clearing', 'وسيط بوابة الدفع', '1100'],
            ['1200', 'asset', 'Accounts Receivable', 'الذمم المدينة', '1000'],
            ['1300', 'asset', 'Inventory', 'المخزون', '1000'],
            ['1400', 'asset', 'VAT Receivable (Input)', 'ضريبة القيمة المضافة القابلة للخصم', '1000'],
            // Liabilities (2xxx)
            ['2000', 'liability', 'Liabilities', 'الالتزامات', null],
            ['2100', 'liability', 'Accounts Payable', 'الذمم الدائنة', '2000'],
            ['2200', 'liability', 'VAT Payable (Output)', 'ضريبة القيمة المضافة المستحقة', '2000'],
            ['2210', 'liability', 'Levies Payable', 'الرسوم المستحقة', '2000'], // J32: pre-VAT levies (municipality fee, stamp duty)
            ['2300', 'liability', 'Wages Payable', 'الأجور المستحقة', '2000'],
            ['2310', 'liability', 'Tips Payable', 'الإكراميات المستحقة', '2000'],
            ['2400', 'liability', 'Gift Card Liability', 'التزام بطاقات الهدايا', '2000'],
            // Equity (3xxx)
            ['3000', 'equity', 'Equity', 'حقوق الملكية', null],
            ['3100', 'equity', 'Retained Earnings', 'الأرباح المحتجزة', '3000'],
            // Revenue (4xxx)
            ['4000', 'revenue', 'Revenue', 'الإيرادات', null],
            ['4100', 'revenue', 'Food & Beverage Sales', 'مبيعات الأطعمة والمشروبات', '4000'],
            ['4200', 'revenue', 'Delivery Fees', 'رسوم التوصيل', '4000'],
            ['4900', 'revenue', 'Discounts & Refunds', 'الخصومات والمستردات', '4000'],
            // Expenses (5xxx / 6xxx)
            ['5000', 'expense', 'Cost of Goods Sold', 'تكلفة البضاعة المباعة', null],
            ['5100', 'expense', 'Food Cost', 'تكلفة الطعام', '5000'],
            ['6000', 'expense', 'Operating Expenses', 'المصاريف التشغيلية', null],
            ['6100', 'expense', 'Wages & Salaries', 'الأجور والرواتب', '6000'],
            ['6110', 'expense', 'Tips Expense', 'مصروف الإكراميات', '6000'],
            ['6200', 'expense', 'Rent', 'الإيجار', '6000'],
            ['6300', 'expense', 'Utilities', 'المرافق', '6000'],
            ['6400', 'expense', 'Payment Processing Fees', 'رسوم معالجة الدفع', '6000'],
            ['6900', 'expense', 'Other Expenses', 'مصاريف أخرى', '6000'],
        ];
    }

    /**
     * Seed the default chart for a restaurant. Idempotent: existing codes are
     * left untouched, missing ones are inserted, parent links resolved by code.
     */
    public static function seed(int $storeId): void
    {
        DB::transaction(function () use ($storeId) {
            $existing = GlAccount::where('store_id', $storeId)
                ->pluck('id', 'code')->all();

            foreach (self::template() as [$code, $type, $en, $ar, $parentCode]) {
                if (isset($existing[$code])) {
                    continue;
                }
                $parentId = $parentCode ? ($existing[$parentCode] ?? null) : null;
                $acct = GlAccount::create([
                    'store_id' => $storeId,
                    'code' => $code,
                    'name' => ['en' => $en, 'ar' => $ar],
                    'type' => $type,
                    'parent_id' => $parentId,
                    'system' => true,
                    'archived' => false,
                ]);
                $existing[$code] = $acct->id;
            }
        });
    }

    /** Seed once if the restaurant has no chart yet. Safe to call on every visit. */
    public static function ensure(int $storeId): void
    {
        if (! GlAccount::where('store_id', $storeId)->exists()) {
            self::seed($storeId);
        }
    }
}
