<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\GlobalMenuExtra;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\TestCase;

/**
 * P1-05 slice 2: menu money is stored as `*_minor` integers in the row's currency; the legacy major attributes
 * (`price`, `cost`, `upcharge`, pivot `price_override`) keep their old read/write/JSON shape.
 */
class MinorMoneyModelTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency): Store
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    public function test_price_is_stored_in_minor_units_of_the_store_currency_whatever_the_fill_order(): void
    {
        $jod = $this->store('JOD');
        // price before store_id: the currency is only known on save.
        $item = Menu::create(['price' => '12.345', 'cost' => 4.5, 'name' => 'Mansaf', 'store_id' => $jod->id]);
        $row = DB::table('items')->where('id', $item->id)->first();
        $this->assertSame(12345, (int) $row->price_minor);
        $this->assertSame(4500, (int) $row->cost_minor);
        $this->assertSame('JOD', $row->currency_code);

        $fresh = Menu::find($item->id);
        $this->assertSame(12.345, $fresh->price, 'price stays a number (old float cast)');
        $this->assertSame('4.500', $fresh->cost, 'cost stays a store-precision string (old StoreMoney cast)');
        $this->assertSame(12345, $fresh->price_minor);
        $this->assertSame('12.345', $fresh->money('price')->major());

        $json = $fresh->toArray();
        $this->assertSame(12.345, $json['price']);
        $this->assertSame(12345, $json['price_minor']);
        $this->assertSame('4.500', $json['cost']);

        $usd = $this->store('USD');
        $this->assertSame(1235, Menu::create(['store_id' => $usd->id, 'name' => 'X', 'price' => 12.345])->price_minor, 'half up to cents');
        $this->assertSame(500, Menu::create(['store_id' => $this->store('JPY')->id, 'name' => 'Y', 'price' => 499.5])->price_minor);
    }

    public function test_a_later_store_currency_change_does_not_reinterpret_stored_amounts(): void
    {
        $store = $this->store('USD');
        $item = Menu::create(['store_id' => $store->id, 'name' => 'A', 'price' => 10.5]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'KWD');

        $this->assertSame(10.5, Menu::find($item->id)->price, 'same number as the old decimal column showed');
        $item = Menu::find($item->id);
        $item->update(['name' => 'B']);
        $this->assertSame('USD', DB::table('items')->where('id', $item->id)->value('currency_code'), 'no amount edited: currency kept');
        $item = Menu::create(['store_id' => $store->id, 'name' => 'C', 'price' => 1, 'cost' => 0.5]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $item = Menu::find($item->id);
        $item->update(['price' => 2.125]);
        $row = DB::table('items')->where('id', $item->id)->first();
        $this->assertSame('USD', $row->currency_code, 'an edited row moves to the store currency');
        $this->assertSame(213, (int) $row->price_minor);
        $this->assertSame(50, (int) $row->cost_minor, 'the untouched amount keeps its number in the new currency');
    }

    public function test_other_menu_money_models_and_pivot_override(): void
    {
        $store = $this->store('OMR');
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Item', 'price' => 2]);
        $extra = MenuExtra::create(['store_id' => $store->id, 'title' => 'Cheese', 'price' => '0.275']);
        $this->assertSame('0.275', MenuExtra::find($extra->id)->price);
        $this->assertSame(275, (int) DB::table('modifiers')->where('id', $extra->id)->value('price_minor'));

        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 3.25]);
        $this->assertSame(3250, (int) DB::table('menu_variants')->where('id', $variant->id)->value('price_minor'));
        $this->assertSame('OMR', DB::table('menu_variants')->where('id', $variant->id)->value('currency_code'), 'child row takes its store currency');

        $combo = Combo::create(['store_id' => $store->id, 'name' => 'Meal', 'price' => 5.5]);
        $this->assertSame('5.500', Combo::find($combo->id)->price);

        $item->attachedExtras()->attach($extra->id, ['price_override' => '0.125', 'active' => 1, 'position' => 1]);
        $pivot = DB::table('item_modifier_group')->where('menu_id', $item->id)->first();
        $this->assertSame('OMR', $pivot->currency_code);
        $this->assertSame(125, (int) $pivot->price_override_minor);
        $this->assertSame(0.125, Menu::find($item->id)->attachedExtras()->first()->pivot->price_override);
        $item->attachedExtras()->updateExistingPivot($extra->id, ['price_override' => null]);
        $this->assertNull(DB::table('item_modifier_group')->where('menu_id', $item->id)->value('price_override_minor'));

        $global = GlobalMenuExtra::create(['title' => 'Napkin', 'price' => 1.5]);
        $this->assertSame('1.500', GlobalMenuExtra::find($global->id)->price, 'old decimal:3 string');
    }

    public function test_legacy_rows_with_decimal_keys_are_upgraded(): void
    {
        $row = LegacyMoneyColumns::upgradeRow('menu', ['name' => 'Old', 'price' => '12.345', 'cost' => null], 'KWD');
        $this->assertSame(['name' => 'Old', 'price_minor' => 12345, 'cost_minor' => null, 'currency_code' => 'KWD'], $row);
        $this->assertSame(['id' => 1], LegacyMoneyColumns::upgradeRow('orders', ['id' => 1], 'USD'));
        $this->assertSame(1234, Money::minorFromMajor('12.34', 'USD'));
    }
}
