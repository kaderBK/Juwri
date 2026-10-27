<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelRoomToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Charge-to-room: the SERVER half of the contract.
 *
 * A hotel guest scans a room QR, ticks "Charge to my room" and orders. The charge
 * silently never reached the folio, because StoreController@submitOrder only resolves
 * the room, sets service_location and evaluates `tender` INSIDE the
 * `ordering-type == 'room_service'` branch — and no storefront theme ever offers or
 * sends `room_service`. Orders arrived as `on-table`, so the branch never ran and
 * `tender=charge_to_room` was ignored.
 *
 * The fix is client-side: resources/views/store_templates/_partials/order-field-forwarder.blade.php
 * now OVERRIDES `ordering-type` to `room_service` on the outgoing /send-order POST
 * whenever `tender === 'charge_to_room'`. That JS is not unit-testable here.
 *
 * What IS testable — and what this class locks down — is that the server honours the
 * payload the forwarder now produces, and (case 5) that the pre-fix payload shape
 * demonstrably does NOT reach the folio. If the server contract ever drifts, the
 * forwarder becomes a no-op again and the bug returns silently.
 *
 * Folio assertions read the ledger, resolved via owner_type/owner_id — NOT via
 * hotel_stays.folio_account_id, which is a misleading signal.
 */
class HotelChargeToRoomOrderTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST = 'Sam Carter';

    private const PRICE = 42.00;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // The order-placed notification renders a Blade mail template that is empty
        // in the test env; it is irrelevant to the folio contract under test.
        Mail::fake();
        Notification::fake();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /**
     * A hotel-entitled restaurant with hotel_mode on.
     *
     * Traps: the plan's settings must carry hotel as the STRING '1'
     * (Entitlements does a (string) $value === '1' comparison), and the
     * hotel_mode post-option must likewise be '1'.
     */
    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');

        return $store;
    }

    /** Rooms are store_tables rows with area_type='room'. */
    private function room(Store $store, int $no = 101): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Suite '.$no,
        ]);
    }

    /** One active menu item priced at self::PRICE. `active` must be the STRING '1'. */
    private function menuItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Club Sandwich', 'price' => self::PRICE, 'active' => '1',
        ]);
    }

    /** The payload the fixed forwarder produces; $overrides tweaks it per case. */
    private function payload(Menu $item, StoreTable $room, array $overrides = []): array
    {
        return array_merge([
            // Exactly the item shape the storefront cart serialises.
            'items' => json_encode([[
                'id' => $item->id, 'quantity' => 1, 'variants' => '', 'extras' => [],
            ]]),
            'name' => self::GUEST,
            'ordering-type' => 'room_service',
            'room' => $room->key,
            'table' => $room->key,
            'tender' => 'charge_to_room',
            'pay_via' => 'pay_on_delivery',
        ], $overrides);
    }

    private function send(Store $store, array $payload)
    {
        return $this->postJson(route('store.submitOrder', ['store' => $store->id]), $payload);
    }

    /**
     * Folio total for a stay, read straight off the ledger and resolved by
     * owner_type/owner_id — deliberately NOT via hotel_stays.folio_account_id,
     * which stays NULL in practice and is a misleading signal.
     */
    private function folioTotal(int $stayId): float
    {
        $account = LedgerAccount::where('owner_type', 'hotel_stay')
            ->where('owner_id', $stayId)
            ->where('kind', 'hotel_folio')
            ->first();

        if (! $account) {
            return 0.0;
        }

        return round((float) LedgerEntry::where('ledger_account_id', $account->id)->sum(DB::raw(LedgerEntry::minorMoneySql('amount'))), 2);
    }

    // ---------------------------------------------------------------- case 1

    public function test_room_service_with_charge_to_room_creates_order_and_posts_to_the_folio(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->scanned($room)->send($store, $this->payload($item, $room))
            ->assertOk()
            ->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order, 'A room-service order should have been created.');
        $this->assertSame('room_service', $order->type);
        $this->assertSame('Suite 101', $order->service_location, 'service_location must carry the human-readable room label.');

        $this->assertEqualsWithDelta(
            self::PRICE,
            $this->folioTotal($stay->id),
            0.001,
            'The order total must have been posted onto the stay\'s hotel_folio ledger account.'
        );
    }

    // ---------------------------------------------------------------- case 2

    /**
     * The control half: identical request, no `tender`. Proves the folio assertion
     * in case 1 is actually detecting the charge rather than passing vacuously.
     */
    public function test_room_service_without_tender_creates_the_order_but_never_touches_the_folio(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $payload = $this->payload($item, $room);
        unset($payload['tender']);

        $this->send($store, $payload)->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('room_service', $order->type);
        $this->assertNotSame('charge_to_room', $order->payment_method);

        $this->assertEqualsWithDelta(
            0.0,
            $this->folioTotal($stay->id),
            0.001,
            'Without tender=charge_to_room nothing may reach the folio.'
        );
    }

    // ---------------------------------------------------------------- case 3

    /** HotelFolioService::guestNameMatches is the gate; a stranger must be refused. */
    public function test_charge_to_room_with_a_name_that_does_not_match_the_stay_guest_is_rejected(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->scanned($room)->send($store, $this->payload($item, $room, ['name' => 'Mallory Vance']))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'No order may be created for a refused charge-to-room request.');
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001);
    }

    // ------------------------------------------------------------- case 3b

    /**
     * SECURITY: a 1-character name prefix ("S") must NOT match "Sam Carter". The old
     * str_starts_with() gate accepted it, so an unauthenticated visitor could bill
     * another guest's folio in ~26 tries. Nothing may reach the folio.
     */
    public function test_charge_to_room_with_a_one_char_name_prefix_is_rejected(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->scanned($room)->send($store, $this->payload($item, $room, ['name' => 'S']))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'A 1-char name prefix must not create a charge-to-room order.');
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001, 'nothing may reach the folio for a prefix-only name');
    }

    // ------------------------------------------------------------- case 3c

    /** J57: the first name alone ("Sam" for "Sam Carter") no longer charges the room, even with a valid token. */
    public function test_charge_to_room_refuses_the_first_name_alone(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->scanned($room)->send($store, $this->payload($item, $room, ['name' => 'Sam']))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001, 'a first name alone must not charge the folio');
    }

    /** J57: a charge-to-room order names its room by the QR key only; a numeric room id is refused. */
    public function test_charge_to_room_refuses_a_numeric_room_id(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->scanned($room)->send($store, $this->payload($item, $room, ['room' => (string) $room->id, 'table' => (string) $room->id]))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001);
    }

    /** J57: with the setting never touched (default), a charge without a scanned token is refused. */
    public function test_by_default_charge_to_room_requires_the_scanned_token(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->assertTrue(HotelRoomToken::enabled($store->id), 'J57: required by default');
        $this->send($store, $this->payload($item, $room))
            ->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001);
    }

    // ---------------------------------------------------------------- case 4

    public function test_charge_to_room_on_a_room_with_no_active_stay_is_rejected(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        // No check-in: the room is vacant.

        $this->send($store, $this->payload($item, $room))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'A vacant room must not produce an order.');
        $this->assertSame(
            0,
            LedgerAccount::where('kind', 'hotel_folio')->count(),
            'No folio account may be opened for a vacant room.'
        );
    }

    // ---------------------------------------------------------------- case 5

    /**
     * The PRE-FIX payload shape, pinned as a regression witness.
     *
     * Themes send their own `ordering-type` (on-table for a QR scan) and the room /
     * tender fields ride along untouched. The server evaluates `tender` ONLY inside
     * the room_service branch, so on-table + charge_to_room places a perfectly normal
     * order and the folio never sees a cent — exactly the silent failure that was
     * reported. This is precisely why the forwarder must OVERRIDE `ordering-type`
     * (with .set, not the file's additive injection which skips keys already present)
     * rather than merely forward the tender.
     */
    public function test_on_table_with_charge_to_room_is_the_pre_fix_shape_and_never_reaches_the_folio(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        // P6-351: provide a non-room dine-in table so the on-table lookup resolves.
        $table = StoreTable::create([
            'store_id' => $store->id, 'table_no' => 1, 'key' => Str::random(10), 'seats' => 2, 'area_type' => 'table',
        ]);

        $this->send($store, $this->payload($item, $room, [
            'ordering-type' => 'on-table',
            // Override with the real (non-room) table — the room key would fail the
            // dine-in resolve after P6-351, and that resolution is NOT the point of
            // this regression witness (the tender/receipt path is).
            'table' => $table->key,
        ]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order, 'The pre-fix shape still places an ordinary order — that is what made the bug silent.');
        $this->assertSame('on-table', $order->type);
        $this->assertNotSame('charge_to_room', $order->payment_method);

        $this->assertEqualsWithDelta(
            0.0,
            $this->folioTotal($stay->id),
            0.001,
            'on-table never enters the room_service branch, so tender=charge_to_room is ignored and the folio stays empty.'
        );
    }

    // ============================================================= B3 / J57
    // Signed room-token binding: when hotel_charge_room_token_required is on (J57: the
    // default), the charge must carry the signed token from the room's QR (session,
    // stashed on scan) matching the room being charged. Only an owner opt-out turns it off.

    private function requireToken(Store $store): void
    {
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '1');
    }

    /** The guest scanned this room's QR: its token is in the session. */
    private function scanned(StoreTable $room): static
    {
        return $this->withSession(['hotel_room_token' => HotelRoomToken::mint($room)]);
    }

    /** The token binds to ONE room — a room-101 token never validates room 102. */
    public function test_room_token_binds_to_its_room(): void
    {
        $store = $this->storeFixture();
        $a = $this->room($store, 101);
        $b = $this->room($store, 102);

        $this->assertTrue(HotelRoomToken::verify($a, HotelRoomToken::mint($a)));
        $this->assertFalse(HotelRoomToken::verify($a, HotelRoomToken::mint($b)), 'a token for another room must not validate');
        $this->assertFalse(HotelRoomToken::verify($a, null), 'no token is refused');
    }

    /** Owner opt-out (explicit off): charge-to-room works with no token, by room key + full name. */
    public function test_flag_off_charge_to_room_works_without_a_token(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '0');

        $this->send($store, $this->payload($item, $room)) // no session token
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(self::PRICE, $this->folioTotal($stay->id), 0.001);
    }

    /** Flag ON + no scanned token: refused, no order, no folio posting. */
    public function test_flag_on_refuses_charge_without_a_scanned_token(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->requireToken($store);

        $this->send($store, $this->payload($item, $room)) // no session token
            ->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'no order for a refused charge');
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001, 'nothing reaches the folio');
    }

    /** Flag ON + a token for a DIFFERENT room: refused, no folio posting. */
    public function test_flag_on_refuses_a_token_for_a_different_room(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store, 101);
        $other = $this->room($store, 102);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->requireToken($store);

        // Guest scanned room 102 but is trying to charge room 101.
        $this->withSession(['hotel_room_token' => HotelRoomToken::mint($other)])
            ->send($store, $this->payload($item, $room))
            ->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001);
    }

    /** Flag ON + the matching scanned token: charge succeeds and posts to the folio. */
    public function test_flag_on_accepts_the_matching_scanned_token(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->requireToken($store);

        $this->withSession(['hotel_room_token' => HotelRoomToken::mint($room)])
            ->send($store, $this->payload($item, $room))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(self::PRICE, $this->folioTotal($stay->id), 0.001);
    }
}
