<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Services\Accounting\QuickBooksProvider;
use Juwri\Modules\Accounting\Services\Accounting\XeroProvider;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Bug 6 (2026-10-01): the Xero and QuickBooks invoice payloads rounded every amount to 2 decimals, whatever the
 * currency. They now round to the order currency's decimals (the order-money rule of slice 10a), within each
 * provider's own cap:
 *  - Xero: UnitAmount takes up to 4 decimals when the request carries `?unitdp=4` (Xero API docs); Xero itself
 *    computes LineAmount / totals at 2 decimals — its cap, which juwri cannot change. So: endpoint gains unitdp=4,
 *    UnitAmount = currency decimals capped at 4.
 *  - QuickBooks: the driver posts juwri's generic invoice shape to the connection's base_url (no provider-side
 *    decimal cap in that contract; QBO's own Amount type is decimal "10.5"), so line amounts and the total go out
 *    at the currency's decimals.
 * The payload currency is the ORDER's currency (a fact of the sale), not the store's current one.
 */
class AccountingExportRoundingCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.currency_code' => 'USD']);
    }

    private function order(string $currency, float $price, int $qty): Order
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'p-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Tea', 'price' => $price, 'active' => 1]);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty]);
        $order->unsetRelation('items');
        $order->snapshotTotals();

        return $order->fresh('items.menu');
    }

    /**
     * @return array<string, array{string, float, int, float, float, float}>
     */
    public static function cases(): array
    {
        // currency, menu price, qty, Xero UnitAmount, QuickBooks line amount, QuickBooks total
        return [
            // the menu price is stored at the store's 2 decimals (10.125 → 10.13)
            'USD (unchanged)' => ['USD', 10.125, 3, 10.13, 30.39, 30.39],
            // before: 1.24 / 3.71 / 3.71
            'KWD' => ['KWD', 1.235, 3, 1.235, 3.705, 3.705],
            'JPY (unchanged)' => ['JPY', 1500.0, 3, 1500.0, 4500.0, 4500.0],
        ];
    }

    #[DataProvider('cases')]
    public function test_xero_and_quickbooks_payload_amounts(string $cur, float $price, int $qty, float $unit, float $line, float $total): void
    {
        $order = $this->order($cur, $price, $qty);

        $xero = (new XeroProvider)->buildPayload($order);
        $this->assertSame($cur, $xero['CurrencyCode']);
        $this->assertSame($unit, $xero['LineItems'][0]['UnitAmount'], $cur.' Xero UnitAmount');
        $this->assertSame($qty, $xero['LineItems'][0]['Quantity']);

        $qb = (new QuickBooksProvider)->buildPayload($order);
        $this->assertSame($cur, $qb['currency']);
        $this->assertSame($line, $qb['lines'][0]['amount'], $cur.' QuickBooks line amount');
        $this->assertSame($total, $qb['total'], $cur.' QuickBooks total');
    }

    public function test_xero_endpoint_opts_into_four_decimal_unit_amounts(): void
    {
        // before: https://api.xero.com/api.xro/2.0/Invoices
        $this->assertSame(
            'https://api.xero.com/api.xro/2.0/Invoices?unitdp=4',
            (new XeroProvider)->endpoint(['base_url' => 'https://api.xero.com/api.xro/2.0/'])
        );
    }

    public function test_payload_currency_is_the_orders_not_the_stores_current_one(): void
    {
        $order = $this->order('KWD', 1.235, 1);
        // The store later switches currency; the order stays a KWD sale.
        StoreOption::updateStoreOption($order->store_id, 'currency_code', 'USD');
        $order = $order->fresh('items.menu');

        // before: 'USD' / 1.24 — the store's current currency, at 2 decimals (the amounts were KWD all along)
        $this->assertSame('KWD', (new XeroProvider)->buildPayload($order)['CurrencyCode']);
        $this->assertSame('KWD', (new QuickBooksProvider)->buildPayload($order)['currency']);
        $this->assertSame(1.235, (new QuickBooksProvider)->buildPayload($order)['total']);
    }
}
