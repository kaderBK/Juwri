<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemExtra;
use App\Models\StaffMember;
use App\Services\PosService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P6-148 golden: the order-write paths (storefront API checkout and POS create) of a mixed cart — choice-group options,
 * a priced add-on with a per-item price override, a variant (with its own stock), an item on its own VAT rate, a
 * zero-rated item, a stock-tracked item, the WELCOME10 coupon — on the VAT engine with prices exclusive and inclusive.
 * Everything money-shaped the order writes (order minor columns, the frozen tax lines, each line and add-on, the stock
 * left, the fiscal invoice math, the journal) must equal tests/Fixtures/order-write-golden-p6148.json, which was
 * recorded on the code BEFORE the per-line reads were batched (P6-148). Ids are left out (they differ per database).
 * GOLDEN_WRITE=1 prints the snapshots instead of comparing (to re-record after a deliberate money change).
 */
class OrderWriteGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = __DIR__.'/../../Fixtures/order-write-golden-p6148.json';

    private ApiFixtures $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 18:40:00', 'UTC'));
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true, 'settings.admin_allow_accounting_gl' => true]);
        $this->app->instance(ZatcaClient::class, new FakeZatcaClient);
        View::share(['settings' => (object) []]);
        Queue::fake();
        $this->fx = new ApiFixtures;
    }

    /** @return array{store: Store, ids: array<string, int>, pub: array<string, string>} */
    private function catalogue(bool $inclusive, bool $feesTaxable): array
    {
        $store = $this->fx->saffron($this->fx->owner(['pos_enabled' => '1']));
        StoreSettings::setMany((int) $store->id, [
            'tax_engine' => 'vat', 'tax_prices_include' => $inclusive ? '1' : '0', 'tax_delivery_taxable' => $feesTaxable ? '1' : '0', 'tax_service_taxable' => $feesTaxable ? '1' : '0',
            // J32 levies: a % levy on the items, a % levy on the total with a threshold (above 100 the higher rate), a fixed levy
            'tax_levies' => [
                ['id' => 'muni', 'name' => 'Municipality fee', 'type' => 'percent', 'value' => 7.0, 'base' => 'items', 'over' => null, 'value_over' => null, 'enabled' => true],
                ['id' => 'stamp', 'name' => 'Stamp duty', 'type' => 'percent', 'value' => 0.5, 'base' => 'total', 'over' => 100.0, 'value_over' => 1.0, 'enabled' => true],
                ['id' => 'bag', 'name' => 'Bag fee', 'type' => 'fixed', 'value' => 1.25, 'base' => 'total', 'over' => null, 'value_over' => null, 'enabled' => true],
                ['id' => 'off', 'name' => 'Disabled levy', 'type' => 'percent', 'value' => 3.0, 'base' => 'items', 'over' => null, 'value_over' => null, 'enabled' => false],
            ],
            'fiscal_country' => 'SA', 'fiscal_legal_name' => 'Saffron House', 'fiscal_vat_number' => '300000000000003',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Golden', 'active' => 1]);
        $water = $this->fx->item($store, $cat, (string) Str::uuid7(), 'Water', 2.5);
        Menu::query()->whereKey($water->id)->update(['tax_rate' => 5, 'track_inventory' => 1, 'stock_qty' => 50]);
        $pills = $this->fx->item($store, $cat, (string) Str::uuid7(), 'Paracetamol', 10);
        Menu::query()->whereKey($pills->id)->update(['tax_category' => 'Z']);
        $juice = $this->fx->item($store, $cat, (string) Str::uuid7(), 'Juice', 9);
        $large = MenuVariant::create(['menu_id' => $juice->id, 'store_id' => $store->id, 'price' => 12.35, 'sku' => 'JUICE-L', 'stock_qty' => 30, 'active' => 1, 'position' => 1]);
        $tawook = Menu::query()->where('store_id', $store->id)->where('public_id', ApiFixtures::TAWOOK)->firstOrFail();
        $cheese = MenuExtra::create(['store_id' => $store->id, 'title' => 'Cheese', 'price' => 3.4, 'active' => 1]);
        $cheesePub = (string) Str::uuid7();
        DB::table('modifiers')->where('id', $cheese->id)->update(['public_id' => $cheesePub]);
        $tawook->attachedExtras()->attach($cheese->id, ['price_override' => '2.95', 'active' => 1, 'position' => 1]);
        $sauce = MenuExtra::create(['store_id' => $store->id, 'menu_id' => $tawook->id, 'title' => 'Chili', 'price' => 1.15, 'active' => 1, 'position' => 2]);
        $saucePub = (string) Str::uuid7();
        DB::table('modifiers')->where('id', $sauce->id)->update(['public_id' => $saucePub]);
        $grill = Menu::query()->where('store_id', $store->id)->where('public_id', ApiFixtures::GRILL)->firstOrFail();

        return ['store' => $store->fresh() ?? $store, 'ids' => [
            'water' => (int) $water->id, 'pills' => (int) $pills->id, 'juice' => (int) $juice->id, 'large' => (int) $large->id,
            'tawook' => (int) $tawook->id, 'grill' => (int) $grill->id, 'cheese' => (int) $cheese->id, 'sauce' => (int) $sauce->id,
        ], 'pub' => [
            'water' => (string) Menu::query()->whereKey($water->id)->value('public_id'), 'pills' => (string) Menu::query()->whereKey($pills->id)->value('public_id'),
            'juice' => (string) Menu::query()->whereKey($juice->id)->value('public_id'), 'cheese' => $cheesePub, 'sauce' => $saucePub,
        ]];
    }

    private function storefront(bool $inclusive, bool $feesTaxable): Order
    {
        ['store' => $store, 'pub' => $pub] = $this->catalogue($inclusive, $feesTaxable);
        $body = ['order_type' => 'delivery', 'address' => ['country' => 'SA', 'line1' => 'King Fahd Road 1', 'city' => 'Riyadh'], 'coupon' => 'WELCOME10', 'lines' => [
            ['line_id' => 'a', 'item_id' => ApiFixtures::GRILL, 'qty' => 2, 'option_ids' => [ApiFixtures::OPT_FOR_ONE, ApiFixtures::OPT_GARLIC]],
            ['line_id' => 'b', 'item_id' => ApiFixtures::TAWOOK, 'qty' => 3, 'option_ids' => [ApiFixtures::OPT_RICE, $pub['cheese'], $pub['sauce']]],
            ['line_id' => 'c', 'item_id' => $pub['water'], 'qty' => 7],
            ['line_id' => 'd', 'item_id' => $pub['pills'], 'qty' => 1],
            ['line_id' => 'e', 'item_id' => $pub['juice'], 'qty' => 2, 'variant_id' => 'JUICE-L'],
            ['line_id' => 'f', 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1],
        ]];
        $q = $this->postJson('/api/v1/storefront/quote', $body, ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001'])->assertOk();
        $r = $this->postJson('/api/v1/storefront/orders', $body + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later', 'method_id' => 'cash']], [
            'X-Storefront-Token' => 'sfx_demo_saffron_write_0001', 'Origin' => 'https://saffron-house.example', 'Idempotency-Key' => (string) Str::uuid7(),
        ])->assertCreated();

        return Order::query()->where('store_id', $store->id)->where('track_token', (string) $r->json('order.track_token'))->firstOrFail();
    }

    private function pos(bool $inclusive, bool $feesTaxable): Order
    {
        ['store' => $store, 'ids' => $id] = $this->catalogue($inclusive, $feesTaxable);
        $staff = StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'Till', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $groups = Menu::query()->findOrFail($id['grill'])->resolved_variant_options->keyBy('title');
        $rice = Menu::query()->findOrFail($id['tawook'])->resolved_variant_options->first();

        return PosService::createOrder($store, $staff, ['type' => 'delivery', 'address' => 'King Fahd Road 1, Riyadh', 'coupon_code' => 'WELCOME10', 'items' => [
            ['item_id' => $id['grill'], 'quantity' => 2, 'options' => [['group_id' => $groups['Size']->id, 'selected' => ['For one']], ['group_id' => $groups['Sauce']->id, 'selected' => ['Garlic sauce']]]],
            ['item_id' => $id['tawook'], 'quantity' => 3, 'extras' => [$id['cheese'], $id['sauce']], 'options' => [['group_id' => $rice->id, 'selected' => ['White rice']]]],
            ['item_id' => $id['water'], 'quantity' => 7],
            ['item_id' => $id['pills'], 'quantity' => 1],
            ['item_id' => $id['juice'], 'quantity' => 2, 'variation' => $id['large']],
            ['item_id' => $id['tawook'], 'quantity' => 1],
            ['item_id' => $id['water'], 'quantity' => 4],
        ]]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Order $order): array
    {
        $order = $order->fresh() ?? $order;
        $store = Store::query()->findOrFail($order->store_id);
        $cols = [];
        foreach ($order->getAttributes() as $k => $v) {
            if (str_ends_with($k, '_minor') || in_array($k, ['currency_code', 'type', 'source', 'coupon_code', 'status', 'is_paid'], true)) {
                $cols[$k] = self::scalar($v);
            }
        }
        ksort($cols);
        $lines = [];
        foreach (OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get() as $item) {
            $row = [];
            foreach ($item->getAttributes() as $k => $v) {
                if (! in_array($k, ['id', 'order_id', 'item_id', 'variation', 'store_id', 'account_id', 'station_id', 'created_at', 'updated_at', 'fired_at', 'public_id'], true)) {
                    $row[$k] = match (true) {
                        $k === 'options' => self::optionGroups(json_decode((string) $v, true)),
                        str_ends_with($k, '_at') => null, // timestamps are not money; never let a clock drift the golden
                        default => self::scalar($v),
                    };
                }
            }
            ksort($row);
            $row['_menu'] = (string) Menu::withTrashed()->whereKey($item->item_id)->value('name');
            $row['_variant'] = $item->variation ? (string) MenuVariant::query()->whereKey($item->variation)->value('sku') : null;
            $extras = OrderItemExtra::query()->where('order_item_id', $item->id)->orderBy('id')->get()
                ->map(fn ($e) => [(string) MenuExtra::query()->whereKey($e->extra_id)->value('title'), (string) $e->getAttribute('price_minor'), (string) $e->getAttribute('currency_code')])->all();
            usort($extras, fn (array $a, array $b): int => self::canonical($a) <=> self::canonical($b));
            $row['_extras'] = $extras;
            $lines[] = $row;
        }
        $stock = Menu::query()->where('store_id', $store->id)->whereIn('name', ['Water', 'Juice'])->orderBy('name')->pluck('stock_qty', 'name')->map(fn ($v) => $v === null ? null : (int) $v)->all();
        $stock['JUICE-L'] = (int) MenuVariant::query()->where('sku', 'JUICE-L')->whereIn('menu_id', Menu::query()->where('store_id', $store->id)->select('id'))->value('stock_qty');
        $money = array_map(fn ($v) => is_float($v) ? round($v, 6) : $v, $order->computeMoney());
        $tax = self::strip(TaxEngine::snapshot($order));
        $fiscal = FiscalInvoiceMath::compute(OrderFiscalContext::for($store, $order), 2, dropOutOfScope: true);
        $journal = OrderJournalPoster::postOrderPaid($order);
        $jl = $journal === null ? null : $journal->load('lines.account')->lines
            ->map(fn ($l) => [(string) $l->account->code, round((float) ($l->debit ?? 0), 3), round((float) ($l->credit ?? 0), 3)])
            ->sort()->values()->all();

        return ['order' => $cols, 'tax_lines' => self::strip(json_decode((string) $order->getAttribute('tax_lines'), true)), 'lines' => $lines,
            'stock' => $stock, 'money' => $money, 'tax' => $tax, 'fiscal' => self::strip($fiscal), 'journal' => $jl, 'amount_due' => (string) $order->amount_due];
    }

    /** One spelling per value on every database (pgsql booleans, mysql/pgsql decimal padding). */
    private static function scalar(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $v = (string) $v;
        if ($v === '') {
            return '0';
        }

        return is_numeric($v) && str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    /**
     * The choice-group snapshot is written in `resolved_variant_options` order, whose SQL only orders by pivot
     * position — every group here has position 0, so PostgreSQL may return them in any order. Sort groups by title
     * and each group's selected options by label so the result is stable.
     */
    private static function optionGroups(mixed $options): mixed
    {
        if (! is_array($options)) {
            return $options;
        }
        $groups = [];
        foreach ($options as $group) {
            if (is_array($group) && isset($group['selected']) && is_array($group['selected'])) {
                usort($group['selected'], fn (array $a, array $b): int => self::canonical($a) <=> self::canonical($b));
            }
            $groups[] = $group;
        }
        usort($groups, fn (array $a, array $b): int => [(string) ($a['title'] ?? ''), self::canonical($a)] <=> [(string) ($b['title'] ?? ''), self::canonical($b)]);

        return self::strip($groups);
    }

    /** A stable string for sorting and comparing a value: ids removed and map keys sorted (jsonb reorders keys). */
    private static function canonical(mixed $v): string
    {
        return (string) json_encode(self::sorted(self::strip($v)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Drop ids (they differ per database) and keep everything else. */
    private static function strip(mixed $v): mixed
    {
        if (! is_array($v)) {
            return is_float($v) ? round($v, 6) : $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (in_array($k, ['id', 'ref', 'group_id', 'extra_id', 'modifier_id', 'item_id', 'menu_id', 'order_id', 'order_item_id', 'variant_id'], true)) {
                continue;
            }
            $out[$k] = self::strip($x);
        }

        return $out;
    }

    /** Maps compared by key (the fixture file is written with sorted keys); lists keep their order. */
    private static function sorted(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::sorted(...), $v);
        if (! array_is_list($v)) {
            ksort($v);
        }

        return $v;
    }

    private function check(string $name, Order $order): void
    {
        $snap = json_decode((string) json_encode($this->snapshot($order)), true);
        if (getenv('GOLDEN_WRITE')) {
            fwrite(STDERR, "\nGOLDEN ".json_encode([$name => $snap])."\n");
            $this->assertNotEmpty($snap['lines']);

            return;
        }
        $golden = json_decode((string) file_get_contents(self::FILE), true);
        $this->assertIsArray($golden[$name] ?? null, "no golden for $name");
        $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $this->assertSame(
            (string) json_encode(self::sorted($golden[$name]), $json),
            (string) json_encode(self::sorted($snap), $json),
            "$name differs from the golden recorded before P6-148 (expected vs actual above)"
        );
    }

    public function test_storefront_checkout_exclusive_vat(): void
    {
        $this->check('storefront_exclusive', $this->storefront(false, true));
    }

    public function test_storefront_checkout_inclusive_vat(): void
    {
        $this->check('storefront_inclusive', $this->storefront(true, false));
    }

    public function test_pos_create_exclusive_vat(): void
    {
        $this->check('pos_exclusive', $this->pos(false, false));
    }

    public function test_pos_create_inclusive_vat(): void
    {
        $this->check('pos_inclusive', $this->pos(true, true));
    }
}
