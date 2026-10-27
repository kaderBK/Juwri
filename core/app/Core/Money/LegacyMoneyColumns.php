<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money;

/**
 * P1-05: decimal money columns that became `*_minor` integers (+ the row's `currency_code`). Raw row paths —
 * restores of older archives, imports, raw inserts — call upgradeRow() so a row that still carries the old
 * major-unit key keeps its amount instead of losing it to an unknown-column filter.
 */
final class LegacyMoneyColumns
{
    /** @var array<string, array<string, string>> table => [old major column => new minor column] */
    public const TABLES = [
        'menu' => ['price' => 'price_minor', 'cost' => 'cost_minor'], // legacy archive table name
        'items' => ['price' => 'price_minor', 'cost' => 'cost_minor'], // P1-06: the renamed table
        'menu_variants' => ['price' => 'price_minor'],
        'menu_extras' => ['price' => 'price_minor'],
        'menu_item_extra' => ['price_override' => 'price_override_minor'],
        'branch_menu' => ['price' => 'price_minor'],
        'combos' => ['price' => 'price_minor'],
        'combo_slot_options' => ['upcharge' => 'upcharge_minor'],
        'global_menu_extras' => ['price' => 'price_minor'],
        // slice 3 — orders (amounts in the order's currency_code)
        'orders' => [
            'subtotal' => 'subtotal_minor', 'tax_total' => 'tax_total_minor', 'grand_total' => 'grand_total_minor',
            'discount_amount' => 'discount_amount_minor', 'wallet_applied' => 'wallet_applied_minor',
            'mk_wallet_applied' => 'mk_wallet_applied_minor', 'gift_card_applied' => 'gift_card_applied_minor',
            'tip_amount' => 'tip_amount_minor', 'channel_fee' => 'channel_fee_minor',
            'amount_tendered' => 'amount_tendered_minor', 'change_due' => 'change_due_minor',
            'service_charge_amount' => 'service_charge_amount_minor', 'donation_amount' => 'donation_amount_minor',
        ],
        'order_items' => ['unit_price' => 'unit_price_minor'],
        'order_item_extras' => ['price' => 'price_minor'],
        'order_adjustments' => ['amount' => 'amount_minor'],
        'carts' => ['tip_amount' => 'tip_amount_minor'],
        'catering_requests' => ['quote_total' => 'quote_total_minor', 'deposit_amount' => 'deposit_amount_minor'],
        // slice 4 — platform billing (amounts in the row's currency_code; plans/add-ons: the platform currency)
        'transaction' => ['amount' => 'amount_minor', 'base_amount' => 'base_amount_minor'],
        'billing_receipts' => ['amount' => 'amount_minor', 'base_amount' => 'base_amount_minor'],
        'plans' => ['monthly_price' => 'monthly_price_minor', 'annual_price' => 'annual_price_minor', 'lifetime_price' => 'lifetime_price_minor'],
        'plan_prices' => ['price' => 'price_minor'],
        'addon_products' => ['price' => 'price_minor'],
        'account_addons' => ['quoted_price' => 'quoted_price_minor'],
        // slice 5 — payments (amounts in the row's currency: the order's for shares/refund lines, the gateway's for disputes)
        'check_payments' => ['amount' => 'amount_minor'],
        'order_refund_items' => ['amount' => 'amount_minor'],
        'disputes' => ['amount' => 'amount_minor'],
        'ledger_entries' => ['amount' => 'amount_minor', 'balance_after' => 'balance_after_minor'], // the account's currency
        'wallet_transactions' => ['amount' => 'amount_minor', 'balance_after' => 'balance_after_minor'], // platform currency
        // slice 6a — platform → reseller wholesale (the platform currency)
        'resellers' => ['base_fee' => 'base_fee_minor', 'seat_price' => 'seat_price_minor'],
        'reseller_tiers' => [
            'monthly_price' => 'monthly_price_minor', 'annual_price' => 'annual_price_minor',
            'base_fee' => 'base_fee_minor', 'seat_price' => 'seat_price_minor',
        ],
        'reseller_invoices' => ['base_fee' => 'base_fee_minor', 'seat_total' => 'seat_total_minor', 'total' => 'total_minor'],
        // slice 6b — a reseller's retail catalogue + its customers' subscriptions (the reseller's selling currency)
        'reseller_plans' => [
            'monthly_price' => 'monthly_price_minor', 'annual_price' => 'annual_price_minor', 'setup_fee' => 'setup_fee_minor',
        ],
        'reseller_customer_subscriptions' => ['amount' => 'amount_minor'],
        // slice 6c — store/account money
        'reservations' => ['deposit_amount' => 'deposit_amount_minor'], // the store's currency (`currency`)
        'stores' => ['mk_min_order' => 'mk_min_order_minor'], // the store's currency (`mk_min_order_currency`)
        'posts' => ['mk_min_order' => 'mk_min_order_minor'], // legacy archive table name
        'users' => ['balance' => 'balance_minor'], // the platform currency, no currency column
        // slice 8a — inventory (the store's currency; PO lines: their purchase order's; supplier payments: the supplier's store)
        'ingredients' => ['cost_per_unit' => 'cost_per_unit_minor'],
        'purchase_orders' => ['total' => 'total_minor'],
        'purchase_order_items' => [
            'unit_cost' => 'unit_cost_minor', 'line_total' => 'line_total_minor', 'actual_unit_cost' => 'actual_unit_cost_minor',
        ],
        'stock_movements' => ['unit_cost' => 'unit_cost_minor'],
        'supplier_invoices' => ['total' => 'total_minor'],
        'supplier_payments' => ['amount' => 'amount_minor'],
        'waste_logs' => ['cost' => 'cost_minor'],
        // slice 8b — hotel (the store's currency; bookings: the quote's `currency`) — rate_plans.modifier_value is not
        // listed: it stays a column (the percent) and RatePlan reads an archived fixed value from it until it is saved
        'room_types' => ['base_rate' => 'base_rate_minor'],
        'room_rates' => ['price' => 'price_minor'],
        'hotel_extra_services' => ['price' => 'price_minor'],
        'hotel_event_items' => ['unit_price' => 'unit_price_minor'],
        'hotel_bookings' => ['quoted_total' => 'quoted_total_minor'],
        // slice 8b — staff / payroll (the store's currency; payroll items: their run's)
        'staff_members' => ['hourly_rate' => 'hourly_rate_minor', 'base_wage' => 'base_wage_minor'],
        'restaurant_staff' => ['hourly_rate' => 'hourly_rate_minor', 'base_wage' => 'base_wage_minor'], // legacy archive table name
        'staff_role_rates' => ['hourly_rate' => 'hourly_rate_minor'],
        'payroll_runs' => ['total_net' => 'total_net_minor'],
        'payroll_items' => [
            'rate' => 'rate_minor', 'base_pay' => 'base_pay_minor', 'overtime_pay' => 'overtime_pay_minor', 'tips' => 'tips_minor',
            'bonuses' => 'bonuses_minor', 'deductions' => 'deductions_minor', 'net' => 'net_minor',
        ],
        // slice 8c — app / theme marketplace (the platform currency; partner earnings: their `currency`).
        // apps.usage_price is not listed: a per-unit rate with sub-minor precision, it stays a decimal column.
        'apps' => ['price' => 'price_minor'],
        'app_usage_records' => ['charged' => 'charged_minor'],
        'partner_earnings' => ['gross' => 'gross_minor', 'net' => 'net_minor'],
        'seller_withdrawals' => ['amount' => 'amount_minor'],
        'themes' => ['price' => 'price_minor'],
        'theme_purchases' => ['price_paid' => 'price_paid_minor'],
        // slice 8d — fiscal + accounting (documents: their `currency`; books: the store's currency). taxes.value is not
        // listed: it stays a column (the percent) and Tax reads an archived fixed value from it until it is saved.
        'fiscal_documents' => ['total' => 'total_minor', 'tax_total' => 'tax_total_minor'],
        'expenses' => ['amount' => 'amount_minor', 'tax' => 'tax_minor'],
        'accounting_journal_lines' => ['debit' => 'debit_minor', 'credit' => 'credit_minor'],
        'gl_journal_lines' => ['debit' => 'debit_minor', 'credit' => 'credit_minor'], // legacy archive table name
        // slice 7a — POS drawer shifts (the store's currency at open) and split-bill checks (the order's currency)
        'pos_shifts' => [
            'opening_float' => 'opening_float_minor', 'expected_cash' => 'expected_cash_minor',
            'counted_cash' => 'counted_cash_minor', 'over_short' => 'over_short_minor',
        ],
        'table_checks' => ['total' => 'total_minor'],
        // slice 7b — CRM lifetime spend (the store's), courier fee (the store's at dispatch), zone fees (the country's)
        'customers' => ['total_spent' => 'total_spent_minor'],
        'courier_deliveries' => ['fee' => 'fee_minor'],
        'zones' => ['base_fee' => 'base_fee_minor', 'per_km_fee' => 'per_km_fee_minor', 'min_order' => 'min_order_minor'],
        // slice 7c — loyalty (coupons: the issuer's currency; gift cards: `currency`; programs/schedules: the store's)
        'coupons' => ['min_order_amount' => 'min_order_amount_minor', 'percentage' => 'amount_minor'], // percentage: fixed only — see SPLIT_COLUMNS
        'gift_cards' => ['initial_value' => 'initial_value_minor'],
        'loyalty_programs' => ['reward_discount' => 'reward_discount_minor'],
        'price_schedules' => ['adjustment_value' => 'adjustment_minor'], // amount types only — see SPLIT_COLUMNS
        // slice 7d — Fusion shopper wallet ledger (the row's `currency`)
        'shopper_wallet_ledger_entries' => ['amount' => 'amount_minor', 'balance_after' => 'balance_after_minor'],
    ];

    /**
     * Old columns that held a percentage OR an amount by the row's type (owner decision 2026-10-01: split by type).
     * table => [old column => [type column, rate types, rate column, minor column]]. A rate type's value moves to the
     * rate column unchanged; any other type's value is money (TABLES).
     *
     * @var array<string, array<string, array{0: string, 1: list<string>, 2: string, 3: string}>>
     */
    public const SPLIT_COLUMNS = [
        'price_schedules' => ['adjustment_value' => ['adjustment_type', ['percent'], 'adjustment_rate', 'adjustment_minor']],
        // bug fix 2026-10-01: a coupon's value — a percent for every type but 'fixed' (free_delivery/bogo keep theirs)
        'coupons' => ['percentage' => ['coupon_type', ['percentage', 'free_delivery', 'bogo'], 'percentage_rate', 'amount_minor']],
    ];

    /** @var array<string, string|null> tables whose row currency column is not `currency_code` (null: none) */
    public const CURRENCY_COLUMNS = [
        'catering_requests' => 'currency', 'disputes' => 'currency', 'resellers' => 'wholesale_currency_code',
        'reservations' => 'currency', 'stores' => 'mk_min_order_currency', 'posts' => 'mk_min_order_currency',
        'users' => null,
        'hotel_bookings' => 'currency',
        'partner_earnings' => 'currency',
        'fiscal_documents' => 'currency',
        'gift_cards' => 'currency',
        'shopper_wallet_ledger_entries' => 'currency',
    ];

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function upgradeRow(string $table, array $row, ?string $currency): array
    {
        foreach (self::SPLIT_COLUMNS[$table] ?? [] as $old => [$typeColumn, $rateTypes, $rateColumn, $minorColumn]) {
            if (! array_key_exists($old, $row) || array_key_exists($rateColumn, $row) || array_key_exists($minorColumn, $row)) {
                continue;
            }
            $type = (string) ($row[$typeColumn] ?? $rateTypes[0]); // the column default type
            if (in_array($type, $rateTypes, true)) {
                $row[$rateColumn] = $row[$old] ?? 0;
                $row[$minorColumn] = null;
                unset($row[$old]);
            } else {
                $row[$rateColumn] = null; // the amount converts below
            }
        }
        $map = self::TABLES[$table] ?? [];
        if ($map === []) {
            return $row;
        }
        $column = array_key_exists($table, self::CURRENCY_COLUMNS) ? self::CURRENCY_COLUMNS[$table] : 'currency_code';
        $code = ($column !== null ? Currencies::normalize((string) ($row[$column] ?? '')) : '') ?: Currencies::normalize($currency);
        $converted = false;
        foreach ($map as $major => $minor) {
            if (! array_key_exists($major, $row)) {
                continue;
            }
            $value = $row[$major];
            unset($row[$major]);
            if (! array_key_exists($minor, $row)) {
                $row[$minor] = ($value === null || $value === '') ? null
                    : Money::minorFromMajor(is_numeric($value) ? $value : (float) $value, $code);
                $converted = true;
            }
        }
        if ($converted && $column !== null && $code !== '' && empty($row[$column])) {
            $row[$column] = $code;
        }

        return $row;
    }
}
