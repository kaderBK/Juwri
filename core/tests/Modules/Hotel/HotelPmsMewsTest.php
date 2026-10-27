<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\Pms\GuestDto;
use Juwri\Modules\Hotel\Services\Hotel\Pms\MewsConnector;
use Juwri\Modules\Hotel\Services\Hotel\Pms\NullPmsConnector;
use Juwri\Modules\Hotel\Services\Hotel\Pms\PmsConnectorInterface;
use Juwri\Modules\Hotel\Services\Hotel\Pms\PmsManager;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A8 — the Mews Connector API PMS driver, tested exclusively with Http::fake
 * fixtures (NO live HTTP). Covers: resolution via a feature='pms' Credential row,
 * the happy-path charge payload, HTTP-error tolerance (the local folio must still
 * land), the missing-credential and provider='manual' fallbacks to the Null
 * driver, and that chargeOrder dispatches to the resolved driver.
 */
class HotelPmsMewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SsrfGuard::$resolver = static fn (string $h): array => ['93.184.216.34']; // P6-68: test hosts resolve public
        app()->setLocale('en');
        PmsManager::flush();
    }

    protected function tearDown(): void
    {
        PmsManager::flush(); // static driver registry must not leak across tests
        parent::tearDown();
    }

    private function storeFixture(string $currency = 'USD'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'hotel' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '1');
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    private function room(Store $store): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => 101, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Suite 101',
        ]);
    }

    private function order(Store $store, StoreTable $room, float $price): Order
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => '1']);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => $room->label,
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'unit_price' => $price]);

        return $order->fresh()->load('items.menu');
    }

    private function mewsCredential(Store $store): void
    {
        Credential::create([
            'store_id' => $store->id,
            'feature' => 'pms',
            'provider' => 'mews',
            'secrets' => [
                'base_url' => 'https://mews.example',
                'client_token' => 'CLIENT-TOK',
                'access_token' => 'ACCESS-TOK',
                'service_id' => 'SERVICE-XYZ',
            ],
            'active' => true,
        ]);
    }

    private function folioTotal(int $stayId): float
    {
        $account = LedgerAccount::where('owner_type', 'hotel_stay')->where('owner_id', $stayId)
            ->where('kind', 'hotel_folio')->first();

        return $account ? round((float) LedgerEntry::where('ledger_account_id', $account->id)->sum(DB::raw(LedgerEntry::minorMoneySql('amount'))), 3) : 0.0;
    }

    // ------------------------------------------------------------------

    public function test_resolves_the_mews_driver_from_a_credential_row(): void
    {
        $store = $this->storeFixture();
        $this->mewsCredential($store);

        $driver = PmsManager::for($store->id);
        $this->assertInstanceOf(MewsConnector::class, $driver);
        $this->assertSame('mews', $driver->key());
        $this->assertTrue(PmsManager::configured($store->id));
    }

    public function test_missing_credential_falls_back_to_the_null_driver(): void
    {
        $store = $this->storeFixture();

        $this->assertInstanceOf(NullPmsConnector::class, PmsManager::for($store->id));
        $this->assertFalse(PmsManager::configured($store->id));
    }

    public function test_provider_manual_resolves_the_null_driver(): void
    {
        $store = $this->storeFixture();
        Credential::create([
            'store_id' => $store->id, 'feature' => 'pms', 'provider' => 'manual',
            'secrets' => ['note' => 'staff enter guests'], 'active' => true,
        ]);

        // PROVIDERS lists 'manual' but no driver registers under it → Null fallback.
        $this->assertInstanceOf(NullPmsConnector::class, PmsManager::for($store->id));
    }

    public function test_post_charge_sends_the_mews_orders_add_payload(): void
    {
        Http::fake(['*' => Http::response(['Orders' => [['Id' => 'mews-1']]], 200)]);

        $store = $this->storeFixture('USD');
        $this->mewsCredential($store);
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $order = $this->order($store, $room, 42.00);

        new MewsConnector()->postCharge($stay, $order);

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return str_ends_with($request->url(), '/api/connector/v1/orders/add')
                && ($body['ClientToken'] ?? null) === 'CLIENT-TOK'
                && ($body['AccessToken'] ?? null) === 'ACCESS-TOK'
                && ($body['ServiceId'] ?? null) === 'SERVICE-XYZ'
                && ($body['Orders'][0]['Identifier'] ?? null) === 'order-'.$order->id
                && ($body['Orders'][0]['Items'][0]['UnitAmount']['Currency'] ?? null) === 'USD'
                && abs((float) ($body['Orders'][0]['Items'][0]['UnitAmount']['GrossValue'] ?? 0) - 42.00) < 0.001;
        });
    }

    public function test_http_error_is_tolerated_and_never_throws(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $store = $this->storeFixture();
        $this->mewsCredential($store);
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $order = $this->order($store, $room, 25.00);

        // Must not throw on a 500.
        new MewsConnector()->postCharge($stay, $order);
        $this->assertTrue(true);
    }

    public function test_charge_order_still_lands_the_local_folio_when_mews_errors(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $store = $this->storeFixture();
        $this->mewsCredential($store); // provider=mews → chargeOrder mirrors via MewsConnector
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $order = $this->order($store, $room, 30.00);

        HotelFolioService::chargeOrder($order, $stay);

        // The local folio is authoritative and must be charged despite the PMS outage.
        $this->assertEqualsWithDelta(30.00, $this->folioTotal($stay->id), 0.001);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/connector/v1/orders/add'));
    }

    public function test_charge_order_tolerates_a_throwing_driver(): void
    {
        // A fake driver that throws — chargeOrder's guard must keep the folio charge.
        PmsManager::register(new class implements PmsConnectorInterface
        {
            public function key(): string
            {
                return 'mews';
            }

            public function label(): string
            {
                return 'Throwing';
            }

            public function validateGuest(string $room, string $name): ?GuestDto
            {
                return null;
            }

            public function postCharge(HotelStay $stay, Order $order): void
            {
                throw new \RuntimeException('PMS down');
            }

            public function fetchStays(): iterable
            {
                return [];
            }
        });

        $store = $this->storeFixture();
        $this->mewsCredential($store);
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $order = $this->order($store, $room, 18.00);

        HotelFolioService::chargeOrder($order, $stay);
        $this->assertEqualsWithDelta(18.00, $this->folioTotal($stay->id), 0.001);
    }

    public function test_post_charge_refuses_a_non_public_base_url(): void
    {
        // P6-68: guest data + PMS tokens must never be POSTed to an internal address.
        SsrfGuard::$resolver = static fn (string $h): array => $h === 'internal.example' ? ['10.0.0.5'] : ['93.184.216.34'];
        Http::fake(['*' => Http::response(['Orders' => []], 200)]);
        $store = $this->storeFixture('USD');
        $this->mewsCredential($store);
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $order = $this->order($store, $room, 10.00);
        $cred = Credential::where('store_id', $store->id)->where('feature', 'pms')->first();

        foreach (['https://127.0.0.1', 'https://169.254.169.254', 'https://internal.example', 'https://[::1]', 'https://[fc00::1]', 'http://mews.example'] as $url) {
            $cred->secrets = array_merge($cred->secrets, ['base_url' => $url]);
            $cred->save();
            new MewsConnector()->postCharge($stay, $order);
        }
        Http::assertNothingSent();

        $cred->secrets = array_merge($cred->secrets, ['base_url' => 'https://api.mews.example']);
        $cred->save();
        new MewsConnector()->postCharge($stay, $order);
        Http::assertSentCount(1);
    }
}
