<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Bugs10;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Bug batch 10 · 4 — the 14 storefront themes besides glow / matcha (plus aurora) rounded their coupon / tax / tip
 * previews with CFG.currencyDecimals — the DISPLAY decimals — while the order stores amounts at the currency's exponent
 * (they differ for IQD, MGA, ISK, UYI, CLF, UYW); aurora also rounded a percentage / fixed coupon at 2 decimals and
 * feast never rounded a percentage tip. Each theme now gets CFG.moneyDecimals (money_decimals()) and uses it for that
 * arithmetic (and the tip it posts); display formatting keeps currencyDecimals.
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class ThemeMoneyDecimalsTest extends TestCase
{
    /** The themes that preview coupon / tax / tip themselves (a word list, not a copy of the kept-template registry). */
    private const THEMES = 'aurora crave crumb dough ember feast lime orbit picnic plate scoop soiree velora velvet vivid';

    public function test_every_theme_hands_its_bundle_the_stored_precision_and_uses_it(): void
    {
        foreach (explode(' ', self::THEMES) as $t) {
            $blade = file_get_contents(resource_path("views/store_templates/{$t}/index.blade.php"));
            $js = file_get_contents(base_path("../assets/store_templates/{$t}/js/script.js"));
            $this->assertMatchesRegularExpression('/moneyDecimals: @json\(money_decimals\(/', $blade, $t); // before: absent
            $this->assertStringContainsString('moneyDecimals', $js, $t);                                   // before: absent
        }
    }

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

    private function grab(string $src, string $pattern, string $what): string
    {
        $this->assertSame(1, preg_match($pattern, $src, $m), "{$what} not found");

        return $m[1];
    }

    /** crave totals() for a subtotal with a 10 % coupon, 10 % tax, 10 % tip. */
    private function craveTotals(float $sub, ?int $moneyDecimals, int $displayDecimals): array
    {
        $src = file_get_contents(base_path('../assets/store_templates/crave/js/script.js'));
        $mb = file_get_contents(base_path('../assets/store_templates/_shared/js/mb-money.js'));
        $cfg = json_encode(['moneyDecimals' => $moneyDecimals, 'currencyDecimals' => $displayDecimals, 'taxCharge' => 10, 'taxType' => 'percentage', 'deliveryCharge' => 0]);
        $js = "var window = {};\n".$mb."\nvar mbMoney = window.mbMoney;\nvar CFG = {$cfg};\nvar cart = [1];\n"
            ."var S = {coupon: {coupon_type: 'percentage', percentage: 10}, tipPct: 10, tipCustom: null, orderType: 'takeaway'};\n"
            ."function subtotal() { return {$sub}; }\nfunction comboCount() { return 0; }\nfunction bxgyDiscount() { return 0; }\n"
            .$this->grab($src, '/\n(  var BASE_DP = [^\n]*)\n/', 'BASE_DP')."\n"
            .(str_contains($src, 'var MONEY_DP') ? $this->grab($src, '/\n(  var MONEY_DP = [^\n]*)\n/', 'MONEY_DP')."\n" : '')
            .$this->grab($src, '/\n(  function pctAmount\([^\n]*)\n/', 'pctAmount')."\n"
            .$this->grab($src, '/\n(  function totals\(\) \{.*?\n  \})\n/s', 'totals')."\nconsole.log(JSON.stringify(totals()));\n";
        $file = tempnam(sys_get_temp_dir(), 'b10js').'.js';
        file_put_contents($file, $js);
        $p = new Process([$this->node(), $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode(trim($p->getOutput()), true);
    }

    public function test_crave_previews_at_the_stored_precision(): void
    {
        $iqd = $this->craveTotals(12.345, 3, 2); // IQD: stored 3, shown 2
        $this->assertSame(1.235, $iqd['disc']); // before: 1.23
        $this->assertSame(1.235, $iqd['tax']);  // before: 1.23
        $this->assertSame(1.235, $iqd['tip']);  // before: 1.23

        $usd = $this->craveTotals(12.34, 2, 2);
        $this->assertSame(1.23, $usd['disc']);  // unchanged
        $this->assertSame(1.23, $usd['tax']);

        $legacy = $this->craveTotals(12.345, null, 3); // an old cached page without moneyDecimals: display decimals as before
        $this->assertSame(1.235, $legacy['tax']);
    }

    public function test_aurora_coupon_preview_is_no_longer_two_decimals(): void
    {
        $src = file_get_contents(base_path('../assets/store_templates/aurora/js/script.js'));
        $this->assertStringNotContainsString('Math.round(sub * appliedCoupon.percentage) / 100', $src); // before: present (KWD 10 % of 12.345 → 1.23)
        $this->assertStringContainsString('roundMoney(sub * (Number(appliedCoupon.percentage) || 0) / 100)', $src);
    }
}
