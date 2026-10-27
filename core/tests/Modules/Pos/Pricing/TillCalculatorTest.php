<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos\Pricing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Pricing\TillCalculator;
use Symfony\Component\Process\Process;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Offline POS OP-2 (OFFLINE-POS-SPEC §4.1): the till calculator. The golden vectors (tests/Fixtures/till-calculator)
 * are reproduced byte for byte here and by assets/pos/till-calculator.js (tests/js/pos, run in CI); the edge cases
 * are also checked by hand; and the calculator agrees with the server's own order money (Order::computeMoney), so an
 * offline sale replays to the same total as an online one.
 */
class TillCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../../../Fixtures/till-calculator';

    /** @return list<array{name: string, input: array<string, mixed>, expected: array<string, mixed>}> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES.'/vectors.json'), true, 512, JSON_THROW_ON_ERROR)['vectors'];
    }

    public function test_every_golden_vector_is_reproduced_byte_for_byte(): void
    {
        $vectors = self::vectors();
        $this->assertGreaterThanOrEqual(300, count($vectors));
        foreach ($vectors as $v) {
            try {
                $got = TillCalculator::price($v['input']);
            } catch (InvalidArgumentException) {
                $got = ['error' => true];
            }
            $this->assertSame(json_encode($v['expected']), json_encode($got), $v['name']);
        }
    }

    public function test_the_committed_vectors_are_what_the_generator_writes(): void
    {
        $p = new Process([PHP_BINARY, self::FIXTURES.'/generate.php', '--check']);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
    }

    public function test_edge_cases_by_hand(): void
    {
        $by = array_column(self::vectors(), 'expected', 'name');
        $this->assertSame(563, $by['tax rounds half up at .5']['tax_minor']);            // 3750 × 15 % = 562.5
        $this->assertSame(0, $by['tax rounds down below .5']['tax_minor']);              // 3 × 15 % = 0.45
        // P6-271: (2000 − 500) × 15 % = 225; the legacy gross base keeps 2000 × 15 % = 300
        $this->assertSame([225, 500, 1725], [$by['tax on the subtotal after discount']['tax_minor'], $by['tax on the subtotal after discount']['discount_minor'], $by['tax on the subtotal after discount']['total_minor']]);
        $this->assertSame([300, 500, 1800], [$by['legacy gross tax base']['tax_minor'], $by['legacy gross tax base']['discount_minor'], $by['legacy gross tax base']['total_minor']]);
        $this->assertSame([2997, 0, 0], [$by['100 percent discount']['discount_minor'], $by['100 percent discount']['tax_minor'], $by['100 percent discount']['total_minor']]);
        $this->assertSame([500, 100], [$by['discount above the subtotal is capped']['discount_minor'], $by['discount above the subtotal is capped']['total_minor']]);
        $this->assertSame(2800, $by['fixed tax']['total_minor']);
        $this->assertSame(0, $by['fixed tax not on an empty sale']['total_minor']);
        $this->assertSame(76698, $by['four-decimal tax rate']['tax_minor']);             // 864199 × 8.875 % = 76697.66 → 76698
        $this->assertSame(7700, $by['extras and options times quantity']['subtotal_minor']); // (1500+250+75+100) × 4
        $this->assertSame(385, $by['extras and options times quantity']['tax_minor']);
        $this->assertSame(700, $by['zero-price item with priced extras']['total_minor']);
        $this->assertSame([9999, 1500, 11499], [$by['quantity 9999']['subtotal_minor'], $by['quantity 9999']['tax_minor'], $by['quantity 9999']['total_minor']]);
        // (4375 − 547) × 5 % = 191.4
        $this->assertSame([4375, 191, 547, 4019], [$by['three-decimal currency (KWD) amounts']['subtotal_minor'], $by['three-decimal currency (KWD) amounts']['tax_minor'],
            $by['three-decimal currency (KWD) amounts']['discount_minor'], $by['three-decimal currency (KWD) amounts']['total_minor']]);
        // (2940 − 98) × 10 % = 284.2
        $this->assertSame([284, 98, 3126], [$by['zero-decimal currency (JPY) amounts']['tax_minor'], $by['zero-decimal currency (JPY) amounts']['discount_minor'], $by['zero-decimal currency (JPY) amounts']['total_minor']]);
        $this->assertSame(6625, $by['service charge and tip']['total_minor']);          // 4500 + 675 + 450 + 1000
        $this->assertSame(['error' => true], $by['refuses a negative price']);
        $this->assertSame(['error' => true], $by['refuses five tax decimals']);
        $this->assertSame(['error' => true], $by['refuses a line above the maximum']);
    }

    public function test_the_calculator_agrees_with_the_servers_order_money(): void
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Till', 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Item', 'price' => 1, 'active' => '1']);

        $checked = 0;
        foreach (self::vectors() as $v) {
            $in = $v['input'];
            // the server's tax is a percentage or fixed store setting; amounts here are SAR (2 decimals)
            if (isset($v['expected']['error']) || ($in['tax']['mode'] ?? 'none') === 'fixed' || $checked >= 80) {
                continue;
            }
            $tax = $in['tax'] ?? ['mode' => 'none'];
            StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', $tax['mode'] === 'percentage' ? $tax['rate'] : '0');
            StoreOption::updateStoreOption($store->id, 'restaurant_tax_type', 'percentage');
            $want = $v['expected'];
            $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'currency_code' => 'SAR',
                'discount_amount' => Money::majorFromMinor($want['discount_minor'], 'SAR'),
                'service_charge_amount' => Money::majorFromMinor($want['service_minor'], 'SAR'),
                'tip_amount' => Money::majorFromMinor($want['tip_minor'], 'SAR'),
                'tax_pre_discount' => ($in['tax_base'] ?? 'net') === 'gross' ? true : null]); // P6-271: the old rule's orders
            foreach ($in['lines'] as $line) {
                $adds = array_merge($line['extras_minor'] ?? [], $line['options_minor'] ?? []);
                OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $line['qty'],
                    'unit_price' => Money::majorFromMinor($line['unit_minor'], 'SAR'),
                    'options' => [['group' => 'g', 'selected' => array_map(fn ($a) => ['label' => 'x', 'price' => Money::majorFromMinor($a, 'SAR')], $adds)]]]);
            }
            $money = $order->fresh()->computeMoney();
            $this->assertSame([$want['subtotal_minor'], $want['tax_minor'], $want['total_minor']],
                [Money::minorFromMajor((string) $money['subtotal'], 'SAR'), Money::minorFromMajor((string) $money['tax'], 'SAR'), Money::minorFromMajor((string) $money['grand'], 'SAR')],
                "{$v['name']}: the server's order money differs from the till calculator");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(60, $checked);
    }
}
