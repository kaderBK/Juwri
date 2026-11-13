<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Juwri\Modules\Accounting\Services\Accounting\OrderInvoice;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\Tax\TaxCountryRules;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Juwri\Modules\Ordering\Services\Tax\TaxLevies;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-116 / DECISIONS J32: tax responsibility sits with each store. Country defaults only for confirmed rates, every
 * uncertain rule an opt-in switch (OFF = no effect), pre-VAT levies as their own lines; order snapshot == fiscal
 * invoice == accounting export == journal; existing stores keep what they charged.
 */
class TaxRulesJ32Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true, 'settings.admin_allow_accounting_gl' => true]);
        $this->app->instance(ZatcaClient::class, new FakeZatcaClient);
        View::share(['settings' => (object) []]);
        Queue::fake();
    }

    /** @param array<string,mixed> $options */
    private function store(array $options, string $country = 'SA', string $currency = 'SAR', string $rate = '15'): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($options + ['fiscal_country' => $country, 'fiscal_legal_name' => 'Diner', 'currency_code' => $currency, 'restaurant_taxx_charge' => $rate] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    /**
     * @param  list<array{0:string,1:float,2:int}>  $items
     * @param  array<string,mixed>  $attrs
     */
    private function order(Store $store, array $items, array $attrs = []): Order
    {
        $order = Order::create($attrs + ['store_id' => $store->id, 'type' => 'delivery', 'status' => 'new', 'payment_method' => 'card']);
        foreach ($items as $row) {
            $menu = Menu::create(['store_id' => $store->id, 'name' => $row[0], 'price' => $row[1], 'active' => '1']);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $row[2]]);
        }
        $order = $order->fresh();
        $order->snapshotTotals();

        return $order->fresh();
    }

    /** @param array<string,mixed> $over */
    private static function levy(string $preset, array $over = []): array
    {
        return $over + ['id' => $preset, 'enabled' => true] + TaxLevies::PRESETS[$preset];
    }

    /**
     * order tax/total == snapshot == fiscal invoice == accounting export == journal (VAT and levies on their own lines)
     */
    private function assertOneTruth(Store $store, Order $order, int $taxMinor, int $levyMinor, int $grandMinor, int $decimals): void
    {
        $f = 10 ** $decimals;
        $money = $order->computeMoney();
        $this->assertSame($taxMinor, (int) round($money['tax'] * $f), 'order tax');
        $this->assertSame($grandMinor, (int) round($money['grand'] * $f), 'order total');
        $this->assertSame($grandMinor, (int) $order->grand_total_minor, 'stored total');
        $this->assertSame($taxMinor, (int) $order->tax_total_minor, 'stored tax');

        $snap = TaxEngine::snapshot($order);
        $this->assertNotNull($snap);
        $this->assertSame($decimals, $snap['decimals']);
        $this->assertSame($taxMinor, array_sum(array_column($snap['lines'], 'tax')), 'Σ line VAT');
        $levies = array_values(array_filter($snap['lines'], fn ($l) => $l['kind'] === 'levy'));
        $this->assertSame($levyMinor, array_sum(array_column($levies, 'amount')), 'snapshot levies');

        $fiscal = FiscalInvoiceMath::compute(OrderFiscalContext::for($store, $order), $decimals, dropOutOfScope: false);
        $this->assertSame($taxMinor, $fiscal['tax_total'], 'fiscal invoice VAT');
        $this->assertSame($grandMinor, $fiscal['payable'] + $fiscal['excluded'], 'fiscal payable');
        $fiscalLevies = array_values(array_filter($fiscal['lines'], fn ($l) => ($l['kind'] ?? null) === 'levy'));
        $this->assertCount(count($levies), $fiscalLevies, 'levies are fiscal lines of their own');

        $inv = OrderInvoice::fromOrder($order);
        $this->assertSame($grandMinor, $inv->linesMinor() + $inv->taxMinor, 'accounting export: lines + tax = total');
        $this->assertSame($levyMinor, array_sum(array_column(array_filter($inv->lines, fn ($l) => $l['kind'] === OrderInvoice::LEVY), 'amount_minor')), 'export levy lines');

        $j = OrderJournalPoster::postOrderPaid($order);
        $this->assertNotNull($j);
        $j->load('lines.account');
        $credit = fn (string $code) => (int) round((float) ($j->lines->first(fn ($l) => $l->account->code === $code)?->credit ?? 0) * $f);
        $this->assertSame($taxMinor, $credit('2200'), 'journal Output VAT');
        $this->assertSame($levyMinor, $credit('2210'), 'journal levies payable');
        $this->assertEqualsWithDelta($j->totalDebit(), $j->totalCredit(), 0.0001);
    }

    public function test_country_defaults_only_for_confirmed_rates_and_every_rate_stays_editable(): void
    {
        foreach (['SA' => '15', 'AE' => '5', 'JO' => '16', 'EG' => '14', 'LY' => '0', 'TN' => '19', 'OM' => '5', 'BH' => '10'] as $country => $rate) {
            $this->assertSame($rate, TaxCountryRules::applyDefaults(['restaurant_taxx_charge' => '', 'fiscal_country' => $country])['restaurant_taxx_charge'], $country);
        }
        $this->assertSame('', TaxCountryRules::applyDefaults(['restaurant_taxx_charge' => '', 'fiscal_country' => 'GB'])['restaurant_taxx_charge'], 'unknown country: no default');
        $this->assertSame('12.5', TaxCountryRules::applyDefaults(['restaurant_taxx_charge' => '12.5', 'fiscal_country' => 'SA'])['restaurant_taxx_charge'], 'an owner rate wins');
        $this->assertArrayNotHasKey('restaurant_taxx_charge', TaxCountryRules::applyDefaults(['fiscal_country' => 'SA']), 'no rate field, nothing written');

        // presets: off by default, picking one sets the rate
        $this->assertSame('8', TaxCountryRules::applyDefaults(['tax_rate_preset' => 'jo_tourist_8', 'restaurant_taxx_charge' => '16', 'fiscal_country' => 'JO'])['restaurant_taxx_charge']);
        $this->assertSame('7', TaxCountryRules::applyDefaults(['tax_rate_preset' => 'tn_reduced_7', 'restaurant_taxx_charge' => '19'])['restaurant_taxx_charge']);
        $this->assertSame('13', TaxCountryRules::applyDefaults(['tax_rate_preset' => 'tn_reduced_13', 'restaurant_taxx_charge' => '19'])['restaurant_taxx_charge']);
        $this->assertSame('16', TaxCountryRules::applyDefaults(['tax_rate_preset' => '', 'restaurant_taxx_charge' => '16'])['restaurant_taxx_charge']);
    }

    public function test_each_fee_switch_is_off_by_default_and_taxes_only_its_own_fee_when_on(): void
    {
        $attrs = ['type' => 'room_service', 'delivery_fee' => 0, 'service_charge_amount' => 10];
        // all off: VAT on the item only, fee lines out of scope
        $store = $this->store(['tax_engine' => 'vat']);
        $order = $this->order($store, [['Kabsa', 100.0, 1]], $attrs);
        $this->assertSame(1500, (int) $order->tax_total_minor);
        $this->assertSame(['O'], array_values(array_map(fn ($l) => $l['category'], array_filter(TaxEngine::snapshot($order)['lines'], fn ($l) => $l['kind'] === 'fee'))));

        // service charge on: + 10.00 × 15 % = 1.50
        $store = $this->store(['tax_engine' => 'vat', 'tax_service_taxable' => '1']);
        $order = $this->order($store, [['Kabsa', 100.0, 1]], $attrs);
        $this->assertSame(1650, (int) $order->tax_total_minor);
        $this->assertSame(10000 + 1000 + 1650, (int) $order->grand_total_minor);

        // delivery on, service off: delivery taxed, service not
        $store = $this->store(['tax_engine' => 'vat', 'tax_delivery_taxable' => '1']);
        $order = $this->order($store, [['Kabsa', 100.0, 1]], ['delivery_fee' => 20, 'service_charge_amount' => 10]);
        $this->assertSame(1500 + 300, (int) $order->tax_total_minor);
        $fees = array_column(array_filter(TaxEngine::snapshot($order)['lines'], fn ($l) => $l['kind'] === 'fee'), 'category', 'name');
        $this->assertSame(['Delivery' => 'S', 'Service charge' => 'O'], $fees);
        $this->assertSame(['delivery' => true, 'tray' => false, 'service' => false], TaxEngine::snapshot($order)['fees']);
    }

    public function test_a_levy_is_off_by_default_and_has_no_effect(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'tax_levies' => [self::levy('ae_municipality', ['enabled' => false])]], 'AE', 'AED', '5');
        $order = $this->order($store, [['Shawarma', 100.0, 1]], ['type' => 'takeaway']);
        $this->assertSame(500, (int) $order->tax_total_minor);
        $this->assertSame(10500, (int) $order->grand_total_minor);
        $this->assertSame([], TaxEngine::levyLines($order));

        // a store not on the engine with only a disabled levy stays legacy
        $legacy = $this->store(['tax_levies' => [self::levy('ae_municipality', ['enabled' => false])]], 'AE', 'AED', '5');
        $this->assertFalse(TaxEngine::applies($this->order($legacy, [['Shawarma', 100.0, 1]], ['type' => 'takeaway'])));
    }

    public function test_a_levy_comes_before_vat_on_its_own_line_in_minor_units(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'tax_levies' => [self::levy('ae_municipality')]], 'AE', 'AED', '5');
        $order = $this->order($store, [['Mixed grill', 33.35, 3]], ['type' => 'takeaway', 'discount_amount' => 10.05]);

        // items 100.05 − 10.05 = 90.00 → municipality fee 7 % = 6.30; VAT 90.00×5 % = 4.50 + 6.30×5 % = 0.315 → 0.32
        $this->assertOneTruth($store, $order, 450 + 32, 630, 9000 + 630 + 482, 2);
        $lines = TaxEngine::levyLines($order);
        $this->assertSame([['id' => 'ae_municipality', 'name' => 'Municipality fee', 'amount' => 630, 'tax' => 32, 'decimals' => 2]], $lines);
    }

    public function test_a_levy_with_tax_inclusive_prices_is_added_on_top(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'tax_prices_include' => '1', 'tax_levies' => [self::levy('eg_table')]], 'EG', 'EGP', '14');
        $order = $this->order($store, [['Koshary', 114.0, 1]], ['type' => 'takeaway']);

        // 114.00 gross holds 14.00 VAT → net 100.00 → table tax 5 % = 5.00, its VAT 0.70 on top
        $this->assertOneTruth($store, $order, 1400 + 70, 500, 11400 + 500 + 70, 2);
    }

    public function test_libya_stamp_duty_threshold_in_three_decimals(): void
    {
        $store = $this->store(['tax_levies' => [self::levy('ly_stamp')]], 'LY', 'LYD', '0');

        // 80.250 + delivery 5.000 = 85.250 ≤ LYD 100 → 0.5 % = 0.42625 → 0.426; no VAT in Libya
        $order = $this->order($store, [['Bazin', 80.25, 1]], ['delivery_fee' => 5]);
        $this->assertTrue(TaxEngine::applies($order), 'a levy alone puts the order on the engine');
        $this->assertOneTruth($store, $order, 0, 426, 80250 + 5000 + 426, 3);

        // 98.125 + 5.000 = 103.125 > LYD 100 → 1 % = 1.03125 → 1.031
        $order = $this->order($store, [['Bazin', 98.125, 1]], ['delivery_fee' => 5]);
        $this->assertOneTruth($store, $order, 0, 1031, 98125 + 5000 + 1031, 3);

        // exactly LYD 100 is not above the threshold
        $order = $this->order($store, [['Bazin', 100.0, 1]], ['type' => 'takeaway']);
        $this->assertSame(500, TaxEngine::levyLines($order)[0]['amount']);
    }

    public function test_a_placed_order_keeps_its_levies_and_fee_switches(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'tax_delivery_taxable' => '1', 'tax_levies' => [self::levy('ae_municipality')]], 'AE', 'AED', '5');
        $order = $this->order($store, [['Grill', 100.0, 1]], ['delivery_fee' => 10]);
        $grand = (int) $order->grand_total_minor;
        $this->assertSame(10000 + 1000 + 700 + 500 + 50 + 35, $grand);

        StoreOption::updateStoreOption($store->id, 'tax_levies', []);
        StoreOption::updateStoreOption($store->id, 'tax_delivery_taxable', '0');
        $order = Order::find($order->id);
        $order->snapshotTotals();
        $this->assertSame($grand, (int) $order->fresh()->grand_total_minor);
        $this->assertSame(700, TaxEngine::levyLines($order->fresh())[0]['amount']);
    }

    public function test_a_v1_snapshot_keeps_its_single_fee_switch(): void
    {
        $store = $this->store(['tax_engine' => 'vat']);
        $order = $this->order($store, [['Kabsa', 40.0, 1]], ['delivery_fee' => 10]);
        $v1 = TaxEngine::snapshot($order);
        unset($v1['fees'], $v1['levies']);
        $v1['v'] = 1;
        $v1['fees_taxable'] = true;
        DB::table('orders')->where('id', $order->id)->update(['tax_lines' => json_encode($v1)]);

        $this->assertSame(7.5, Order::find($order->id)->computeMoney()['tax'], 'v1 fees_taxable still taxes the delivery fee');
    }

    public function test_the_migration_keeps_what_existing_stores_charged(): void
    {
        $engineCountry = $this->store(['tax_engine' => 'vat']);                       // '' + SA: country rule taxed fees
        $engineNo = $this->store(['tax_engine' => 'vat', 'tax_fees_taxable' => '0']);
        $engineYes = $this->store(['tax_engine' => 'vat', 'tax_fees_taxable' => '1'], 'GB', 'GBP', '20');
        $engineGb = $this->store(['tax_engine' => 'vat'], 'GB', 'GBP', '20');         // country rule: no
        $legacy = $this->store([]);                                                   // not on the engine
        StoreOption::updateStoreOption($engineCountry->id, 'tax_fees_taxable', '');

        $migration = require base_path('app/Modules/Ordering/Database/Migrations/2026_10_13_180000_split_tax_fees_taxable.php');
        $migration->up();
        StoreSettings::flush();

        foreach ([[$engineCountry, true], [$engineNo, false], [$engineYes, true], [$engineGb, false]] as [$store, $taxed]) {
            foreach (['tax_delivery_taxable', 'tax_tray_taxable', 'tax_service_taxable'] as $key) {
                $this->assertSame($taxed, StoreSettings::bool($store->id, $key), $store->id.' '.$key);
            }
            $this->assertFalse(StoreSettings::has($store->id, 'tax_fees_taxable'), 'the retired setting is gone');
        }
        $this->assertFalse(StoreSettings::has($legacy->id, 'tax_delivery_taxable'), 'a legacy store gets no switch (off)');

        $order = $this->order($engineCountry, [['Kabsa', 40.0, 1]], ['delivery_fee' => 10]);
        $this->assertSame(600 + 150, (int) $order->tax_total_minor, 'a KSA engine store still taxes delivery');
    }

    public function test_legacy_store_unchanged_by_the_new_switches(): void
    {
        // P6-186 (J44): a KSA store is always on the engine; the legacy tax lives on outside KSA
        $store = $this->store(['tax_delivery_taxable' => '1', 'tax_service_taxable' => '1', 'fiscal_country' => 'JO']);
        $order = $this->order($store, [['Kabsa', 42.5, 2]], ['delivery_fee' => 11.5, 'discount_amount' => 10.1]);
        $this->assertFalse(TaxEngine::applies($order));
        $this->assertSame(1124, (int) $order->tax_total_minor, 'legacy (P6-271): 15 % on items after the discount, no fee VAT');
        $this->assertSame(8500 + 1150 - 1010 + 1124, (int) $order->grand_total_minor);
    }

    public function test_levy_input_is_cleaned(): void
    {
        $rows = TaxLevies::normalize([
            ['id' => 'ly_stamp', 'name' => ' Stamp duty ', 'type' => 'percent', 'value' => '0.5', 'base' => 'total', 'over' => '100', 'value_over' => '1', 'enabled' => '1'],
            ['id' => '', 'name' => '', 'value' => '9'],                                            // no name: dropped
            ['id' => 'x', 'name' => '<b>Fee</b>', 'type' => 'fixed', 'value' => '2.5', 'over' => '5', 'value_over' => '3', 'enabled' => '0'],
            ['name' => 'Big', 'value' => '250'],
        ]);
        $this->assertCount(3, $rows);
        $this->assertSame(['id' => 'ly_stamp', 'name' => 'Stamp duty', 'type' => 'percent', 'value' => 0.5, 'base' => 'total', 'over' => 100.0, 'value_over' => 1.0, 'enabled' => true], $rows[0]);
        $this->assertSame(['Fee', 'fixed', null, null, false], [$rows[1]['name'], $rows[1]['type'], $rows[1]['over'], $rows[1]['value_over'], $rows[1]['enabled']]);
        $this->assertSame(100.0, $rows[2]['value'], 'a percentage is capped at 100');
        $this->assertSame(250, TaxLevies::amount($rows[1], 0, 2), 'fixed levy in minor units');
        $this->assertSame(2500, TaxLevies::amount($rows[1], 99999, 3), 'fixed LYD 2.500');
    }

    public function test_the_owner_saves_switches_levies_and_a_preset_and_sees_the_disclaimer(): void
    {
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
        app()->setLocale('en');
        $store = $this->store(['store_template' => 'juwri'], 'JO', 'JOD', '16');
        $owner = User::find($store->user_id);

        $this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk()
            ->assertSee('Tax rates are set by you. Check them with your accountant or tax authority.')
            ->assertSee('name="tax_service_taxable"', false)
            ->assertSee('Jordan tourist restaurant 8%');

        $this->actingAs($owner)->put(route('stores.update', $store->id), [
            'color' => '#111', 'title' => 'Diner', 'description' => 'd', 'address' => '1', 'store_template' => 'juwri',
            'currency_code' => 'JOD', 'currency_sign' => 'JD', 'currency_pos' => 0, 'fiscal_country' => 'JO',
            'restaurant_taxx_charge' => '16', 'tax_rate_preset' => 'jo_tourist_8',
            'tax_delivery_taxable' => '0', 'tax_tray_taxable' => '0', 'tax_service_taxable' => '1',
            'tax_levies' => [
                ['id' => 'custom', 'name' => 'City fee', 'type' => 'percent', 'value' => '2', 'base' => 'items', 'enabled' => '1'],
                ['id' => '', 'name' => '', 'value' => '', 'enabled' => '0'],
            ],
        ])->assertSessionHasNoErrors();

        StoreSettings::flush();
        $this->assertSame('8', (string) store_options($store->id, 'restaurant_taxx_charge'), 'the preset set the rate');
        $this->assertTrue(StoreSettings::bool($store->id, 'tax_service_taxable'));
        $this->assertFalse(StoreSettings::bool($store->id, 'tax_delivery_taxable'));
        $levies = TaxLevies::all(store_options($store->id));
        $this->assertCount(1, $levies);
        $this->assertSame(['City fee', 2.0, true], [$levies[0]['name'], $levies[0]['value'], $levies[0]['enabled']]);
    }
}
