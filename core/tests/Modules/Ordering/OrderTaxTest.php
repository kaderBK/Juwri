<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use Tests\TestCase;

/**
 * Order::computeTax honors restaurant_tax_type. Regression cover for "Fixed"
 * tax being silently charged as a percentage.
 */
class OrderTaxTest extends TestCase
{
    private function opts(array $a): object
    {
        return (object) $a;
    }

    public function test_percentage_tax_is_a_percentage_of_subtotal(): void
    {
        $opts = $this->opts(['restaurant_taxx_charge' => 10, 'restaurant_tax_type' => 'percentage']);
        $this->assertSame(10.0, Order::computeTax(100.0, $opts));
    }

    public function test_missing_type_defaults_to_percentage(): void
    {
        $opts = $this->opts(['restaurant_taxx_charge' => 10]);
        $this->assertSame(10.0, Order::computeTax(100.0, $opts));
    }

    public function test_fixed_tax_is_a_flat_amount_not_a_percentage(): void
    {
        $opts = $this->opts(['restaurant_taxx_charge' => 5, 'restaurant_tax_type' => 'fixed']);
        // 5 flat, NOT 5% of 100 (which would also be 5) — use a subtotal that disambiguates:
        $this->assertSame(5.0, Order::computeTax(200.0, $opts));
    }

    public function test_zero_charge_or_zero_subtotal_yields_no_tax(): void
    {
        $this->assertSame(0.0, Order::computeTax(100.0, $this->opts(['restaurant_taxx_charge' => 0, 'restaurant_tax_type' => 'fixed'])));
        $this->assertSame(0.0, Order::computeTax(0.0, $this->opts(['restaurant_taxx_charge' => 5, 'restaurant_tax_type' => 'fixed'])));
    }
}
