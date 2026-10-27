<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyHistoryFixture as History;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyModifierFixture as Modifiers;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\HistoryImporter;
use Juwri\Modules\LegacyCompat\Import\ImportGuard;
use Tests\TestCase;

/**
 * J52b M4c (DDL diff §5 #6): menu modifiers in `legacy:import` against a SYNTHETIC source (J19): variants, option groups
 * (+ their options), add-ons of items and of the store library, item links with per-item price overrides, the platform
 * add-on library; ids kept where order lines name them, derived rows recorded; cross-store rows refused; order line
 * extras resolve to the imported add-ons of the same store only and price from the per-item override.
 */
class LegacyModifierImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_modifier_source';

    private Source $src;

    private History $hist;

    private Modifiers $mod;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'UTC',
            'legacy_compat.import.source_app_key' => '',
        ]);
        DB::purge(self::SOURCE);
        $db = DB::connection(self::SOURCE);
        $this->src = (new Source($db))->createSchema();
        $this->hist = (new History($db))->createSchema();
        $this->mod = (new Modifiers($db))->createSchema()->addLaterColumns();
        $this->src->platformOption('currency_code', 'USD');
        $this->src->user(1);
        $this->src->user(2);
        $this->store(10, 1, 'SAR');
        $this->store(20, 2, 'SAR');
        $this->src->item(100, 10, null, 10.00);
        $this->src->item(101, 10, null, 4.00);
        $this->src->item(200, 20, null, 7.00);
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    private function store(int $id, int $owner, string $currency): void
    {
        $this->src->store($id, $owner);
        $this->src->storeOption($id, 'currency_code', $currency);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function import(array $args = []): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE] + $args);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    private function catalogue(): void
    {
        $this->hist->variant(7, 100, 12.5, ['sku' => 'SKU-7']);
        $this->mod->optionGroup(20, 10, 100, 'Size', ['Small', 'Large'], [0, 2.5], [
            'required' => true, 'min_select' => 1, 'max_select' => 1,
            'translations' => json_encode(['ar' => ['title' => 'Size AR', 'options' => ['Small AR', 'Large AR']]]),
        ]);
        $this->mod->optionGroup(21, 10, null, 'Sauces', ['Mild', 'Hot']);
        $this->hist->extra(50, 100, 1.25, ['restaurant_id' => 10]);
        $this->mod->platformExtra(900, 'Platform cheese', 0.75);
        $this->hist->extra(60, 0, 3.00, ['menu_id' => null, 'restaurant_id' => 10, 'is_global' => true, 'source_global_id' => 900]);
        $this->mod->itemExtraLink(70, 101, 60, '2.000');
        $this->mod->itemOptionGroupLink(71, 101, 21, ['min_select' => 0, 'max_select' => 2]);
    }

    public function test_variants_option_groups_add_ons_and_links_land_with_their_store(): void
    {
        $this->catalogue();

        $report = $this->import();

        $variant = DB::table('menu_variants')->where('id', 7)->first();
        $this->assertSame([100, 10, 1250, 'SAR', 'SKU-7'], [(int) $variant->menu_id, (int) $variant->store_id, (int) $variant->price_minor, $variant->currency_code, $variant->sku]);

        $group = DB::table('modifier_groups')->where('id', 20)->first();
        $this->assertSame(['variant', 100, 10, 1, true], [$group->kind, (int) $group->menu_id, (int) $group->store_id, (int) $group->min_select, (bool) $group->required]);
        $options = DB::table('modifiers')->where('modifier_group_id', 20)->orderBy('position')->get();
        $this->assertSame(['Small', 'Large'], $options->pluck('title')->all());
        $this->assertSame([0, 250], $options->pluck('price_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['variant', 'variant'], $options->pluck('kind')->all());
        $this->assertSame('Large AR', json_decode((string) $options[1]->translations, true)['ar']['title']);
        // The library group keeps no item; its options have no price.
        $this->assertNull(DB::table('modifier_groups')->where('id', 21)->value('menu_id'));
        $this->assertSame([null, null], DB::table('modifiers')->where('modifier_group_id', 21)->orderBy('position')->pluck('price_minor')->all());

        // Add-ons keep their ids, in the derived add-on group of their item / of the library.
        $extra = DB::table('modifiers')->where('id', 50)->first();
        $this->assertSame(['addon', 100, 125], [$extra->kind, (int) $extra->menu_id, (int) $extra->price_minor]);
        $itemGroup = DB::table('modifier_groups')->where('id', $extra->modifier_group_id)->first();
        $this->assertSame(['addon', 100, 10], [$itemGroup->kind, (int) $itemGroup->menu_id, (int) $itemGroup->store_id]);
        $library = DB::table('modifiers')->where('id', 60)->first();
        $this->assertNull($library->menu_id);
        $this->assertSame([300, 900], [(int) $library->price_minor, (int) $library->source_global_id]);
        $libraryGroup = DB::table('modifier_groups')->where('id', $library->modifier_group_id)->first();
        $this->assertTrue((bool) $libraryGroup->is_global);
        $this->assertNull($libraryGroup->menu_id);

        // Links: the add-on link keeps its id and its per-item override; the option-group link gets a new id.
        $link = DB::table('item_modifier_group')->where('id', 70)->first();
        $this->assertSame([101, 60, (int) $library->modifier_group_id, 200, 'SAR', 10], [
            (int) $link->menu_id, (int) $link->modifier_id, (int) $link->modifier_group_id, (int) $link->price_override_minor,
            $link->currency_code, (int) $link->store_id,
        ]);
        $groupLink = DB::table('item_modifier_group')->where('modifier_group_id', 21)->first();
        $this->assertNotNull($groupLink);
        $this->assertNull($groupLink->modifier_id);
        $this->assertGreaterThan(71, (int) $groupLink->id);
        $this->assertSame((string) $groupLink->id, DB::table('legacy_import_rows')->where('source_table', 'menu_item_variant_option')->where('source_id', '71')->value('target_id'));

        // Platform library in the platform currency.
        $platform = DB::table('global_menu_extras')->where('id', 900)->first();
        $this->assertSame([75, 'USD'], [(int) $platform->price_minor, $platform->currency_code]);

        // Every row (derived ones too) is recorded: the resume guard sees no foreign row, a second run skips the store.
        $this->assertSame([], ImportGuard::targetReasons(DB::connection()));
        $this->assertSame(4, $report['counters']['modifier_options_written']);
        $again = $this->import();
        $this->assertSame(2, $again['counters']['stores_already_imported']);
        $this->assertSame(1, DB::table('menu_variants')->count());
    }

    public function test_a_modifier_row_of_another_store_is_refused_never_linked_across_stores(): void
    {
        $this->catalogue();
        // An add-on hanging on store 10's item but naming store 20, and links from store 20's item to store 10's rows.
        $this->hist->extra(51, 100, 9.99, ['restaurant_id' => 20]);
        $this->mod->optionGroup(22, 20, 100, 'Foreign', ['X']);
        $this->mod->itemExtraLink(72, 200, 50);
        $this->mod->itemOptionGroupLink(73, 200, 20);
        $this->src->order(5000, 20);
        $this->src->orderItem(9000, 5000, 200, 1);
        $this->hist->orderItemExtra(9500, 9000, 50); // store 10's add-on on a store 20 line

        $report = $this->import();

        $this->assertNull(DB::table('modifiers')->where('id', 51)->first());
        $this->assertNull(DB::table('modifier_groups')->where('id', 22)->first());
        $this->assertNull(DB::table('item_modifier_group')->where('id', 72)->first());
        $this->assertSame(0, DB::table('item_modifier_group')->where('menu_id', 200)->count());
        $this->assertSame(1, $report['tables']['modifiers']['skipped'] ?? null, json_encode($report['tables']['modifiers'] ?? []));

        $line = DB::table('order_item_extras')->where('id', 9500)->first();
        $this->assertNull($line->extra_id);
        $this->assertNull($line->price_minor);
        $conflicts = implode("\n", array_keys($report['conflicts']));
        $this->assertStringContainsString('order_item_extras.extra_id: modifiers row not in this store', $conflicts);
        $this->assertSame([HistoryImporter::PRICE_UNKNOWN], DB::table('legacy_import_marks')->where('target_table', 'order_item_extras')->where('target_id', '9500')->pluck('mark')->all());
    }

    public function test_line_extras_resolve_to_imported_add_ons_and_snapshot_the_per_item_override(): void
    {
        $this->catalogue();
        $this->src->order(5000, 10);
        $this->src->orderItem(9000, 5000, 101, 2);   // item 101 links library add-on 60 at 2.000
        $this->hist->orderItemExtra(9500, 9000, 60);
        $this->src->orderItem(9001, 5000, 100, 1);   // item 100: add-on 60 not linked, its own price
        $this->hist->orderItemExtra(9501, 9001, 60);
        $this->hist->orderItemExtra(9502, 9001, 50);

        $this->import();

        $extra = fn (int $id) => DB::table('order_item_extras')->where('id', $id)->first();
        $this->assertSame([60, 200], [(int) $extra(9500)->extra_id, (int) $extra(9500)->price_minor]);
        $this->assertSame([60, 300], [(int) $extra(9501)->extra_id, (int) $extra(9501)->price_minor]);
        $this->assertSame([50, 125], [(int) $extra(9502)->extra_id, (int) $extra(9502)->price_minor]);
        $basis = fn (int $id): string => json_decode((string) DB::table('legacy_import_marks')->where('target_table', 'order_item_extras')
            ->where('target_id', (string) $id)->value('detail'), true)['basis'];
        $this->assertSame('extra_override', $basis(9500));
        $this->assertSame('extra', $basis(9501));
        // Derived subtotal: extras per unit of their line.
        $this->assertSame(400 * 2 + 200 * 2 + 1000 + 300 + 125, (int) DB::table('orders')->where('id', 5000)->value('subtotal_minor'));
    }

    public function test_a_source_without_the_later_modifier_columns_still_imports_item_add_ons(): void
    {
        // Base-shaped add-on table (no store column): item add-ons only, no "no store column" noise.
        DB::connection(self::SOURCE)->getSchemaBuilder()->drop('menu_extras');
        DB::connection(self::SOURCE)->getSchemaBuilder()->drop('menu_variant_options');
        $this->hist->createSchema();
        $this->hist->extra(50, 100, 1.25);

        $report = $this->import();

        $this->assertSame(125, (int) DB::table('modifiers')->where('id', 50)->value('price_minor'));
        $conflicts = implode("\n", array_keys($report['conflicts']));
        $this->assertStringNotContainsString('menu_extras: no store column', $conflicts);
        $this->assertStringNotContainsString('menu_variant_options: no store column', $conflicts);
    }
}
