<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\P6105;

use App\Models\Order;
use App\Models\StoreOption;
use App\Services\MultiVendor\CrossCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\ExchangeRate;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\ExchangeRateService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Storefront\Storefront\DisplayRates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-105: display FX never touches charged amounts. A shopper browsing in a display currency is charged, taxed,
 * stored and sent to the gateway in the STORE currency; a moved/stale FX rate between cart and pay changes nothing;
 * a bag that mixes store currencies is refused, never converted.
 */
class MultiCurrencyChargeTest extends TestCase
{
    use RefreshDatabase;

    private CrossCartService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_multivendor_cart' => true, 'settings.currency_code' => 'USD']);
        $this->svc = new CrossCartService;
        ExchangeRateService::flush();
    }

    private function seedRates(array $rates): void
    {
        foreach ($rates as $quote => $rate) {
            ExchangeRate::updateOrCreate(
                ['base' => 'USD', 'quote' => $quote, 'provider' => ExchangeRateService::PROVIDER],
                ['rate' => $rate, 'fetched_at' => now()]
            );
        }
        ExchangeRateService::flush();
    }

    /** @return array{0: Store, 1: Menu} */
    private function store(string $currency, string $price, string $display): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($r->id, 'enable_multi_currency', '1');
        StoreOption::updateStoreOption($r->id, 'currency_rates_mode', 'auto');
        StoreOption::updateStoreOption($r->id, 'display_currencies', $display);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);

        return [$r, $menu];
    }

    /** @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed> */
    private static function row(array $results, int $storeId): array
    {
        return (array) collect($results)->firstWhere('store_id', $storeId);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}> */
    public static function goldens(): array
    {
        // store currency, unit price, display currency, expected total (×3), expected ISO minor units
        return [
            'KWD 3dp' => ['KWD', '1.125', 'USD', '3.375', 3375],
            'JPY 0dp' => ['JPY', '1500', 'USD', '4500', 4500],
            'SAR' => ['SAR', '12.50', 'KWD', '37.5', 3750],
            'USD' => ['USD', '9.99', 'JPY', '29.97', 2997],
        ];
    }

    #[DataProvider('goldens')]
    public function test_golden_order_charges_the_store_currency_total(string $cur, string $price, string $display, string $total, int $minor): void
    {
        $this->seedRates(['KWD' => 0.3071, 'JPY' => 149.2, 'SAR' => 3.75]);
        [$r, $menu] = $this->store($cur, $price, $display);

        // The shopper sees a converted display price…
        $rates = DisplayRates::forStore($r->id)->rates;
        $this->assertArrayHasKey($display, $rates);
        $this->assertNotEquals(1.0, $rates[$display]);

        $this->svc->addItem($r->id, $menu->id, 3);
        $results = $this->svc->checkout(['name' => 'Sam', 'phone' => '123']);
        $this->assertTrue(self::row($results, $r->id)['ok'], (string) self::row($results, $r->id)['error']);

        // …but is charged, stored and sent to the gateway in the store currency.
        $order = Order::findOrFail(self::row($results, $r->id)['order_id']);
        $this->assertSame($cur, $order->currency_code);
        $this->assertEqualsWithDelta((float) $total, (float) $order->grand_total, 0.0000001);
        $this->assertEqualsWithDelta((float) $total, (float) $order->amount_due, 0.0000001);

        $tx = app(HostedOrderPayments::class)->open($r, $order, null, null);
        $this->assertSame($cur, strtoupper((string) $tx->currency_code));
        $this->assertEqualsWithDelta((float) $total, (float) $tx->amount, 0.0000001);
        $this->assertSame($minor, Money::minorFromMajor((float) $tx->amount, (string) $tx->currency_code));
    }

    public function test_fx_rate_moving_between_cart_and_pay_does_not_change_the_charge(): void
    {
        $this->seedRates(['KWD' => 0.3071, 'SAR' => 3.75]);
        [$r, $menu] = $this->store('KWD', '1.125', 'SAR');
        $this->svc->addItem($r->id, $menu->id, 2);

        // Feed moves (and the 1h memo would otherwise hold the old rate): the display price changes…
        $before = DisplayRates::forStore($r->id)->rates['SAR'];
        $this->seedRates(['KWD' => 0.20, 'SAR' => 9.0]);
        $this->assertNotEquals($before, DisplayRates::forStore($r->id)->rates['SAR']);

        $results = $this->svc->checkout(['name' => 'Sam', 'phone' => '123']);
        $order = Order::findOrFail(self::row($results, $r->id)['order_id']);
        $this->assertSame('KWD', $order->currency_code);
        $this->assertEqualsWithDelta(2.25, (float) $order->grand_total, 0.0000001);

        // A stale feed (hides auto display codes) changes nothing either.
        ExchangeRate::query()->update(['fetched_at' => now()->subDays(10)]);
        ExchangeRateService::flush();
        $tx = app(HostedOrderPayments::class)->open($r, $order, null, null);
        $this->assertEqualsWithDelta(2.25, (float) $tx->amount, 0.0000001);
        $this->assertSame(2250, Money::minorFromMajor((float) $tx->amount, 'KWD'));
    }

    public function test_mixed_currency_bag_is_refused_at_add(): void
    {
        [$a, $ma] = $this->store('KWD', '1.125', 'USD');
        [$b, $mb] = $this->store('SAR', '12.50', 'USD');
        $this->svc->addItem($a->id, $ma->id, 1);

        $this->expectException(ValidationException::class);
        $this->svc->addItem($b->id, $mb->id, 1);
    }

    public function test_mixed_currency_bag_built_before_the_gate_is_refused_whole_at_checkout(): void
    {
        [$a, $ma] = $this->store('KWD', '1.125', 'USD');
        [$b, $mb] = $this->store('KWD', '2.000', 'USD');
        $this->svc->addItem($a->id, $ma->id, 1);
        $this->svc->addItem($b->id, $mb->id, 1);
        StoreOption::updateStoreOption($b->id, 'currency_code', 'SAR'); // store B switches currency after the add

        try {
            $this->svc->checkout(['name' => 'Sam', 'phone' => '123']);
            $this->fail('a mixed-currency bag must be refused');
        } catch (ValidationException) {
        }
        $this->assertSame(0, Order::query()->whereIn('store_id', [$a->id, $b->id])->count());

        // One currency at a time still checks out.
        $results = $this->svc->checkout(['name' => 'Sam', 'phone' => '123'], [], [$a->id]);
        $this->assertTrue(self::row($results, $a->id)['ok']);
        $this->assertSame('KWD', Order::findOrFail(self::row($results, $a->id)['order_id'])->currency_code);
    }

    public function test_same_currency_multi_store_bag_splits_per_store_in_that_currency(): void
    {
        [$a, $ma] = $this->store('SAR', '12.50', 'USD');
        [$b, $mb] = $this->store('SAR', '3.25', 'KWD');
        $this->svc->addItem($a->id, $ma->id, 1);
        $this->svc->addItem($b->id, $mb->id, 2);

        $results = $this->svc->checkout(['name' => 'Sam', 'phone' => '123']);
        $this->assertEqualsWithDelta(12.5, (float) Order::findOrFail(self::row($results, $a->id)['order_id'])->grand_total, 0.0000001);
        $this->assertEqualsWithDelta(6.5, (float) Order::findOrFail(self::row($results, $b->id)['order_id'])->grand_total, 0.0000001);
    }
}
