<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\PosShift;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P6-59b — IDOR sweep for ids in the REQUEST BODY (P6-59 / OwnerApiIdorCensusTest covered path ids only).
 *
 * One row per write endpoint whose body carries a model id: an actor of store A posts store B's id and the request is
 * refused (403 / 404 / 422, or the dashboard's `success:false` JSON) AND nothing of A ever points at B's row, AND B's
 * rows are byte-identical afterwards. The full per-endpoint census (incl. the many endpoints that were already scoped)
 * is in the internal IDOR sweep notes, "Body ids" section.
 */
class BodyIdIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config([
            'api.owner_rate' => 100000, 'tenancy.rate_limits.owner_api_per_account' => 100000,
            'settings.admin_allow_combos' => true, 'settings.admin_allow_digital_signage' => true,
            'settings.admin_allow_pos_bridge' => true, 'settings.admin_allow_aggregator_deliverect' => true,
        ]);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1', 'table_qr' => '1',
                'hotel' => '1', 'aggregator_hub' => '1'],
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'email' => Str::lower(Str::random(8)).'@idor.test']);
    }

    /** @return array{owner: User, store: Store, branch: Branch, category: MenuCategory, item: Menu, customer: Customer, room: StoreTable, type: RoomType} */
    private function world(string $tag): array
    {
        $owner = $this->owner();
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'idor-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => "Store {$tag}",
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        $branch = Branch::create(['store_id' => $store->id, 'name' => "Branch {$tag}", 'active' => true]);
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => "Cat {$tag}", 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => "Item {$tag}", 'price' => 9, 'active' => 1]);
        $customerId = DB::table('customers')->insertGetId(['store_id' => $store->id, 'account_id' => $store->fresh()->account_id,
            'name' => "Guest {$tag}", 'phone' => UniquePhone::next('05')]);
        StoreSettings::set($store->id, 'hotel_mode', '1');
        $type = RoomType::create(['store_id' => $store->id, 'name' => "Std {$tag}", 'base_rate' => 100]);
        $room = StoreTable::create(['store_id' => $store->id, 'table_no' => (string) random_int(100, 999), 'key' => Str::random(10),
            'area_type' => 'room', 'room_type_id' => $type->id]);

        return ['owner' => $owner, 'store' => $store, 'branch' => $branch, 'category' => $category, 'item' => $item,
            'customer' => Customer::findOrFail($customerId), 'room' => $room, 'type' => $type];
    }

    /** Every row of store B a body id could touch, serialised — must be identical before and after the attack. */
    private function snapshot(Store $store): string
    {
        $rows = [];
        foreach (['branches', 'categories', 'items', 'customers', 'store_tables', 'room_types', 'aggregator_connections', 'hotel_booking_nights'] as $table) {
            $q = DB::table($table);
            $table === 'hotel_booking_nights'
                ? $q->whereIn('room_id', DB::table('store_tables')->where('store_id', $store->id)->pluck('id'))
                : $q->where('store_id', $store->id);
            $rows[$table] = $q->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return json_encode($rows, JSON_THROW_ON_ERROR);
    }

    private function assertRefused(TestResponse $res, string $case): void
    {
        $status = $res->getStatusCode();
        $ok = in_array($status, [403, 404, 422], true)
            || ($status === 200 && $res->headers->get('content-type') === 'application/json' && $res->json('success') === false);
        $this->assertTrue($ok, "{$case}: a foreign body id must be refused, got HTTP {$status}: ".mb_substr((string) $res->getContent(), 0, 300));
    }

    /**
     * Run one attack: $send posts store B's id as store A's actor. Refused, B unchanged, and $leak (A-side check) false.
     *
     * @param  Closure(): TestResponse  $send
     * @param  Closure(): bool  $leak  true when an A row now references B's id
     */
    private function attack(string $case, Store $victim, Closure $send, Closure $leak): void
    {
        $before = $this->snapshot($victim);
        $this->assertRefused($send(), $case);
        $this->assertSame($before, $this->snapshot($victim), "{$case}: store B's rows changed");
        $this->assertFalse($leak(), "{$case}: a row of store A now points at store B's id");
    }

    private function apiToken(User $owner): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);

        return $raw;
    }

    // ── owner API v1 (bearer token) ─────────────────────────────────────────

    public function test_owner_api_order_create_refuses_another_stores_customer_and_branch(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureFeatureEnabled::class]);
        $a = $this->world('A');
        $b = $this->world('B');
        $h = ['Authorization' => 'Bearer '.$this->apiToken($a['owner'])];
        $order = fn (array $extra) => $this->postJson(route('api.v1.orders.store', ['id' => $a['store']->id]),
            ['type' => 'takeaway', 'customer_name' => 'X', 'items' => [['item_id' => $a['item']->id, 'quantity' => 1]]] + $extra, $h);
        $noOrder = fn () => DB::table('orders')->where('store_id', $a['store']->id)->exists();

        $this->attack('api.v1.orders.store customer_id', $b['store'], fn () => $order(['customer_id' => $b['customer']->id]), $noOrder);
        $this->attack('api.v1.orders.store branch_id', $b['store'], fn () => $order(['branch_id' => $b['branch']->id]), $noOrder);

        // Control: the store's own customer + branch are accepted.
        $order(['customer_id' => $a['customer']->id, 'branch_id' => $a['branch']->id])->assertSuccessful();
        $this->assertDatabaseHas('orders', ['store_id' => $a['store']->id, 'customer_id' => $a['customer']->id, 'branch_id' => $a['branch']->id]);
    }

    // ── owner dashboard ─────────────────────────────────────────────────────

    public function test_menu_item_create_and_update_refuse_another_stores_category(): void
    {
        $this->withoutMiddleware();
        $a = $this->world('A');
        $b = $this->world('B');
        $base = ['name' => 'Injected', 'price' => 5, 'type' => 'veg', 'category_id' => $b['category']->id];

        $this->attack('stores.addMenuItem category_id', $b['store'],
            fn () => $this->actingAs($a['owner'])->postJson(route('stores.addMenuItem', $a['store']), $base),
            fn () => Menu::where('store_id', $a['store']->id)->where('category_id', $b['category']->id)->exists());
        $this->attack('stores.updateMenuItem category_id', $b['store'],
            fn () => $this->actingAs($a['owner'])->postJson(route('stores.updateMenuItem', $a['store']), $base + ['id' => $a['item']->id]),
            fn () => (int) $a['item']->fresh()->category_id === (int) $b['category']->id);

        // Control: the store's own category still saves.
        $this->actingAs($a['owner'])->postJson(route('stores.addMenuItem', $a['store']), ['category_id' => $a['category']->id] + $base)
            ->assertJsonPath('success', true);
    }

    public function test_aggregator_connect_cannot_rehome_another_stores_outlet_or_bind_its_branch(): void
    {
        $this->withoutMiddleware();
        $a = $this->world('A');
        $b = $this->world('B');
        AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $b['store']->id, 'external_store_id' => 'B-OUTLET', 'status' => 'active']);
        $url = route('stores.aggregators.connect').'?store_id='.$a['store']->id;

        $this->attack('stores.aggregators.connect external_store_id', $b['store'],
            fn () => $this->actingAs($a['owner'])->postJson($url, ['provider' => 'deliverect', 'external_store_id' => 'B-OUTLET', 'webhook_secret' => 'x']),
            fn () => AggregatorConnection::where('store_id', $a['store']->id)->exists());
        $this->attack('stores.aggregators.connect branch_id', $b['store'],
            fn () => $this->actingAs($a['owner'])->postJson($url, ['provider' => 'deliverect', 'external_store_id' => 'A-OUTLET', 'webhook_secret' => 'x', 'branch_id' => $b['branch']->id]),
            fn () => AggregatorConnection::where('store_id', $a['store']->id)->exists());

        // Control: re-saving the store's OWN outlet is still an upsert.
        $this->actingAs($a['owner'])->post($url, ['provider' => 'deliverect', 'external_store_id' => 'A-OUTLET', 'webhook_secret' => 'x', 'branch_id' => $a['branch']->id])->assertRedirect();
        $this->actingAs($a['owner'])->post($url, ['provider' => 'deliverect', 'external_store_id' => 'A-OUTLET', 'webhook_secret' => 'y'])->assertRedirect();
        $this->assertSame(1, AggregatorConnection::where('store_id', $a['store']->id)->count());
    }

    public function test_india_aggregator_pos_bridge_and_signage_refuse_another_stores_branch(): void
    {
        $this->withoutMiddleware();
        $a = $this->world('A');
        $b = $this->world('B');
        $as = $this->actingAs($a['owner']);
        $q = '?store_id='.$a['store']->id;

        $this->attack('stores.zomato.connect branch_id', $b['store'],
            fn () => $as->postJson(route('stores.zomato.connect', $a['store']->id), ['external_store_id' => 'ZOM-A', 'webhook_secret' => 's', 'branch_id' => $b['branch']->id]),
            fn () => AggregatorConnection::where('store_id', $a['store']->id)->exists());
        $this->attack('stores.posbridge.connect branch_id', $b['store'],
            fn () => $as->postJson(route('stores.posbridge.connect').$q, ['provider' => 'square', 'external_location_id' => 'L', 'access_token' => 't', 'branch_id' => $b['branch']->id]),
            fn () => DB::table('pos_bridge_connections')->where('store_id', $a['store']->id)->exists());
        $this->attack('stores.signage.store branch_id', $b['store'],
            fn () => $as->postJson(route('stores.signage.store').$q, ['kind' => 'menu', 'branch_id' => $b['branch']->id]),
            fn () => DB::table('signage_screens')->where('store_id', $a['store']->id)->exists());
    }

    public function test_combo_save_refuses_another_stores_branch(): void
    {
        $this->withoutMiddleware();
        $a = $this->world('A');
        $b = $this->world('B');

        $this->attack('combos.save branch_id', $b['store'],
            fn () => $this->actingAs($a['owner'])->postJson(route('combos.save'), [
                'store_id' => $a['store']->id, 'branch_id' => $b['branch']->id, 'name' => 'Meal', 'price' => 10,
                'slots' => [['title' => 'Main', 'min' => 1, 'max' => 1, 'options' => [['menu_id' => $a['item']->id]]]],
            ]),
            fn () => DB::table('combos')->where('store_id', $a['store']->id)->exists());
    }

    public function test_bogo_coupon_refuses_another_stores_item_and_category(): void
    {
        $this->withoutMiddleware();
        $a = $this->world('A');
        $b = $this->world('B');
        $coupon = fn (array $o) => $this->actingAs($a['owner'])->postJson(route('stores.coupons.store'), $o + [
            'code' => 'BG'.Str::upper(Str::random(5)), 'coupon_type' => 'bogo', 'buy_scope' => 'item', 'buy_id' => $a['item']->id,
            'buy_qty' => 1, 'get_id' => $a['item']->id, 'get_qty' => 1, 'get_discount_percent' => 100,
        ]);
        $none = fn () => DB::table('coupons')->where('user_id', $a['owner']->id)->exists();

        $this->attack('stores.coupons.store buy_id', $b['store'], fn () => $coupon(['buy_id' => $b['item']->id]), $none);
        $this->attack('stores.coupons.store get_id', $b['store'], fn () => $coupon(['get_id' => $b['item']->id]), $none);
        $this->attack('stores.coupons.store buy_id (category)', $b['store'], fn () => $coupon(['buy_scope' => 'category', 'buy_id' => $b['category']->id]), $none);
    }

    public function test_hotel_booking_store_update_and_confirm_refuse_another_stores_room(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $a = $this->world('A');
        $b = $this->world('B');
        $as = $this->actingAs($a['owner'], 'web');
        $dates = ['check_in' => now()->addDays(10)->toDateString(), 'check_out' => now()->addDays(12)->toDateString()];
        $booking = BookingService::create((int) $a['store']->id, ['guest_name' => 'G', 'room_type_id' => $a['type']->id] + $dates, HotelBooking::STATUS_PENDING);
        $onB = fn () => HotelBooking::where('store_id', $a['store']->id)->where('room_id', $b['room']->id)->exists();

        $this->attack('hotel.pms.bookings.store room_id', $b['store'],
            fn () => $as->postJson(route('hotel.pms.bookings.store'), ['guest_name' => 'G', 'room_type_id' => $a['type']->id, 'room_id' => $b['room']->id] + $dates), $onB);
        $this->attack('hotel.pms.bookings.update room_id', $b['store'],
            fn () => $as->postJson(route('hotel.pms.bookings.update', $booking->id), ['room_id' => $b['room']->id]), $onB);
        $this->attack('hotel.pms.bookings.confirm room_id', $b['store'],
            fn () => $as->postJson(route('hotel.pms.bookings.confirm', $booking->id), ['room_id' => $b['room']->id]), $onB);

        // Control: the store's own room confirms.
        $as->postJson(route('hotel.pms.bookings.confirm', $booking->id), ['room_id' => $a['room']->id])->assertOk();
        $this->assertSame((int) $a['room']->id, (int) $booking->fresh()->room_id);
    }

    // ── POS (staff guard) ───────────────────────────────────────────────────

    public function test_pos_open_shift_refuses_another_stores_branch(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $a = $this->world('A');
        $b = $this->world('B');
        $manager = StaffMember::create(['owner_id' => $a['owner']->id, 'store_id' => $a['store']->id, 'name' => 'M', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1]);

        $this->attack('pos.shift.open branch_id', $b['store'],
            fn () => $this->actingAs($manager, 'staff')->postJson(route('pos.shift.open', ['store' => $a['store']->id]), ['opening_float' => 10, 'branch_id' => $b['branch']->id]),
            fn () => PosShift::where('store_id', $a['store']->id)->exists());

        $this->actingAs($manager, 'staff')->postJson(route('pos.shift.open', ['store' => $a['store']->id]), ['opening_float' => 10, 'branch_id' => $a['branch']->id])
            ->assertStatus(201);
    }
}
