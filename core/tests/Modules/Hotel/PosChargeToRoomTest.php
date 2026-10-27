<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreTable;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Staff POS "Charge to room" tender (closes the V6 gap: the guest storefront could
 * charge a room-service order to the folio, but the register had no room tender).
 * Settling a POS order that sits on a checked-in room with method=charge_to_room
 * must post the order onto the guest folio (the same idempotent
 * `hotel_folio:order:{id}` ledger path the guest flow uses) and never collect cash.
 */
class PosChargeToRoomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: Store, 1: StaffMember, 2: User} */
    private function hotelStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'hotel' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 20, 'active' => 1]);
        $staff = StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        return [$store, $staff, $owner];
    }

    private function checkedInRoom(Store $store): StoreTable
    {
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Std', 'base_rate' => 100]);
        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => (string) rand(200, 999),
            'key' => Str::random(10), 'area_type' => 'room', 'room_type_id' => $type->id, 'status' => 'free',
        ]);
        HotelFolioService::checkIn((int) $store->id, $room, 'Sam Carter', '0550000000');

        return $room;
    }

    private function orderOnTable(Store $store, ?string $tableNo): Order
    {
        $item = Menu::where('store_id', $store->id)->first();
        $order = OrderApiService::create($store->user_id, $store->id, [
            'type' => $tableNo ? 'on-table' : 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        if ($tableNo !== null) {
            $order->table_number = $tableNo;
            $order->save();
        }

        return $order->fresh();
    }

    public function test_charge_to_room_posts_the_order_onto_the_guest_folio(): void
    {
        [$store, $staff] = $this->hotelStore();
        $room = $this->checkedInRoom($store);
        $order = $this->orderOnTable($store, $room->table_no);
        $total = (float) $order->total;
        $this->assertGreaterThan(0, $total);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.settle', ['id' => $order->id]), ['method' => 'charge_to_room'])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('charge_to_room', $fresh->payment_method);

        // The order landed on the guest folio via the shared ledger ref — the money
        // path, not a cash collection. Amount equals the order total.
        $line = LedgerEntry::where('ref', 'hotel_folio:order:'.$order->id.':folio')->first();
        $this->assertNotNull($line, 'expected a folio charge ledger line for the POS order');
        $this->assertEqualsWithDelta($total, (float) $line->amount, 0.01);
        // No cash was tendered.
        $this->assertNull($fresh->amount_tendered);
    }

    public function test_charge_to_room_is_rejected_for_a_non_room_order(): void
    {
        [$store, $staff] = $this->hotelStore();
        $this->checkedInRoom($store); // a room exists, but this order is not on it
        $order = $this->orderOnTable($store, null); // takeaway, no table

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.settle', ['id' => $order->id]), ['method' => 'charge_to_room'])
            ->assertStatus(422);

        $this->assertNull(LedgerEntry::where('ref', 'hotel_folio:order:'.$order->id.':folio')->first());
    }

    /** J52b note 1: next to "Charge to room" the settle sheet tells staff to confirm the guest first. */
    public function test_settle_sheet_asks_staff_to_confirm_the_guest_before_charging(): void
    {
        $html = Blade::render("@include('staff.pos.partials.settle')", [], deleteCachedView: true);

        $this->assertStringContainsString('pos-settle-hint', $html);
        $this->assertStringContainsString(___('Before charging, confirm the guest name and room with the guest. The order may come from an anonymous room service request.'), $html);
        $this->assertStringContainsString('x-show="canChargeToRoom()"', $html);
    }

    public function test_tables_payload_flags_a_checked_in_room_as_chargeable(): void
    {
        [$store, $staff] = $this->hotelStore();
        $room = $this->checkedInRoom($store);

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.tables'))
            ->assertOk()
            ->assertJsonFragment([
                'is_room' => true,
                'can_charge_to_room' => true,
                'room_guest' => 'Sam Carter',
            ]);
    }
}
