<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use App\Models\GiftCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyProdShapeFixture as Shape;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\GiftCardBalances;
use Juwri\Modules\LegacyCompat\Import\HistoryImporter;
use Tests\TestCase;

/**
 * J52b M4b step 2: the importer against the later source schema (SYNTHETIC rows, J19): soft-deleted items, the full
 * catalogue columns, item cost, the later order columns (tracking token kept verbatim, money extras in the derived
 * total), weighed lines, and gift-card balances derived from the orders that redeemed them (opening ledger balance,
 * a store whose cards were over-redeemed is blocked).
 */
class LegacyLaterSchemaImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_later_source';

    private Source $src;

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
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        (new Shape(DB::connection(self::SOURCE)))->laterColumns();
        $this->src->platformOption('currency_code', 'USD');
        $this->src->user(1);
        $this->src->store(10, 1);
        $this->src->storeOption(10, 'currency_code', 'SAR');
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function import(int $expectedExit = 0): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE]);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    /** @return list<string> */
    private function marks(string $table, int $id): array
    {
        return DB::table('legacy_import_marks')->where('target_table', $table)->where('target_id', (string) $id)
            ->orderBy('mark')->pluck('mark')->all();
    }

    public function test_deleted_items_stay_deleted_but_resolve_for_order_lines_and_catalogue_columns_are_kept(): void
    {
        $s = $this->src;
        $s->item(1000, 10, null, 9.5, [
            'cost' => 3.125, 'plu' => 'PLU-1', 'is_vegan' => true, 'is_sold_out' => true, 'calories' => 420, 'brand_id' => 7,
            'auto_86_recipe' => true,
        ]);
        $s->item(1001, 10, null, 4.0, ['deleted_at' => '2025-04-01 10:00:00']);
        $s->order(5000, 10);
        $s->orderItem(9000, 5000, 1001, 2);

        $report = $this->import();

        $live = DB::table('items')->where('id', 1000)->first();
        $this->assertSame(313, (int) $live->cost_minor); // SAR: 2 decimals, half up
        $this->assertSame('PLU-1', $live->plu);
        $this->assertTrue((bool) $live->is_vegan);
        $this->assertTrue((bool) $live->is_sold_out);
        $this->assertSame(420, (int) $live->calories);
        $this->assertNull($live->deleted_at);
        $this->assertNull($live->brand_id); // brands are not imported: dropped and reported
        $this->assertSame(1, $report['conflicts']['items.brand_id: brands row not in this store (or not imported), set NULL']['count']);
        $this->assertNotContains('auto_86_recipe', $report['unknown_source_columns']['menu'] ?? []);

        $this->assertStringStartsWith('2025-04-01 10:00:00', (string) DB::table('items')->where('id', 1001)->value('deleted_at'));
        $this->assertSame(1001, (int) DB::table('order_items')->where('id', 9000)->value('item_id'));
        $this->assertSame(400, (int) DB::table('order_items')->where('id', 9000)->value('unit_price_minor'));

        // The nightly purge of old trash never takes an imported item that order lines name, nor the lines.
        Artisan::call('menu:purge-trashed', ['--days' => 30]);
        $this->assertTrue(DB::table('items')->where('id', 1001)->exists());
        $this->assertSame(1001, (int) DB::table('order_items')->where('id', 9000)->value('item_id'));
    }

    public function test_later_order_columns_are_kept_and_money_extras_count_in_a_derived_total(): void
    {
        $s = $this->src;
        $s->item(1000, 10, null, 10.0);
        $s->order(5000, 10, [
            'track_token' => 'TrK-synthetic-Token-0001', 'fx_rate_base' => 0.26666667, 'locale' => 'ar', 'source' => 'pos',
            'service_charge_amount' => 1.5, 'donation_amount' => 0.25, 'channel_fee' => 4.0, 'mk_wallet_applied' => 2.0,
            'tip_amount' => 1.0, 'discount_amount' => 0.75, 'preparing_at' => '2025-03-01 20:20:00', 'pod_otp' => '123456',
        ]);
        $s->orderItem(9000, 5000, 1000, 2, ['status' => 'served', 'seat' => 3, 'options' => '{"size":"L"}']);

        $this->import();

        $order = DB::table('orders')->where('id', 5000)->first();
        $this->assertSame('TrK-synthetic-Token-0001', $order->track_token);
        $this->assertSame('ar', $order->locale);
        $this->assertSame('pos', $order->source);
        $this->assertEqualsWithDelta(0.26666667, (float) $order->fx_rate_base, 1e-8);
        $this->assertSame(150, (int) $order->service_charge_amount_minor);
        $this->assertSame(25, (int) $order->donation_amount_minor);
        $this->assertSame(400, (int) $order->channel_fee_minor);
        $this->assertSame(200, (int) $order->mk_wallet_applied_minor);
        $this->assertStringStartsWith('2025-03-01 20:20:00', (string) $order->preparing_at);
        $this->assertNull($order->pod_otp);
        // 20.00 lines + 1.00 tip + 0.25 donation + 1.50 service − 0.75 discount; the channel commission is not the customer's.
        $this->assertSame(2000, (int) $order->subtotal_minor);
        $this->assertSame(2200, (int) $order->grand_total_minor);
        $line = DB::table('order_items')->where('id', 9000)->first();
        $this->assertSame('served', $line->status);
        $this->assertSame(3, (int) $line->seat);
        $this->assertSame(['size' => 'L'], json_decode((string) $line->options, true)); // JSON columns may re-space it
    }

    public function test_a_weighed_line_without_a_recorded_price_is_priced_per_kg_times_its_weight(): void
    {
        $s = $this->src;
        $s->item(1000, 10, null, 12.0, ['pricing_unit' => 'kg']);
        $s->order(5000, 10);
        $s->orderItem(9000, 5000, 1000, 1, ['weight_kg' => 0.755]);
        $s->orderItem(9001, 5000, 1000, 1, ['weight_kg' => 2.5, 'unit_price' => 30.0]);

        $this->import();

        $this->assertSame(906, (int) DB::table('order_items')->where('id', 9000)->value('unit_price_minor')); // 12.00 × 0.755 = 9.06
        $this->assertSame(3000, (int) DB::table('order_items')->where('id', 9001)->value('unit_price_minor')); // recorded wins
        $this->assertSame('0.755', rtrim(rtrim(number_format((float) DB::table('order_items')->where('id', 9000)->value('weight_kg'), 3, '.', ''), '0'), '.'));
        $this->assertContains(HistoryImporter::PRICE_SNAPSHOT, $this->marks('order_items', 9000));
        $this->assertSame(3906, (int) DB::table('orders')->where('id', 5000)->value('subtotal_minor'));
    }

    public function test_gift_card_balance_is_its_value_minus_the_orders_that_redeemed_it(): void
    {
        $s = $this->src;
        $s->customer(300, 10);
        $s->giftCard(84, 10, 'GIFT-SYNTH-84', 'SAR', 50.0);
        $s->giftCard(85, 10, 'GIFT-SYNTH-85', 'SAR', 20.0, ['status' => 'pending']);
        $s->giftCard(86, 10, 'GIFT-SYNTH-86', 'SAR', 20.0, ['status' => 'void']);
        $s->order(5000, 10, ['gift_card_id' => 84, 'gift_card_applied' => 10.0]);
        $s->order(5001, 10, ['gift_card_id' => 84, 'gift_card_applied' => 15.5]);

        $report = $this->import();

        $this->assertSame(84, (int) DB::table('orders')->where('id', 5000)->value('gift_card_id'));
        $this->assertSame(1550, (int) DB::table('orders')->where('id', 5001)->value('gift_card_applied_minor'));
        $this->assertEqualsWithDelta(24.5, GiftCard::query()->withoutGlobalScopes()->findOrFail(84)->balance(), 0.0001);
        $this->assertEqualsWithDelta(0.0, GiftCard::query()->withoutGlobalScopes()->findOrFail(85)->balance(), 0.0001); // never funded
        $this->assertEqualsWithDelta(0.0, GiftCard::query()->withoutGlobalScopes()->findOrFail(86)->balance(), 0.0001); // not active
        $this->assertContains(GiftCardBalances::OPENING, $this->marks('gift_cards', 84));
        $this->assertSame(2450, $report['store_totals']['10']['gift_card_opening_minor_sum']);
        // A balanced posting: the clearing leg mirrors the card leg.
        $this->assertSame(0, (int) DB::table('ledger_entries')->where('ref', 'like', GiftCardBalances::REF_PREFIX.'84:%')->sum('amount_minor'));
    }

    public function test_an_over_redeemed_gift_card_blocks_the_store(): void
    {
        $s = $this->src;
        $s->giftCard(84, 10, 'GIFT-SYNTH-84', 'SAR', 10.0);
        $s->order(5000, 10, ['gift_card_id' => 84, 'gift_card_applied' => 8.0]);
        $s->order(5001, 10, ['gift_card_id' => 84, 'gift_card_applied' => 2.5]);

        $report = $this->import(1);

        $this->assertFalse(DB::table('stores')->where('id', 10)->exists());
        $this->assertSame(0, DB::table('gift_cards')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertSame(['store#10 gift_card#84'], $report['conflicts']['blocked: stores: '.GiftCardBalances::REASON]['samples']);
    }

    public function test_a_card_named_by_another_stores_order_blocks_its_store(): void
    {
        $s = $this->src;
        $s->user(2);
        $s->store(20, 2);
        $s->storeOption(20, 'currency_code', 'SAR');
        $s->giftCard(84, 10, 'GIFT-SYNTH-84', 'SAR', 50.0);
        $s->order(6000, 20, ['gift_card_id' => 84, 'gift_card_applied' => 5.0]);

        $report = $this->import(1);

        $this->assertFalse(DB::table('stores')->where('id', 10)->exists());
        $this->assertSame(['store#10 gift_card#84'], $report['conflicts']['blocked: stores: '.GiftCardBalances::REASON]['samples']);
        $this->assertNull(DB::table('orders')->where('id', 6000)->value('gift_card_id')); // never linked across stores
    }

    public function test_a_redemption_that_did_not_land_blocks_the_store_instead_of_inflating_the_card(): void
    {
        $s = $this->src;
        $s->giftCard(84, 10, 'GIFT-SYNTH-84', 'SAR', 50.0);
        $s->order(5000, 10, ['gift_card_id' => 84, 'gift_card_applied' => 10.0]);
        $s->order(0, 10, ['gift_card_id' => 84, 'gift_card_applied' => 5.0]); // a row the loader refuses (no usable id)

        $report = $this->import(1);

        $this->assertFalse(DB::table('stores')->where('id', 10)->exists());
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertSame(['store#10'], $report['conflicts']['blocked: stores: '.GiftCardBalances::REASON.' after import (gift_card#84)']['samples']);
    }
}
