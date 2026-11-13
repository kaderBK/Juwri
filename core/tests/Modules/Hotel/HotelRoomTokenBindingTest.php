<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
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
 * C4 — room-token binding for charge-to-room. The production wiring already exists —
 * store_public_qr_url() embeds the room-bound HMAC as `rt=` (Helper), publicView stashes
 * it in the session on scan (StoreController), and the room_service branch requires it
 * when the flag is on. What was UNTESTED (and could regress silently) is exactly that:
 * the QR-mint embedding, the real scan→stash path (not a withSession() injection), and
 * that the token gate is conjunctive with the name gate, never a replacement for it.
 */
class HotelRoomTokenBindingTest extends TestCase
{
    use RefreshDatabase;

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
    }

    /** A hotel-entitled store with table_qr on (the stash rides the table_qr branch). */
    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1',
                'hotel' => '1', 'allow_ordering' => '1',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        foreach ([
            'store_template' => 'crave',
            'restaurant_on_table_order' => '1',
            'hotel_mode' => '1',
        ] as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Club Sandwich', 'price' => self::PRICE, 'active' => '1',
        ]);

        return $store;
    }

    private function room(Store $store, int $no = 101): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Suite '.$no,
        ]);
    }

    private function item(Store $store): Menu
    {
        return Menu::where('store_id', $store->id)->first();
    }

    private function payload(Menu $item, StoreTable $room, array $overrides = []): array
    {
        return array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => '', 'extras' => []]]),
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

    private function requireToken(Store $store): void
    {
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '1');
    }

    private function folioTotal(int $stayId): float
    {
        $account = LedgerAccount::where('owner_type', 'hotel_stay')->where('owner_id', $stayId)
            ->where('kind', 'hotel_folio')->first();

        return $account ? round((float) LedgerEntry::where('ledger_account_id', $account->id)->sum(DB::raw(LedgerEntry::minorMoneySql('amount'))), 2) : 0.0;
    }

    // ------------------------------------------------------------ (a) stash path

    /**
     * The PRODUCTION scan→stash path, not a withSession() injection: a GET carrying the
     * room QR's `rt=` must stash the token so a later charge (same session, NO explicit
     * session) succeeds under the flag. A regression moving the stash out of reach would
     * break every real scan while the withSession()-based tests stayed green.
     */
    public function test_scan_with_rt_stashes_the_token_so_the_charge_succeeds(): void
    {
        $store = $this->store();
        $room = $this->room($store);
        $item = $this->item($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->requireToken($store);

        // Scan: the room QR URL carries &rt=<mint>. The storefront must stash it.
        $this->get('/'.$store->slug.'?table='.$room->key.'&rt='.HotelRoomToken::mint($room))
            ->assertOk()
            ->assertSessionHas('hotel_room_token', HotelRoomToken::mint($room));

        // Charge WITHOUT withSession(): only the stash from the scan can authorize it.
        $this->send($store, $this->payload($item, $room))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(self::PRICE, $this->folioTotal($stay->id), 0.001);
    }

    // ------------------------------------------------------------ (b) QR mint embedding

    public function test_qr_url_embeds_the_room_token_only_when_the_flag_is_on(): void
    {
        $store = $this->store();
        $room = $this->room($store);

        // Flag explicitly off (owner opt-out; J57 made it on by default): the printed QR must NOT carry a token.
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '0');
        $this->assertStringNotContainsString('rt=', store_public_qr_url($store, $room->key), 'no token when the flag is off');

        $this->requireToken($store);
        // store_options memoizes per request; clear it so the flag flip is visible.
        app()->forgetInstance('store_options_'.$store->id);
        $url = store_public_qr_url($store, $room->key);
        $this->assertStringContainsString('rt='.HotelRoomToken::mint($room), $url, 'the QR must embed the room-bound token when the flag is on');
    }

    // ------------------------------------------------------------ (c) token+name conjunction

    /**
     * A valid scanned token must NOT replace the name gate: flag ON + the matching token
     * + a stranger's name is still refused. Proves the gates are conjunctive.
     */
    public function test_valid_token_with_a_mismatched_name_is_still_refused(): void
    {
        $store = $this->store();
        $room = $this->room($store);
        $item = $this->item($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->requireToken($store);

        $this->withSession(['hotel_room_token' => HotelRoomToken::mint($room)])
            ->send($store, $this->payload($item, $room, ['name' => 'Mallory Vance']))
            ->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001);
    }

    // ------------------------------------------------------------ (d) garbage token

    public function test_flag_on_refuses_a_garbage_token_string(): void
    {
        $store = $this->store();
        $room = $this->room($store);
        $item = $this->item($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->requireToken($store);

        $this->withSession(['hotel_room_token' => 'not-a-token'])
            ->send($store, $this->payload($item, $room))
            ->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001);
    }

    /** J52b SF1: the one-time migration drops the pre-J57 stored '0', so the J57 default (required) applies. */
    public function test_upgrade_migration_drops_the_stored_pre_j57_opt_out(): void
    {
        $store = $this->store();
        $other = $this->store();
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '0');
        StoreSettings::set($other->id, 'hotel_charge_room_token_required', '1');
        StoreSettings::set($store->id, 'some_custom_flag', 'keep');
        $this->assertFalse(HotelRoomToken::enabled($store->id), 'precondition: the stored 0 turns it off');

        $migration = require base_path('app/Modules/Hotel/Database/Migrations/2026_10_26_120000_reset_hotel_room_token_required.php');
        $migration->up();
        $migration->up(); // re-run safe
        StoreOption::flushOptionMemo();

        $this->assertTrue(HotelRoomToken::enabled($store->id), 'J57 default reaches the hotel');
        $this->assertTrue(HotelRoomToken::enabled($other->id));
        $this->assertSame('1', (string) store_options($store->id, 'hotel_mode'), 'other settings untouched');
        $this->assertSame('keep', (string) store_options($store->id, 'some_custom_flag'));
        $settings = json_decode((string) DB::table('stores')->where('id', $store->id)->value('settings'), true);
        $this->assertArrayNotHasKey('hotel_charge_room_token_required', $settings[StoreSettings::CUSTOM] ?? []);

        // An explicit opt-out saved after the upgrade still turns it off.
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '0');
        $this->assertFalse(HotelRoomToken::enabled($store->id));
    }
}
