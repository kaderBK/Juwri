<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Bugs9;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Bug batch 9 · 6 — the glow and matcha themes previewed a percentage tip at 2 decimals, and the commerce runtime
 * (v1 / v2) previewed tax and tip at 2 decimals (tax always as a percentage, ignoring `restaurant_tax_type`) and showed
 * every amount with 2 decimals. Previews now round like the order stores them — the store currency's minor unit
 * (money_decimals(): KWD fils, JPY yen) — and the runtime shows the base currency at its display decimals.
 *
 * The JS totals() functions are run in node (skipped when node is missing). `before:` comments record what the same
 * assertion returned before the fix.
 */
class TipTaxPreviewDecimalsTest extends TestCase
{
    use RefreshDatabase;

    private function node(): string
    {
        foreach (['node', '/usr/bin/node', '/usr/local/bin/node'] as $candidate) {
            $p = new Process([$candidate, '--version']);
            $p->run();
            if ($p->isSuccessful()) {
                return $candidate;
            }
        }
        $this->markTestSkipped('node not available');
    }

    /** Pull `function name(...) { … }` (two-space indented, closed by "\n  }") out of a bundle. */
    private function fn(string $src, string $name): string
    {
        $this->assertSame(1, preg_match('/\n(  function '.preg_quote($name, '/').'\(.*?\n  \}\n)/s', $src, $m), "function {$name} not found");

        return $m[1];
    }

    private function oneLine(string $src, string $name): string
    {
        $this->assertSame(1, preg_match('/\n(  function '.preg_quote($name, '/').'\([^\n]*\})\n/', $src, $m), "function {$name} not found");

        return $m[1];
    }

    /** @return array<string, float> */
    private function runJs(string $js): array
    {
        $file = tempnam(sys_get_temp_dir(), 'b9js').'.js';
        file_put_contents($file, $js);
        $p = new Process([$this->node(), $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode(trim($p->getOutput()), true);
    }

    /** glow / matcha totals() for a cart subtotal, tip %, tax %, at the store's money decimals. */
    private function themeTotals(string $theme, float $sub, int $tipPct, float $tax, int $moneyDecimals, int $displayDecimals): array
    {
        $src = file_get_contents(base_path('../assets/store_templates/'.$theme.'/js/script.js'));
        $mb = file_get_contents(base_path('../assets/store_templates/_shared/js/mb-money.js'));
        $cfg = json_encode(['moneyDecimals' => $moneyDecimals, 'currencyDecimals' => $displayDecimals, 'taxCharge' => $tax, 'taxType' => 'percentage', 'deliveryCharge' => 0]);
        $js = "var window = {}; var w = window;\n".$mb."\nvar mbMoney = window.mbMoney;\nvar CFG = {$cfg};\nvar cart = [1]; var S = {coupon: null, tipPct: {$tipPct}, tipCustom: null, orderType: 'takeaway'};\n"
            ."function subtotal() { return {$sub}; }\nfunction comboCount() { return 0; }\n"
            .$this->oneLine($src, 'roundTo')."\n"
            .(str_contains($src, 'function moneyDp') ? $this->oneLine($src, 'moneyDp')."\n" : '')
            .$this->fn($src, 'totals')."\nconsole.log(JSON.stringify(totals()));\n";

        return $this->runJs($js);
    }

    public function test_glow_and_matcha_tip_rounds_to_the_store_minor_unit(): void
    {
        foreach (['glow', 'matcha'] as $theme) {
            $kwd = $this->themeTotals($theme, 12.345, 10, 10, 3, 3);
            $this->assertSame(1.235, $kwd['tip'], $theme); // before: 1.23
            $this->assertSame(1.235, $kwd['tax'], $theme); // before: 1.235 (tax already used the store decimals)

            $usd = $this->themeTotals($theme, 12.34, 15, 10, 2, 2);
            $this->assertSame(1.85, $usd['tip'], $theme);  // unchanged
            $this->assertSame(1.23, $usd['tax'], $theme);  // unchanged

            $jpy = $this->themeTotals($theme, 1234, 15, 10, 0, 0);
            $this->assertEquals(185, $jpy['tip'], $theme); // before: 185.1

            $iqd = $this->themeTotals($theme, 12.345, 10, 10, 3, 2);
            $this->assertSame(1.235, $iqd['tax'], $theme); // before: 1.23 (display decimals)
        }
    }

    /** runtime totals() for a subtotal, tip %, tax charge / type. */
    private function runtimeTotals(string $v, float $sub, int $tipPct, float $tax, string $taxType, ?int $moneyDecimals): array
    {
        $src = file_get_contents(base_path('../assets/commerce-runtime/'.$v.'/commerce.js'));
        $cfg = json_encode(['currency' => ['code' => 'KWD', 'moneyDecimals' => $moneyDecimals, 'decimals' => $moneyDecimals], 'charges' => ['tax' => $tax, 'taxType' => $taxType, 'delivery' => 0], 'flags' => ['allowTips' => true]]);
        $js = "var CFG = {$cfg}; var coupon = null; var orderType = 'takeaway'; var cart = [1]; var tipPct = {$tipPct}; var tipCustom = null;\n"
            ."var curCode = 'KWD', curRate = 1;\nfunction subtotal() { return {$sub}; }\nfunction couponDiscount() { return 0; }\n"
            .(str_contains($src, 'function roundTo') ? $this->fn($src, 'roundTo')."\n".$this->oneLine($src, 'moneyDp')."\n" : '')
            .$this->fn($src, 'totals')."\nconsole.log(JSON.stringify(totals()));\n";

        return $this->runJs($js);
    }

    public function test_runtime_previews_tax_and_tip_like_the_order(): void
    {
        foreach (['v1', 'v2'] as $v) {
            $kwd = $this->runtimeTotals($v, 12.345, 10, 10, 'percentage', 3);
            $this->assertSame(1.235, $kwd['tax'], $v); // before: 1.23
            $this->assertSame(1.235, $kwd['tip'], $v); // before: 1.23

            $fixed = $this->runtimeTotals($v, 20, 0, 1.5, 'fixed', 3);
            $this->assertSame(1.5, $fixed['tax'], $v); // before: 0.3 (a fixed 1.500 tax read as 1.5 %)

            $usd = $this->runtimeTotals($v, 12.34, 15, 10, 'percentage', 2);
            $this->assertSame(1.23, $usd['tax'], $v);  // unchanged
            $this->assertSame(1.85, $usd['tip'], $v);  // unchanged
        }
    }

    public function test_runtime_config_carries_the_decimals_and_tax_type(): void
    {
        $store = new Store(['slug' => 'kw']);
        $store->id = 7;
        $item = new Menu(['price' => 1.25]);
        $item->id = 1;
        $item->name = 'Tea';
        $item->setRelation('variantOptions', collect());
        $item->setRelation('variants', collect());
        $item->setRelation('extras', collect());
        $cfg = (new CommerceRuntime)->config([
            'store' => $store, 'total_menus' => [1 => $item], 'customer' => null, 'table' => null,
            'postOptions' => (object) ['currency_code' => 'IQD', 'currency_sign' => 'IQD', 'restaurant_taxx_charge' => 2, 'restaurant_tax_type' => 'fixed'],
            'flags' => ['allow_order' => true],
        ]);

        $this->assertSame(2, $cfg['currency']['decimals']);      // display (IQD shows 2)
        $this->assertSame(3, $cfg['currency']['moneyDecimals']); // stored (IQD exponent 3)
        $this->assertSame('fixed', $cfg['charges']['taxType']);  // before: absent
    }

    public function test_glow_page_hands_the_theme_both_precisions(): void
    {
        $blade = file_get_contents(resource_path('views/store_templates/glow/index.blade.php'));
        $this->assertStringContainsString('moneyDecimals: @json(money_decimals())', $blade);
        $this->assertStringContainsString('moneyDecimals: @json(money_decimals())', file_get_contents(resource_path('views/store_templates/matcha/index.blade.php')));
    }
}
