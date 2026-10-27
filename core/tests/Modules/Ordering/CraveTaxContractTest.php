<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * WI-H9: crave must quote the tax the server actually charges — on the
 * PRE-discount subtotal, and honouring the store's `restaurant_tax_type` (fixed
 * vs percentage). crave was the sole outlier of the 17 kept templates: it taxed
 * (sub - disc) and had no `taxType` concept. These tests pin the fix against the
 * shipped asset files (an established idiom — see
 * ReportedRetainedTemplateRegressionTest) plus a server-authority pin.
 */
class CraveTaxContractTest extends TestCase
{
    /** The 17 kept templates — the tax contract must hold across all of them. */
    private const KEPT = KeptTemplates::MODERN;

    private function opts(array $a): object
    {
        return (object) $a;
    }

    public function test_crave_taxes_the_pre_discount_subtotal(): void
    {
        $js = file_get_contents(public_path('assets/store_templates/crave/js/script.js'));
        $this->assertStringNotContainsString('sub - disc, CFG.taxCharge', $js);
        $this->assertStringContainsString('pctAmount(sub, CFG.taxCharge)', $js);
    }

    public function test_crave_honours_the_fixed_tax_type(): void
    {
        $js = file_get_contents(public_path('assets/store_templates/crave/js/script.js'));
        $this->assertStringContainsString('CFG.taxType === "fixed"', $js);
        $this->assertStringContainsString('CFG.taxCharge > 0 && sub > 0', $js);
    }

    public function test_crave_blade_exposes_tax_type_to_the_runtime(): void
    {
        $view = file_get_contents(resource_path('views/store_templates/crave/index.blade.php'));
        $this->assertStringContainsString('taxType:', $view);
        $this->assertStringContainsString('restaurant_tax_type', $view);
    }

    public function test_every_kept_theme_agrees_on_the_tax_contract(): void
    {
        foreach (self::KEPT as $t) {
            $js = file_get_contents(public_path("assets/store_templates/{$t}/js/script.js"));
            $this->assertStringContainsString('taxType', $js, "{$t} must reference taxType");
            $this->assertStringNotContainsString('sub - disc, CFG.taxCharge', $js, "{$t} must not tax the post-discount subtotal");
        }
    }

    public function test_server_authority_is_unchanged(): void
    {
        $this->assertSame(10.0, Order::computeTax(100.0, $this->opts(['restaurant_taxx_charge' => 10, 'restaurant_tax_type' => 'percentage'])));
        $this->assertSame(5.0, Order::computeTax(200.0, $this->opts(['restaurant_taxx_charge' => 5, 'restaurant_tax_type' => 'fixed'])));
        $this->assertSame(0.0, Order::computeTax(0.0, $this->opts(['restaurant_taxx_charge' => 5, 'restaurant_tax_type' => 'fixed'])));
    }
}
