<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\StoreOption;
use Brick\Math\Exception\NumberFormatException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Casts\MinorMoney;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\CurrencyTotals;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * P6-128: the mutants `composer mutate:money` reported UNCOVERED on 2026-10-13 — CurrencyTotals::minorByCurrency,
 * the Currencies cache hit, the MinorMoney raw-major read and attributesToArray() past a hidden money key. The
 * MAX_EXPONENT / DEFAULT_EXPONENT constants are pinned here too (pcov never marks a constant line covered, so the run
 * keeps listing those four; see the mutation report of 2026-10-13).
 */
class MoneyUncoveredMutantsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Currencies::flush();
        parent::tearDown();
    }

    public function test_minor_by_currency_sums_exact_integers_per_normalised_code_with_fallback(): void
    {
        $out = CurrencyTotals::minorByCurrency([
            ['kwd', '100'], [null, 5], [' KWD ', 23], ['', '7.9'], ['EUR', 4],
        ], 'usd');

        $this->assertSame(['KWD' => 123, 'USD' => 12, 'EUR' => 4], $out);
        $this->assertSame([], CurrencyTotals::minorByCurrency([], 'USD'));
    }

    public function test_currencies_use_the_cached_table_without_reading_the_database(): void
    {
        Currencies::flush();
        Cache::put(Currencies::CACHE_KEY, ['ZZQ' => 4], 3600);

        $this->assertSame(4, Currencies::exponent('zzq'), 'a cached table is used as-is');
    }

    public function test_exponent_limit_and_default_exponent(): void
    {
        $this->assertSame(2, Currencies::DEFAULT_EXPONENT);
        $this->assertSame('1'.str_repeat('0', 50), (string) Money::decimal('1e50'), 'exponent 50 is still accepted');
        $this->assertSame('0.'.str_repeat('0', 49).'1', (string) Money::decimal('1e-50'));
        $this->expectException(NumberFormatException::class);
        Money::decimal('1e51');
    }

    public function test_exponent_51_negative_is_refused_too(): void
    {
        $this->expectException(NumberFormatException::class);
        Money::decimal('1e-51');
    }

    private function menu(): Menu
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'm-'.Str::lower(Str::random(6)), 'title' => 'M',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        return Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => '3.25', 'cost' => '1.10']);
    }

    public function test_cast_reads_a_raw_major_value_not_yet_settled(): void
    {
        $item = $this->menu()->fresh();
        $cast = new MinorMoney('price_minor');

        $this->assertSame(12.345, $cast->get($item, 'price', '12.345', ['price_minor' => 325]), 'a raw major key wins over the minor column');
        $this->assertSame(3.25, $cast->get($item, 'price', null, ['price_minor' => 325]));
        // A float raw value is read through its string form (PHP precision 14), not the full float expansion.
        $this->assertSame((float) (string) 0.123456789012345678, $cast->get($item, 'price', 0.123456789012345678, []));
    }

    public function test_array_output_keeps_later_money_keys_after_a_hidden_one(): void
    {
        $item = $this->menu()->fresh();
        $item->makeHidden('price');

        $array = $item->toArray();
        $this->assertArrayNotHasKey('price', $array);
        $this->assertArrayHasKey('cost', $array, 'a hidden money key skips only itself');
        $this->assertEquals(1.1, $array['cost']);
    }
}
