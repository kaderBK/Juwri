<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Menu\Jobs\SendStockAlertsJob;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\SizeGuide;
use Juwri\Modules\Menu\Models\StockAlert;
use Juwri\Modules\Menu\Models\WishlistItem;
use Tests\Support\StorefrontApi\ApiContract;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-08 / P3-06b phase B — the retail API beyond the replayed examples: variant stock states (API-6), catalogue
 * filters / sort / cursor, shipping in quote and order, strict stock for headless orders, and juwri's additive retail
 * endpoints (wishlist + sign-in sync, back-in-stock, size guides, returns) — each scoped to the token's store.
 *
 * Regressions of bugs found while building it: headless (`storefront_api`) orders were not a guest source (stock not
 * refused), item detail leaked exact stock counts, a variant cell's axis options were charged again on top of the
 * cell price, and a ship / delivery order was accepted without an address.
 */
class StorefrontRetailApiTest extends TestCase
{
    use RefreshDatabase;

    private const READ = 'sfx_demo_dune_read_0001';

    private const WRITE = 'sfx_demo_dune_write_0001';

    private const ORIGIN = 'https://dune-and-co.example';

    private const GUEST = 'wl_guest_token_0123456789abcdefghijklmnop';

    private ApiFixtures $fx;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 06:44:50', 'UTC'));
        $this->fx = new ApiFixtures;
        $this->store = $this->fx->duneRetail();
    }

    /** @return array<string, string> */
    private function write(array $extra = []): array
    {
        return array_merge(['X-Storefront-Token' => self::WRITE, 'Origin' => self::ORIGIN, 'Idempotency-Key' => (string) Str::uuid7()], $extra);
    }

    /** @return array<string, mixed> */
    private function shipCart(int $qty = 1, bool $address = true): array
    {
        return array_filter([
            'order_type' => 'ship',
            'lines' => [['line_id' => 'ln_1', 'item_id' => ApiFixtures::SHIRT, 'variant_id' => ApiFixtures::SHIRT_SAND_M, 'qty' => $qty]],
            'address' => $address ? ['line1' => 'King Fahd Rd 12', 'city' => 'Riyadh', 'postal_code' => '12611', 'country' => 'SA'] : null,
            'shipping_method_id' => ApiFixtures::SHIP_STANDARD,
        ]);
    }

    private function quote(array $body)
    {
        $r = $this->postJson('/api/v1/storefront/quote', $body, ['X-Storefront-Token' => self::READ]);
        ApiContract::assertResponse('quoteCart', 'POST', '/api/v1/storefront/quote', $r);

        return $r;
    }

    private function otherStore(): Store
    {
        $b = $this->fx->store($this->fx->owner(), 'other-'.Str::lower(Str::random(5)), 'Other Shop', 'retail');
        $cat = MenuCategory::create(['store_id' => $b->id, 'name' => 'B', 'active' => 1]);
        $this->fx->item($b, $cat, '019fffff-0000-7000-8000-00000000c001', 'B only shirt', 50);
        $this->fx->token($b, 'sfx_b_read', ['menu:read']);
        $this->fx->token($b, 'sfx_b_write', ['order:write'], ['https://b.example']);

        return $b;
    }

    public function test_item_detail_shows_an_exact_stock_count_only_when_low(): void
    {
        $r = $this->getJson('/api/v1/storefront/items/'.ApiFixtures::SHIRT, ['X-Storefront-Token' => self::READ])->assertOk();
        $json = json_encode($r->json());
        $this->assertStringNotContainsString('"qty":20', (string) $json, 'API-6: an in-stock cell never shows its count');
        $this->assertStringNotContainsString('"qty":30', (string) $json);
        $variants = $this->getJson('/api/v1/storefront/items/'.ApiFixtures::SHIRT.'/variants', ['X-Storefront-Token' => self::READ])->assertOk();
        $this->assertSame([null, null, 2, null, null], array_column(array_column($variants->json('variants'), 'stock'), 'qty'));
    }

    public function test_a_token_never_reads_another_stores_variants_size_guide_or_returns(): void
    {
        $this->otherStore();
        $this->getJson('/api/v1/storefront/items/'.ApiFixtures::SHIRT.'/variants', ['X-Storefront-Token' => 'sfx_b_read'])->assertNotFound();
        $guide = SizeGuide::create(['store_id' => $this->store->id, 'title' => 'Shirts', 'unit' => 'cm', 'columns' => ['Size', 'Chest'], 'rows' => [['M', '100']], 'active' => true]);
        $this->getJson('/api/v1/storefront/size-guides/'.$guide->public_id, ['X-Storefront-Token' => self::READ])->assertOk()->assertJsonPath('rows.0.1', '100');
        $this->getJson('/api/v1/storefront/size-guides/'.$guide->public_id, ['X-Storefront-Token' => 'sfx_b_read'])->assertNotFound();
        $order = Order::create(['store_id' => $this->store->id, 'type' => 'ship', 'status' => 'new', 'track_token' => 'trk_dune_returns_0001']);
        $this->getJson('/api/v1/storefront/orders/trk_dune_returns_0001/returns', ['X-Storefront-Token' => self::READ])->assertOk()->assertJsonPath('offered', false);
        $this->getJson('/api/v1/storefront/orders/trk_dune_returns_0001/returns', ['X-Storefront-Token' => 'sfx_b_read'])->assertNotFound();
        $this->assertNotNull($order);
    }

    public function test_axis_options_of_a_variant_cell_are_not_charged_on_top_of_the_cell_price(): void
    {
        // the Sand option carries a price of its own (an add-on elsewhere); the cell's price already includes it
        Modifier::query()->where('public_id', '019f23cd-4a14-7b7b-a7c0-91d71e6a5348')->update(['price_minor' => 1000]);
        $body = ['order_type' => 'pickup', 'lines' => [[
            'line_id' => 'ln_1', 'item_id' => ApiFixtures::SHIRT, 'variant_id' => ApiFixtures::SHIRT_SAND_M, 'qty' => 1,
            'option_ids' => ['019f23cd-4a14-7b7b-a7c0-91d71e6a5348', '019f6628-fcc8-7266-b5e7-8ac988ca5a9d'],
        ]]];
        $q = $this->quote(['order_type' => 'ship'] + $this->shipCart())->assertOk();
        $this->assertSame(13900, $q->json('quote.rows.0.amount.minor'));
        $withAxes = $this->quote(array_merge($this->shipCart(), ['lines' => $body['lines']]))->assertOk();
        $this->assertSame(13900, $withAxes->json('quote.rows.0.amount.minor'), 'the cell price, not cell + option');
    }

    public function test_option_picks_covering_every_axis_resolve_the_cell(): void
    {
        $line = fn (string $size) => array_merge($this->shipCart(), ['lines' => [[
            'line_id' => 'ln_1', 'item_id' => ApiFixtures::SHIRT, 'qty' => 1,
            'option_ids' => ['019f23cd-4a14-7b7b-a7c0-91d71e6a5348', $size],
        ]]]);
        // Sand · L: the cell's price
        $this->assertSame(13900, $this->quote($line('019f08c5-dabe-7e68-9fcb-a31ab7644014'))->assertOk()->json('quote.rows.0.amount.minor'));
        // Sand · XS is sold out: refused with the line id, not priced as the bare item
        $this->quote($line('019f8489-f7d7-7f7e-b550-2536c06e64ad'))->assertStatus(422)
            ->assertJsonPath('code', 'out_of_stock')->assertJsonPath('details.line_ids', ['ln_1']);
    }

    public function test_a_ship_order_needs_an_address_and_a_method_offered_there(): void
    {
        $q = $this->quote($this->shipCart(1, false))->assertOk();
        $this->assertContains('address', $q->json('requires'));
        $this->postJson('/api/v1/storefront/orders', $this->shipCart(1, false) + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later', 'method_id' => 'cod']], $this->write())
            ->assertStatus(422);
        $outside = array_merge($this->shipCart(), ['address' => ['line1' => 'Sheikh Zayed Rd', 'city' => 'Dubai', 'country' => 'AE']]);
        $q2 = $this->quote($outside)->assertOk();
        $this->assertContains('out_of_zone', array_column($q2->json('warnings'), 'code'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_headless_order_cannot_take_more_than_the_stock(): void
    {
        $this->quote($this->shipCart(3))->assertStatus(422)->assertJsonPath('code', 'out_of_stock');
        // the quote was fine, then the stock sold elsewhere: the order is refused, nothing is taken
        $q = $this->quote($this->shipCart(2))->assertOk();
        MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->update(['stock_qty' => 1]);
        $r = $this->postJson('/api/v1/storefront/orders', $this->shipCart(2) + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later', 'method_id' => 'cod']], $this->write());
        $r->assertStatus(422);
        MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->update(['stock_qty' => 2]);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(2, (int) MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->value('stock_qty'));
    }

    public function test_a_ship_order_carries_the_shipping_fee_and_window(): void
    {
        $q = $this->quote($this->shipCart())->assertOk();
        $this->assertSame(15400, $q->json('quote.total.minor'), 'SAR 139 + standard SAR 15 (under the SAR 200 threshold)');
        $r = $this->postJson('/api/v1/storefront/orders', $this->shipCart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later', 'method_id' => 'cod']], $this->write())
            ->assertCreated();
        $order = Order::query()->where('public_id', $r->json('order.public_id'))->firstOrFail();
        $this->assertSame(15400, (int) $order->grand_total_minor);
        $this->assertSame('2026-10-04', $order->shipping_eta_from?->toDateString());
        $this->assertSame(1, (int) MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->value('stock_qty'));
    }

    public function test_catalog_filters_sort_and_cursor(): void
    {
        $read = ['X-Storefront-Token' => self::READ];
        $all = $this->getJson('/api/v1/storefront/catalog?sort=price_desc', $read)->assertOk();
        $this->assertSame([ApiFixtures::TROUSERS, ApiFixtures::SHIRT], array_keys($all->json('catalog.items')));

        $cheap = $this->getJson('/api/v1/storefront/catalog?price_max=15000', $read)->assertOk();
        $this->assertSame([ApiFixtures::SHIRT], array_keys($cheap->json('catalog.items')));

        $first = $this->getJson('/api/v1/storefront/catalog?sort=price_asc&per_page=1', $read)->assertOk();
        $this->assertSame([ApiFixtures::SHIRT], array_keys($first->json('catalog.items')));
        $this->assertSame(2, $first->json('catalog.total'));
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);
        $second = $this->getJson('/api/v1/storefront/catalog?sort=price_asc&per_page=1&cursor='.urlencode($cursor), $read)->assertOk();
        $this->assertSame([ApiFixtures::TROUSERS], array_keys($second->json('catalog.items')));
        $this->assertNull($second->json('next_cursor'));

        // a cursor of another query is refused
        $this->getJson('/api/v1/storefront/catalog?sort=price_desc&per_page=1&cursor='.urlencode($cursor), $read)->assertStatus(422);
        $this->getJson('/api/v1/storefront/catalog?sort=cheapest', $read)->assertStatus(422);
    }

    public function test_guest_wishlist_by_token_and_sync_into_the_customer_list_at_sign_in(): void
    {
        $guest = $this->write(['X-Wishlist-Token' => self::GUEST]);
        $this->postJson('/api/v1/storefront/wishlist', ['item_id' => ApiFixtures::SHIRT], $guest)->assertCreated()
            ->assertJsonPath('items.0.item_id', ApiFixtures::SHIRT);
        $this->postJson('/api/v1/storefront/wishlist', ['item_id' => ApiFixtures::SHIRT], $guest)->assertCreated();
        $this->assertSame(1, WishlistItem::query()->count(), 'saving twice keeps one row');
        $this->postJson('/api/v1/storefront/wishlist', ['item_id' => ApiFixtures::SHIRT], $this->write())->assertStatus(422);
        $this->getJson('/api/v1/storefront/wishlist', ['X-Storefront-Token' => self::READ, 'X-Wishlist-Token' => self::GUEST])->assertOk()
            ->assertJsonCount(1, 'items');

        $customer = Customer::query()->create(['store_id' => $this->store->id, 'name' => 'Noura', 'phone' => '+966551234567']);
        $raw = CustomerToken::issue($customer, 'test');
        $synced = $this->postJson('/api/v1/storefront/wishlist/sync', ['item_ids' => [ApiFixtures::TROUSERS]], $guest + ['Authorization' => 'Bearer '.$raw])->assertOk();
        $this->assertEqualsCanonicalizing([ApiFixtures::SHIRT, ApiFixtures::TROUSERS], array_column($synced->json('items'), 'item_id'));
        $this->assertSame(0, WishlistItem::query()->whereNotNull('guest_key')->count(), 'the guest rows joined the customer list');

        $this->deleteJson('/api/v1/storefront/wishlist/'.ApiFixtures::SHIRT, [], $this->write(['Authorization' => 'Bearer '.$raw]))->assertOk()
            ->assertJsonCount(1, 'items');
        $this->postJson('/api/v1/storefront/wishlist/sync', [], $this->write())->assertUnauthorized();
    }

    public function test_a_wishlist_never_holds_another_stores_item(): void
    {
        $this->otherStore();
        $this->postJson('/api/v1/storefront/wishlist', ['item_id' => '019fffff-0000-7000-8000-00000000c001'], $this->write(['X-Wishlist-Token' => self::GUEST]))
            ->assertNotFound();
        // the same guest token at another store is another list
        $this->postJson('/api/v1/storefront/wishlist', ['item_id' => ApiFixtures::SHIRT], $this->write(['X-Wishlist-Token' => self::GUEST]))->assertCreated();
        $this->getJson('/api/v1/storefront/wishlist', ['X-Storefront-Token' => 'sfx_b_read', 'X-Wishlist-Token' => self::GUEST])->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_back_in_stock_needs_consent_and_a_sold_out_cell_and_goes_out_on_restock(): void
    {
        Bus::fake([SendStockAlertsJob::class]);
        $xs = '019e8a90-b580-7d21-8841-c8ec7cc5c1e6';
        $body = ['item_id' => ApiFixtures::SHIRT, 'variant_id' => $xs, 'channel' => 'email', 'contact' => 'noura@example.com'];

        $this->postJson('/api/v1/storefront/stock-alerts', $body + ['marketing_opt_in' => false], $this->write())
            ->assertStatus(422)->assertJsonPath('code', 'consent_required');
        $this->postJson('/api/v1/storefront/stock-alerts', array_merge($body, ['variant_id' => ApiFixtures::SHIRT_SAND_M, 'marketing_opt_in' => true]), $this->write())
            ->assertStatus(422)->assertJsonPath('code', 'in_stock');
        $this->postJson('/api/v1/storefront/stock-alerts', array_merge($body, ['variant_id' => ApiFixtures::TROUSERS_NAVY_M, 'marketing_opt_in' => true]), $this->write())
            ->assertNotFound();
        $this->postJson('/api/v1/storefront/stock-alerts', $body + ['marketing_opt_in' => true], $this->write())->assertCreated();
        $this->postJson('/api/v1/storefront/stock-alerts', $body + ['marketing_opt_in' => true], $this->write())->assertCreated();
        $this->assertSame(1, StockAlert::query()->count(), 'one request per contact and cell');
        $this->assertNotSame('noura@example.com', DB::table('stock_alerts')->value('contact'), 'the contact is stored encrypted');

        MenuVariant::restoreStock((int) MenuVariant::query()->where('public_id', $xs)->value('id'), 3);
        Bus::assertDispatched(SendStockAlertsJob::class);
    }

    public function test_back_in_stock_requests_can_be_switched_off(): void
    {
        StoreSettings::set((int) $this->store->id, 'back_in_stock_alerts', false);
        $this->postJson('/api/v1/storefront/stock-alerts', [
            'item_id' => ApiFixtures::SHIRT, 'variant_id' => '019e8a90-b580-7d21-8841-c8ec7cc5c1e6', 'channel' => 'email',
            'contact' => 'noura@example.com', 'marketing_opt_in' => true,
        ], $this->write())->assertStatus(422)->assertJsonPath('code', 'alerts_off');
        $this->assertSame(0, StockAlert::query()->count());
    }
}
