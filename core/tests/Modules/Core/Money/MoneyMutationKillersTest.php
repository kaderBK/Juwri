<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\StoreOption;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Casts\MinorMoney;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\CurrencyTotals;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use LogicException;
use Tests\TestCase;

/**
 * P1-18 part 2: pins the Core money behaviours the mutation run (composer mutate:money) found
 * uncovered: Money::decimal edge inputs and __toString, CurrencyTotals formatting, Currencies defaults, and the MinorMoney
 * cast's non-numeric and wrong-model paths.
 */
class MoneyMutationKillersTest extends TestCase
{
    use RefreshDatabase;

    public function test_decimal_edge_inputs(): void
    {
        $big = BigDecimal::of('1.50');
        $this->assertSame('1.50', (string) Money::decimal($big), 'a BigNumber passes through at its own scale');
        $this->assertSame('0', (string) Money::decimal(''), 'empty input is zero');
        $this->assertSame('0', (string) Money::decimal('-'), 'a lone minus is zero');
        $this->assertSame('0', (string) Money::decimal('   '), 'blank input is zero');
        $this->assertSame('12.5', (string) Money::decimal(' 12.5 '), 'surrounding blanks are trimmed');
        $this->assertSame('0.1', (string) Money::decimal(0.1));
        $this->expectException(InvalidArgumentException::class);
        Money::decimal(INF);
    }

    public function test_money_to_string_and_json_shape(): void
    {
        $usd = Money::ofMinor(12345, 'USD');
        $this->assertSame('USD 123.45', (string) $usd);
        $this->assertSame(['amount' => '123.45', 'minor' => 12345, 'currency' => 'USD'], $usd->jsonSerialize());
        $this->assertSame('KWD 13.833', (string) Money::ofMinor(13833, 'KWD'));
        $this->assertSame('JPY 500', (string) Money::ofMinor(500, 'JPY'));
    }

    public function test_currency_totals_format_and_label(): void
    {
        $this->assertSame('KWD 13.833', CurrencyTotals::format(13.833, 'KWD'));
        $this->assertSame('USD 1,234.50', CurrencyTotals::format(1234.5, 'USD'));
        $this->assertSame('JPY 1,300', CurrencyTotals::format(1300, 'JPY'));
        $this->assertSame('KWD 13.833 · EUR 5.00', CurrencyTotals::label(['KWD' => 13.833, 'EUR' => 5]));
        $this->assertSame('EUR 5.00', CurrencyTotals::label(['EUR' => 5]));
        $this->assertSame('', CurrencyTotals::label([]));
    }

    public function test_currency_totals_split_keeps_primary_and_sorts_the_others(): void
    {
        [$primary, $others] = CurrencyTotals::split(['KWD' => 13833, 'EUR' => 500, 'USD' => 900, 'JPY' => 0], 'KWD');
        $this->assertSame(13.833, $primary);
        $this->assertSame(['USD' => 9.0, 'EUR' => 5.0], $others, 'zero amounts dropped, largest first');
        [$none, $empty] = CurrencyTotals::split([], 'USD');
        $this->assertSame(0.0, $none);
        $this->assertSame([], $empty);
    }

    public function test_currencies_defaults_and_sql_major_without_currency_column(): void
    {
        $this->assertSame(2, Currencies::DEFAULT_EXPONENT);
        $this->assertSame(2, Currencies::exponent(null));
        $this->assertSame(2, Currencies::exponent(''));
        $this->assertSame(2, Currencies::exponent('ZZZ'), 'an unknown code falls back to 2 decimals');
        $this->assertSame('(p.amount / 100.0)', Currencies::sqlMajor('p.amount', null));
    }

    public function test_minor_money_cast_refuses_a_model_that_is_not_a_minor_money_model(): void
    {
        $cast = new MinorMoney('price_minor');
        try {
            $cast->get(new class extends Model {}, 'price', 1, []);
            $this->fail('expected LogicException');
        } catch (LogicException $e) {
            $this->assertStringContainsString(' must implement '.MinorMoneyModel::class.' (use '.HasMinorMoney::class.')', $e->getMessage());
            $this->assertStringStartsNotWith(' ', $e->getMessage());
            $this->assertStringEndsWith(')', $e->getMessage());
        }
    }

    public function test_minor_money_cast_takes_junk_as_a_best_effort_float_and_money_of_unknown_key_is_null(): void
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'm-'.Str::lower(Str::random(6)), 'title' => 'M',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        $item = Menu::create(['store_id' => $store->id, 'name' => 'Junk', 'price' => '12abc']);
        $this->assertSame(1200, $item->price_minor, '"12abc" reads as 12, like the old decimal column');
        $this->assertNull($item->money('not_a_money_column'));
        $this->assertSame('12.00', $item->money('price')->major());
    }
}
