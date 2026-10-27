<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;
use Tests\TestCase;

/**
 * P1-06 menu remodel: option groups (was menu_variant_options) and add-ons (was menu_extras) are stored as
 * modifier_groups + modifiers (price_minor), library attachments as item_modifier_group rows. The legacy models
 * (MenuVariantOption, MenuExtra, the two pivots) keep their read/write shape.
 */
class ModifierRemodelTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency = 'USD'): Store
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    private function item(Store $store): Menu
    {
        return Menu::create(['store_id' => $store->id, 'name' => 'Pizza', 'price' => 10, 'active' => 1]);
    }

    public function test_a_variant_group_stores_one_modifier_per_option_in_minor_units(): void
    {
        $store = $this->store('OMR');
        $item = $this->item($store);

        $group = MenuVariantOption::create([
            'menu_id' => $item->id, 'title' => 'Size',
            'options' => ['Small', 'Large'], 'option_prices' => [0, 0.275], 'option_images' => [null, 'l.png'],
        ]);

        $row = DB::table('modifier_groups')->where('id', $group->id)->first();
        $this->assertSame('variant', $row->kind);
        $this->assertSame($store->id, (int) $row->store_id);
        $this->assertNotNull($row->account_id);
        $this->assertNotNull($row->public_id);

        $rows = DB::table('modifiers')->where('modifier_group_id', $group->id)->orderBy('position')->get();
        $this->assertSame(['Small', 'Large'], $rows->pluck('title')->all());
        $this->assertSame([0, 275], $rows->pluck('price_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['OMR', 'OMR'], $rows->pluck('currency_code')->all());
        $this->assertSame([null, 'l.png'], $rows->pluck('image')->all());
        $this->assertSame(['variant', 'variant'], $rows->pluck('kind')->all());
        $this->assertSame([$item->id, $item->id], $rows->pluck('menu_id')->map(fn ($v) => (int) $v)->all());

        $fresh = MenuVariantOption::find($group->id);
        $this->assertSame(['Small', 'Large'], $fresh->options);
        $this->assertSame([0.0, 0.275], $fresh->option_prices);
        $this->assertSame([null, 'l.png'], $fresh->option_images);
        $this->assertSame(['Small', 'Large'], $fresh->getOriginal('options'));

        $json = $fresh->toArray();
        $this->assertSame(['Small', 'Large'], $json['options']);
        $this->assertSame([0.0, 0.275], $json['option_prices']);
        $this->assertArrayNotHasKey('modifiers', $json);
    }

    public function test_a_group_without_prices_or_images_reads_null_lists(): void
    {
        $group = MenuVariantOption::create(['menu_id' => $this->item($this->store())->id, 'title' => 'Crust', 'options' => '["Thin","Thick"]']);

        $fresh = MenuVariantOption::find($group->id);
        $this->assertSame(['Thin', 'Thick'], $fresh->options);
        $this->assertNull($fresh->option_prices);
        $this->assertNull($fresh->option_images);
        $this->assertSame([null, null], DB::table('modifiers')->where('modifier_group_id', $group->id)->orderBy('position')->pluck('price_minor')->all());
    }

    public function test_editing_the_lists_keeps_rows_by_position_and_drops_removed_options(): void
    {
        $group = MenuVariantOption::create([
            'menu_id' => $this->item($this->store())->id, 'title' => 'Size',
            'options' => ['S', 'M', 'L'], 'option_prices' => [0, 1, 2],
        ]);
        $ids = Modifier::where('modifier_group_id', $group->id)->orderBy('position')->pluck('id')->all();

        $group->update(['option_prices' => [0, 1.5, 3]]); // prices only: labels kept
        $this->assertSame(['S', 'M', 'L'], MenuVariantOption::find($group->id)->options);
        $this->assertSame([0.0, 1.5, 3.0], MenuVariantOption::find($group->id)->option_prices);

        $group->update(['options' => ['Small', 'Medium']]); // relabel + shrink: prices kept by position
        $fresh = MenuVariantOption::find($group->id);
        $this->assertSame(['Small', 'Medium'], $fresh->options);
        $this->assertSame([0.0, 1.5], $fresh->option_prices);
        $this->assertSame(array_slice($ids, 0, 2), Modifier::where('modifier_group_id', $group->id)->orderBy('position')->pluck('id')->all());

        $group->delete();
        $this->assertSame(0, Modifier::where('modifier_group_id', $group->id)->count());
    }

    public function test_option_label_translations_are_mirrored_onto_the_modifiers(): void
    {
        $group = MenuVariantOption::create([
            'menu_id' => $this->item($this->store())->id, 'title' => 'Size', 'options' => ['Small', 'Large'],
            'translations' => ['ar' => ['title' => 'الحجم', 'options' => ['صغير', 'كبير']]],
        ]);

        $large = Modifier::where('modifier_group_id', $group->id)->where('position', 1)->first();
        $this->assertSame('كبير', $large->translations->ar->title);

        $group->update(['translations' => ['ar' => ['title' => 'الحجم', 'options' => ['صغير', 'كبير جدا']]]]);
        $this->assertSame('كبير جدا', $large->fresh()->translations->ar->title);
    }

    public function test_a_copy_carries_its_options(): void
    {
        $store = $this->store();
        $group = MenuVariantOption::create(['menu_id' => $this->item($store)->id, 'title' => 'Size', 'options' => ['S', 'L'], 'option_prices' => [0, 2]]);
        $other = $this->item($store);

        $copy = MenuVariantOption::find($group->id)->replicate();
        $copy->menu_id = $other->id;
        $copy->save();

        $this->assertNotSame($group->id, $copy->id);
        $fresh = MenuVariantOption::find($copy->id);
        $this->assertSame(['S', 'L'], $fresh->options);
        $this->assertSame([0.0, 2.0], $fresh->option_prices);
        $this->assertSame(2, Modifier::where('modifier_group_id', $group->id)->count(), 'the source keeps its rows');
    }

    public function test_an_add_on_lives_in_its_items_add_on_group_and_library_add_ons_share_one_group(): void
    {
        $store = $this->store();
        $item = $this->item($store);

        $cheese = MenuExtra::create(['store_id' => $store->id, 'menu_id' => $item->id, 'title' => 'Cheese', 'price' => 2]);
        $bacon = $item->extras()->create(['title' => 'Bacon', 'price' => 3]);
        $this->assertSame($cheese->modifier_group_id, $bacon->modifier_group_id);
        $group = ModifierGroup::find($cheese->modifier_group_id);
        $this->assertSame(['addon', $item->id, false], [$group->kind, (int) $group->menu_id, $group->is_global]);
        $this->assertSame('addon', DB::table('modifiers')->where('id', $cheese->id)->value('kind'));
        $this->assertSame(200, (int) DB::table('modifiers')->where('id', $cheese->id)->value('price_minor'));
        $this->assertSame('2.00', MenuExtra::find($cheese->id)->price);

        $sauce = MenuExtra::create(['store_id' => $store->id, 'is_global' => true, 'title' => 'Sauce', 'price' => 1]);
        $dip = MenuExtra::create(['store_id' => $store->id, 'is_global' => true, 'title' => 'Dip', 'price' => 1]);
        $this->assertSame($sauce->modifier_group_id, $dip->modifier_group_id);
        $library = ModifierGroup::find($sauce->modifier_group_id);
        $this->assertSame(['addon', null, true, $store->id], [$library->kind, $library->menu_id, $library->is_global, (int) $library->store_id]);

        // add-ons and option groups never read as each other
        $this->assertNull(MenuVariantOption::find($group->id));
        MenuVariantOption::create(['menu_id' => $item->id, 'title' => 'Size', 'options' => ['S']]);
        $this->assertSame(['Bacon', 'Cheese'], $item->extras()->pluck('title')->sort()->values()->all());
        $this->assertSame(['Size'], $item->variantOptions()->pluck('title')->all());

        // moving an add-on to another item moves it to that item's group
        $other = $this->item($store);
        $cheese->update(['menu_id' => $other->id]);
        $this->assertSame($other->id, (int) ModifierGroup::find($cheese->fresh()->modifier_group_id)->menu_id);
    }

    public function test_library_attachments_are_item_modifier_group_rows(): void
    {
        $store = $this->store();
        $item = $this->item($store);
        $sauce = MenuExtra::create(['store_id' => $store->id, 'is_global' => true, 'title' => 'Sauce', 'price' => 1]);
        $size = MenuVariantOption::create(['store_id' => $store->id, 'is_global' => true, 'title' => 'Size', 'options' => ['S', 'L'], 'option_prices' => [0, 2]]);

        $item->attachedExtras()->attach($sauce->id, ['price_override' => 0.75, 'position' => 0, 'active' => true]);
        $item->attachedVariantOptions()->attach($size->id, ['required' => true, 'min_select' => 1, 'max_select' => 1, 'position' => 1, 'active' => true]);

        $extraRow = DB::table('item_modifier_group')->where('menu_id', $item->id)->whereNotNull('modifier_id')->first();
        $this->assertSame([$sauce->id, $sauce->modifier_group_id, 75, $store->id], [(int) $extraRow->modifier_id, (int) $extraRow->modifier_group_id, (int) $extraRow->price_override_minor, (int) $extraRow->store_id]);
        $this->assertNotNull($extraRow->account_id);
        $groupRow = DB::table('item_modifier_group')->where('menu_id', $item->id)->whereNull('modifier_id')->first();
        $this->assertSame([$size->id, 1], [(int) $groupRow->modifier_group_id, (int) $groupRow->required]);

        $item = Menu::find($item->id);
        $this->assertSame(['Sauce'], $item->resolved_extras->pluck('title')->all());
        $this->assertSame(0.75, (float) $item->resolved_extras->first()->price);
        $resolved = $item->resolved_variant_options;
        $this->assertSame(['Size'], $resolved->pluck('title')->all());
        $this->assertSame([1, 1, true], [$resolved->first()->min_select, $resolved->first()->max_select, $resolved->first()->required]);
        $this->assertSame([0.0, 2.0], $resolved->first()->option_prices);
        $this->assertSame(1, $sauce->menus()->count());
        $this->assertSame(1, MenuVariantOption::withCount('menus')->find($size->id)->menus_count);

        // detaching every add-on leaves the attached option group alone (and the other way round)
        $item->attachedExtras()->detach();
        $this->assertSame(1, DB::table('item_modifier_group')->where('menu_id', $item->id)->count());
        $item->attachedExtras()->attach($sauce->id, ['position' => 0, 'active' => true]);
        $size->menus()->detach();
        $this->assertSame(1, DB::table('item_modifier_group')->where('menu_id', $item->id)->whereNotNull('modifier_id')->count());
        $this->assertSame(0, DB::table('item_modifier_group')->where('menu_id', $item->id)->whereNull('modifier_id')->count());

        // deleting the add-on drops its attachments (FK cascade)
        $sauce->delete();
        $this->assertSame(0, DB::table('item_modifier_group')->where('menu_id', $item->id)->count());
    }
}
