<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomTokenKeys;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J52b review N7: a printed room QR minted under the OLD app key, through the real HTTP flow: scan (the page stashes
 * `rt` in the session) → room-service order with charge-to-room on a store that requires the room token. Allowed
 * during the J56 grace period, refused after it, refused for another room. Fake old key only.
 */
class LegacyRoomChargeFlowTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_KEY = 'base64:c3ludGhldGljLW9sZC1rZXktZmxvdw==';

    private const GUEST = 'Sam Carter';

    private const PRICE = 42.00;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        config([
            'legacy_compat.enabled' => true,
            'legacy_compat.room_token_legacy_keys' => [Crypt::encryptString(self::OLD_KEY)],
            'legacy_compat.room_token_legacy_until' => now()->addMonths(6)->toDateTimeString(),
        ]);
        LegacyCompatServiceProvider::activate($this->app);
    }

    /** @return array{Store, StoreTable, Menu} */
    private function hotel(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1', 'allow_ordering' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'h-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Hotel',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club Sandwich', 'price' => self::PRICE, 'active' => '1']);
        /** @var StoreTable $room */
        $room = $store->tables()->create([
            'table_no' => 101, 'key' => 'rk-'.Str::random(10), 'seats' => 2, 'area_type' => 'room', 'label' => 'Suite 101',
        ]);

        return [$store, $room, $item];
    }

    /** @return TestResponse<Response> */
    private function order(Store $store, StoreTable $room, Menu $item): TestResponse
    {
        return $this->postJson(route(LegacyCompatRoutes::CORE_ORDER_ROUTE, [LegacyCompatRoutes::CORE_ORDER_ROUTE_PARAM => $store->id]), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => '', 'extras' => []]]),
            'name' => self::GUEST,
            'ordering-type' => 'room_service',
            'room' => $room->getAttribute('key'),
            'table' => $room->getAttribute('key'),
            'tender' => 'charge_to_room',
            'pay_via' => 'pay_on_delivery',
        ]);
    }

    private function folioTotal(int $stayId): float
    {
        $account = LedgerAccount::where('owner_type', 'hotel_stay')->where('owner_id', $stayId)->where('kind', 'hotel_folio')->first();

        return $account ? round((float) LedgerEntry::where('ledger_account_id', $account->id)->sum(DB::raw(LedgerEntry::minorMoneySql('amount'))), 2) : 0.0;
    }

    private function scan(Store $store, StoreTable $room, string $token): void
    {
        $this->get('/'.$store->slug.'?table='.$room->getAttribute('key').'&rt='.$token)->assertOk()->assertSessionHas('hotel_room_token', $token);
    }

    public function test_an_old_key_room_qr_charges_the_room_during_the_grace_period(): void
    {
        [$store, $room, $item] = $this->hotel();
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->scan($store, $room, LegacyRoomTokenKeys::sign((int) $store->id, (int) $room->getKey(), self::OLD_KEY));
        $this->order($store, $room, $item)->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(self::PRICE, $this->folioTotal((int) $stay->getKey()), 0.001);
    }

    public function test_after_the_grace_period_the_old_key_room_qr_cannot_charge(): void
    {
        [$store, $room, $item] = $this->hotel();
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        config(['legacy_compat.room_token_legacy_until' => now()->subDay()->toDateTimeString()]);

        $this->scan($store, $room, LegacyRoomTokenKeys::sign((int) $store->id, (int) $room->getKey(), self::OLD_KEY));
        $this->order($store, $room, $item)->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->folioTotal((int) $stay->getKey()), 0.001);
    }

    public function test_an_old_key_token_for_another_room_cannot_charge(): void
    {
        [$store, $room, $item] = $this->hotel();
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->scan($store, $room, LegacyRoomTokenKeys::sign((int) $store->id, (int) $room->getKey() + 1, self::OLD_KEY));
        $this->order($store, $room, $item)->assertOk()->assertJson(['success' => false]);

        $this->assertEqualsWithDelta(0.0, $this->folioTotal((int) $stay->getKey()), 0.001);
    }
}
