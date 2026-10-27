<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelRoomToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Feature\MoneyBugs4\BeforeAfter;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Lane A — money/ledger hardening for the charge-to-room folio.
 *
 * These lock down the three P1 money bugs (A1 double-charge on wallet/gift-card
 * tenders, A2 pay_online+charge stuck-unpaid, A3 cancel/refund never reverses)
 * plus the settle/write-off/charge race hardening (A4), the currency-split fix
 * (A6) and 3-decimal-currency rounding parity (A7). Folio balances are read
 * straight off the ledger (owner_type/owner_id), never via
 * hotel_stays.folio_account_id.
 */
class HotelMoneyHardeningTest extends TestCase
{
    use BeforeAfter;
    use RefreshDatabase;

    private const GUEST = 'Sam Carter';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Mail::fake();
        Notification::fake();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(array $options = []): Store
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
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '1');
        StoreOption::updateStoreOption($store->id, 'currency_code', $options['currency_code'] ?? 'USD');
        foreach ($options as $k => $v) {
            if ($k === 'currency_code') {
                continue;
            }
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function room(Store $store, int $no = 101): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Suite '.$no,
        ]);
    }

    private function menuItem(Store $store, float $price = 50.0): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Club Sandwich', 'price' => $price, 'active' => '1',
        ]);
    }

    private function payload(Menu $item, StoreTable $room, array $overrides = []): array
    {
        return array_merge([
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

    private function send(Store $store, array $payload, ?Customer $as = null)
    {
        $req = $as ? $this->actingAs($as, 'customer') : $this;
        // J57: the guest scanned the room's QR, so its room token is in the session (required by default).
        $room = isset($payload['room']) ? StoreTable::where('store_id', $store->id)->where('key', $payload['room'])->first() : null;
        if ($room) {
            $req = $req->withSession(['hotel_room_token' => HotelRoomToken::mint($room)]);
        }

        return $req->postJson(route('store.submitOrder', ['store' => $store->id]), $payload);
    }

    private function folioAccount(int $stayId): ?LedgerAccount
    {
        return LedgerAccount::where('owner_type', 'hotel_stay')
            ->where('owner_id', $stayId)
            ->where('kind', 'hotel_folio')
            ->first();
    }

    private function folioTotal(int $stayId): float
    {
        $account = $this->folioAccount($stayId);

        return $account ? round((float) LedgerEntry::where('ledger_account_id', $account->id)->sum(DB::raw(LedgerEntry::minorMoneySql('amount'))), 3) : 0.0;
    }

    private function customerWith(Store $store, float $credit): Customer
    {
        $customer = Customer::create([
            'name' => self::GUEST, 'store_id' => $store->id, 'phone' => UniquePhone::next('55-'),
        ]);
        if ($credit > 0) {
            $acct = CustomerWalletService::accountFor($customer);
            $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
            LedgerService::post([
                ['account' => $clr, 'amount' => -$credit, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
                ['account' => $acct, 'amount' => $credit, 'ref' => "seed:{$customer->id}:usr"],
            ]);
        }

        return $customer;
    }

    // ------------------------------------------------------------------ A1

    public function test_a1_wallet_tender_charges_folio_only_the_outstanding_amount(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 50.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $customer = $this->customerWith($store, 20.0);

        $this->send($store, $this->payload($item, $room, ['use_wallet' => 1]), $customer)
            ->assertOk()->assertJson(['success' => true, 'charged_to_room' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('charge_to_room', $order->payment_method);
        $this->assertEqualsWithDelta(20.0, (float) $order->wallet_applied, 0.001, 'wallet must have been spent once');

        // Folio holds ONLY what is still owed: total (50) minus the wallet tender (20).
        $this->assertEqualsWithDelta(30.0, $this->folioTotal($stay->id), 0.001,
            'folio must be charged amount_due (total - wallet), never the gross total');

        // The wallet ledger was debited exactly once (no double spend).
        $walletAcct = CustomerWalletService::accountFor($customer);
        $this->assertEqualsWithDelta(0.0, LedgerService::balanceFor($walletAcct), 0.001,
            'wallet balance drained to zero exactly (20 seeded - 20 spent)');
    }

    public function test_a1_gift_card_tender_charges_folio_only_the_outstanding_amount(): void
    {
        $store = $this->storeFixture(['gift_cards_enabled' => '1']);
        $room = $this->room($store);
        $item = $this->menuItem($store, 50.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($store->id, 15.0, 'stripe');
        GiftCardService::activate($card, $txn);

        $this->send($store, $this->payload($item, $room, ['gift_card_code' => $card->fresh()->code]))
            ->assertOk()->assertJson(['success' => true, 'charged_to_room' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertEqualsWithDelta(15.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(35.0, $this->folioTotal($stay->id), 0.001,
            'folio charged total (50) minus gift-card tender (15)');
    }

    public function test_a1_fully_covered_order_stamps_charge_to_room_with_no_folio_post(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 40.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $customer = $this->customerWith($store, 60.0); // more than the order

        $this->send($store, $this->payload($item, $room, ['use_wallet' => 1]), $customer)
            ->assertOk()->assertJson(['success' => true, 'charged_to_room' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertSame('charge_to_room', $order->payment_method, 'still tendered to the room');
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001,
            'nothing owed to the folio when tenders cover the order in full');
    }

    // ------------------------------------------------------------------ A2

    public function test_a2_pay_online_plus_charge_to_room_is_not_left_unpaid_and_folio_is_charged_once(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 50.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        // Both selected: pay_via=pay_online AND tender=charge_to_room.
        $this->send($store, $this->payload($item, $room, ['pay_via' => 'pay_online']))
            ->assertOk()->assertJson(['success' => true, 'charged_to_room' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertNotSame('unpaid', $order->status,
            'a folio-charged order must never be stuck unpaid (KDS/owner would never see it)');
        $this->assertSame('charge_to_room', $order->payment_method);

        // Folio charged exactly once, the full total (no tenders here).
        $this->assertEqualsWithDelta(50.0, $this->folioTotal($stay->id), 0.001);

        // No gateway Transaction is created for a charge-to-room order.
        $this->assertSame(0, Transaction::where('product_id', $order->id)->count(),
            'charge-to-room must not also create a payment Transaction');

        // The order is on the normal flow (visible to owner lists that exclude unpaid).
        $this->assertGreaterThan(0, Order::where('store_id', $store->id)
            ->where('status', '!=', 'unpaid')->count());
    }

    public function test_a2_belt_and_braces_clears_a_residual_unpaid_after_charge(): void
    {
        // Prove the service-level guard independently: an order stamped 'unpaid'
        // before charging is put back on the normal flow by chargeOrderForTender.
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 25.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'unpaid',
            'table_number' => $room->table_no, 'service_location' => 'Suite 101',
            'grand_total' => 25.0, 'subtotal' => 25.0,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'unit_price' => 25.0,
        ]);
        $order->load('items.menu');

        $res = HotelFolioService::chargeOrderForTender($order, $stay);
        $this->assertTrue($res['charged_to_room']);
        $this->assertSame('pending', $order->fresh()->status, 'residual unpaid must be cleared to pending');
        $this->assertEqualsWithDelta(25.0, $this->folioTotal($stay->id), 0.001);
    }

    // ------------------------------------------------------------------ A3

    /** Place a charge-to-room order and return [order, stay]. */
    private function placeChargeOrder(Store $store, StoreTable $room, HotelStay $stay, Menu $item): Order
    {
        $this->send($store, $this->payload($item, $room))
            ->assertOk()->assertJson(['success' => true, 'charged_to_room' => true]);

        return Order::where('store_id', $store->id)->latest('id')->first();
    }

    public function test_a3_cancelling_a_charge_to_room_order_reverses_the_folio(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 50.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $order = $this->placeChargeOrder($store, $room, $stay, $item);

        $this->assertEqualsWithDelta(50.0, $this->folioTotal($stay->id), 0.001);
        $this->assertSame('charged', HotelFolioService::orderChargeReversalState($order));

        $rev = HotelFolioService::reverseOrderCharge($order);
        $this->assertSame('reversed', $rev['state']);
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001, 'folio balance drops to zero after reversal');
        $this->assertSame('reversed', HotelFolioService::orderChargeReversalState($order));
    }

    public function test_a3_double_cancel_reverses_exactly_once(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 30.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $order = $this->placeChargeOrder($store, $room, $stay, $item);

        HotelFolioService::reverseOrderCharge($order);
        HotelFolioService::reverseOrderCharge($order); // idempotent

        $account = $this->folioAccount($stay->id);
        $revLegs = LedgerEntry::where('ledger_account_id', $account->id)
            ->where('ref', 'hotel_folio:order:'.$order->id.':rev:folio')->count();
        $this->assertSame(1, $revLegs, 'only one reversal leg may exist on the folio');
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001);
    }

    public function test_a3_cancel_after_settle_is_flagged_pending_not_silent(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 40.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $order = $this->placeChargeOrder($store, $room, $stay, $item);

        // Guest settled the full balance at reception.
        HotelFolioService::settle($stay, 40.0, 'cash');
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001);

        // Reversal now cannot post (would drive the folio negative) — flagged, not silent.
        $rev = HotelFolioService::reverseOrderCharge($order);
        $this->assertSame('pending', $rev['state']);
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001, 'no negative balance is created');
        // The order stays flagged for a manual adjustment (A5).
        $this->assertSame('charged', HotelFolioService::orderChargeReversalState($order));
    }

    public function test_a3_reverse_is_a_noop_for_a_non_charge_order(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 20.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        // A plain order that was never charged to the folio.
        $payload = $this->payload($item, $room);
        unset($payload['tender']);
        $this->send($store, $payload)->assertOk();
        $order = Order::where('store_id', $store->id)->latest('id')->first();

        $this->assertSame('none', HotelFolioService::orderChargeReversalState($order));
        $this->assertSame('none', HotelFolioService::reverseOrderCharge($order)['state']);
    }

    public function test_a3_owner_cancel_endpoint_reverses_the_folio(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 50.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $order = $this->placeChargeOrder($store, $room, $stay, $item);
        $owner = User::find($store->user_id);

        $this->actingAs($owner)->withoutMiddleware()
            ->postJson(route('stores.updateOrderStatus', ['order' => $order->id]), ['status' => 'cancelled'])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001,
            'the owner cancel path must reverse the folio charge');
    }

    public function test_a3_refund_to_credit_is_refused_while_a_folio_charge_stands(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 50.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $customer = $this->customerWith($store, 0.0);
        $order = $this->placeChargeOrder($store, $room, $stay, $item);
        $order->customer_id = $customer->id;
        $order->save();
        $owner = User::find($store->user_id);

        $this->actingAs($owner)->withoutMiddleware()
            ->postJson(route('stores.refundOrderToCredit', ['order' => $order->id]), [])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        // Folio charge untouched, no wallet credit issued.
        $this->assertEqualsWithDelta(50.0, $this->folioTotal($stay->id), 0.001);
    }

    // ------------------------------------------------------------------ A4

    public function test_a4_settle_is_idempotent_with_a_supplied_key(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 50.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->placeChargeOrder($store, $room, $stay, $item);

        $key = (string) Str::uuid();
        HotelFolioService::settle($stay, 30.0, 'cash', $key);
        HotelFolioService::settle($stay, 30.0, 'cash', $key); // double-submit, same key

        // The 30 payment landed exactly once — balance is 50 - 30 = 20, not -10.
        $this->assertEqualsWithDelta(20.0, HotelFolioService::balance($stay), 0.001,
            'a double-submitted settle with the same key records the payment once');
    }

    public function test_a4_second_check_out_is_refused_not_silently_successful(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        HotelFolioService::checkOut($stay); // clean checkout, zero balance
        $this->assertSame('checked_out', $stay->fresh()->status);

        $this->expectException(\RuntimeException::class);
        HotelFolioService::checkOut($stay->fresh());
    }

    public function test_a4_charge_racing_a_checkout_is_refused_and_posts_nothing(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 25.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        // A stale in-memory stay instance held by the order flow.
        $stale = HotelStay::find($stay->id);

        // Front desk checks the guest out in the meantime.
        HotelFolioService::checkOut($stay);

        // A late order tries to post onto the (now settled) folio with the stale stay.
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Suite 101',
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'unit_price' => 25.0]);
        $order->load('items.menu');

        $this->expectException(\RuntimeException::class);
        try {
            HotelFolioService::chargeOrder($order, $stale);
        } finally {
            $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001,
                'no charge may land on a checked-out folio');
        }
    }

    public function test_a4_sequential_double_check_in_creates_one_stay(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        try {
            HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
            $this->fail('a second check-in on an occupied room must be refused');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(1, HotelStay::where('store_id', $store->id)
            ->where('table_id', $room->id)->where('status', 'checked_in')->count());
    }

    // ------------------------------------------------------------------ A5

    public function test_a5_manual_charge_adjustment_raises_the_balance_and_logs_activity(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $owner = User::find($store->user_id);

        $this->actingAs($owner)
            ->postJson(route('hotel.folio.adjust', $stay->id), ['amount' => 15, 'reason' => 'Minibar'])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(15.0, HotelFolioService::balance($stay), 0.001);
        $this->assertTrue(ActivityLog::where('action', 'hotel.folio.adjust')->exists(),
            'a manual adjustment must be activity-logged');
    }

    public function test_a5_credit_adjustment_lowers_the_balance(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 50.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->placeChargeOrder($store, $room, $stay, $item);
        $owner = User::find($store->user_id);

        $this->actingAs($owner)
            ->postJson(route('hotel.folio.adjust', $stay->id), ['amount' => -20, 'reason' => 'Overcharge correction'])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(30.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_a5_over_credit_is_rejected(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 10.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->placeChargeOrder($store, $room, $stay, $item);
        $owner = User::find($store->user_id);

        $this->actingAs($owner)
            ->postJson(route('hotel.folio.adjust', $stay->id), ['amount' => -50, 'reason' => 'Too much'])
            ->assertStatus(422)->assertJson(['success' => false]);

        // Balance untouched — an over-credit may not drive the folio negative.
        $this->assertEqualsWithDelta(10.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_a5_adjust_is_tenant_scoped(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        // A different store's owner cannot adjust this stay (stayFor scoping → 404).
        $other = $this->storeFixture();
        $otherOwner = User::find($other->user_id);

        $this->actingAs($otherOwner)
            ->postJson(route('hotel.folio.adjust', $stay->id), ['amount' => 15, 'reason' => 'x'])
            ->assertNotFound();

        $this->assertEqualsWithDelta(0.0, HotelFolioService::balance($stay), 0.001);
    }

    // ------------------------------------------------------------------ A6

    public function test_a6_store_currency_change_mid_stay_does_not_split_or_zero_the_folio(): void
    {
        $store = $this->storeFixture(['currency_code' => 'USD']);
        $room = $this->room($store);
        $item = $this->menuItem($store, 25.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->placeChargeOrder($store, $room, $stay, $item);

        $this->assertEqualsWithDelta(25.0, HotelFolioService::balance($stay), 0.001);
        $pinned = $this->folioAccount($stay->id);
        $this->assertNotNull($pinned);
        $this->assertSame('USD', $pinned->currency);

        // Owner switches the store currency mid-stay (a supported MC operation).
        // updateStoreOption's saved() hook flushes the option memo.
        StoreOption::updateStoreOption($store->id, 'currency_code', 'EUR');

        // The balance survives: read still resolves the PINNED account, not a fresh
        // empty EUR one.
        $this->assertEqualsWithDelta(25.0, HotelFolioService::balance($stay), 0.001,
            'the open balance must survive a store currency change');
        $this->assertSame(1, LedgerAccount::where('owner_type', 'hotel_stay')
            ->where('owner_id', $stay->id)->where('kind', 'hotel_folio')->count(),
            'no second folio account may be spawned in the new currency');

        // Check-out is still blocked while the (original-currency) account holds a
        // balance, regardless of the current store currency.
        try {
            HotelFolioService::checkOut($stay);
            $this->fail('check-out must refuse while the folio holds a balance');
        } catch (\RuntimeException $e) {
            // expected
        }

        HotelFolioService::settle($stay, 25.0, 'cash');
        $stay = HotelFolioService::checkOut($stay);
        $this->assertSame('checked_out', $stay->status);
    }

    // ------------------------------------------------------------------ A7

    public function test_a7_three_decimal_currency_folio_matches_order_total_at_achievable_precision(): void
    {
        // A KWD (3-decimal) store. The folio charge must equal the order's amount_due
        // (bug batch 4 · 1: the ledger now posts fils — see
        // test_a7_kwd_folio_charge_statement_and_settlement_keep_fils). This pins that the
        // folio rounds CURRENCY-aware, never drifting from Order::getAmountDueAttribute.
        $this->assertSame(3, currency_decimals('KWD'), 'guard: KWD is a 3-decimal currency');

        $store = $this->storeFixture(['currency_code' => 'KWD']);
        $room = $this->room($store);
        $item = $this->menuItem($store, 12.340); // exact at 2dp — no ledger-cap drift
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->placeChargeOrder($store, $room, $stay, $item);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertSame('KWD', $order->currency_code, 'order snapshots the store currency');

        // Folio balance == order amount_due, at the achievable (2dp) ledger precision.
        $this->assertEqualsWithDelta(
            round((float) $order->amount_due, 2),
            round($this->folioTotal($stay->id), 2),
            0.0005,
            'the folio charge must not diverge from the order total for a 3-decimal store'
        );
        $this->assertEqualsWithDelta(12.34, $this->folioTotal($stay->id), 0.0005);
    }

    /** Bug batch 4 · 1: the ledger posts fils, so a KWD folio holds the order's exact amount (ends A7). */
    public function test_a7_kwd_folio_charge_statement_and_settlement_keep_fils(): void
    {
        $store = $this->storeFixture(['currency_code' => 'KWD']);
        $room = $this->room($store);
        $item = $this->menuItem($store, 12.345);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $order = $this->placeChargeOrder($store, $room, $stay, $item);

        $this->assertEqualsWithDelta(12.345, (float) $order->amount_due, 0.0000001);
        $this->assertEqualsWithDelta($this->ba(12.35, 12.345), $this->folioTotal($stay->id), 0.0000001);
        $this->assertEqualsWithDelta($this->ba(12.35, 12.345), HotelFolioService::balance($stay), 0.0000001);
        $statement = HotelFolioService::statement($stay);
        $this->assertEqualsWithDelta($this->ba(12.35, 12.345), $statement['charges'][0]['amount'], 0.0000001);
        $this->assertEqualsWithDelta($this->ba(12.35, 12.345), $statement['total_charges'], 0.0000001);

        // Paying the exact order amount clears the folio.
        HotelFolioService::settle($stay, 12.345, 'cash', 'k1');
        $this->assertEqualsWithDelta(0.0, HotelFolioService::balance($stay), 0.0000001);
    }

    // ------------------------------------------------------------------ A9

    public function test_a9_legacy_whatsapp_total_includes_the_tray_charge(): void
    {
        $store = $this->storeFixture([
            'hotel_tray_charge' => '5',
            'whatsapp_ordering_enable' => '1',
            'whatsapp_number' => '15551230000',
        ]);
        $room = $this->room($store);
        $item = $this->menuItem($store, 20.0);

        $payload = $this->payload($item, $room);
        unset($payload['tender']); // plain room-service order (no charge-to-room)
        $res = $this->send($store, $payload)->assertOk()->assertJson(['success' => true]);

        $waUrl = $res->json('whatsapp_url');
        $this->assertNotEmpty($waUrl, 'a WhatsApp handoff URL should be produced');
        // The {ORDER_TOTAL} must be the tray-inclusive total (20 + 5 = 25), not 20.
        $this->assertStringContainsString((string) price_code_format(25.0), urldecode($waUrl),
            'the WhatsApp order total must include the hotel tray charge');
    }

    public function test_a9_transfer_of_a_charge_to_room_order_is_blocked(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 30.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $order = $this->placeChargeOrder($store, $room, $stay, $item);

        $staff = StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'Mgr', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('staffapp.transfer', $order->id), ['table_number' => '7'])
            ->assertStatus(422);

        // The order is untouched — still a room-service order on the folio.
        $this->assertSame('room_service', $order->fresh()->type);
        $this->assertEqualsWithDelta(30.0, $this->folioTotal($stay->id), 0.001);
    }

    public function test_a9_floor_excludes_rooms_and_room_service_orders(): void
    {
        $store = $this->storeFixture();
        // A dine-in table and a hotel room that share the number 5.
        $table = StoreTable::create([
            'store_id' => $store->id, 'table_no' => 5, 'key' => Str::random(10), 'seats' => 4,
        ]);
        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => 5, 'key' => Str::random(10), 'seats' => 2,
            'area_type' => 'room', 'label' => 'Room 5',
        ]);
        // A live room-service order on room 5 (table_number = 5).
        Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'preparing',
            'table_number' => 5, 'service_location' => 'Room 5',
        ]);

        $staff = StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'Mgr', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);

        $tables = $this->actingAs($staff, 'staff')->getJson(route('staffapp.floor'))
            ->assertOk()->json('tables');

        // The room row must NOT appear in the floor grid; the dine-in table 5 must.
        $numbers = array_column($tables, 'table_no');
        $this->assertContains(5, $numbers);
        $this->assertCount(1, $tables, 'only the dine-in table appears (the room is excluded)');

        // The dine-in table 5 must NOT be lit by the room-service order.
        $this->assertSame('free', $tables[0]['status'],
            'a room-service order must not occupy the same-numbered dine-in table');
    }

    // ------------------------------------------------------------------ A10

    public function test_a10_unified_pipeline_persists_the_order_locale_like_legacy(): void
    {
        app()->setLocale('fr');
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 20.0);

        // Legacy pipeline (default): captures the locale.
        $payload = $this->payload($item, $room);
        unset($payload['tender']);
        $this->send($store, $payload)->assertOk();
        $legacyLocale = Order::where('store_id', $store->id)->latest('id')->first()->locale;
        $this->assertNotNull($legacyLocale, 'legacy path must stamp the order locale');

        // Unified pipeline: must persist the SAME locale (it previously dropped it).
        config(['settings.storefront_order_pipeline' => 'unified']);
        $this->send($store, $payload)->assertOk();
        $unifiedOrder = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertSame($legacyLocale, $unifiedOrder->locale,
            'the unified pipeline must persist orders.locale, not drop it');
    }

    public function test_a10_null_branch_rooms_are_resolvable_on_a_multi_branch_store(): void
    {
        $store = $this->storeFixture();
        $branch = Branch::create(['store_id' => $store->id, 'name' => 'Main']);
        // Room created under the "All branches" view carries a NULL branch_id.
        $room = $this->room($store);
        $this->assertNull($room->branch_id);
        $item = $this->menuItem($store, 20.0);

        // The storefront posts the formal branch_id; the room must still resolve.
        $payload = $this->payload($item, $room, ['branch_id' => $branch->id]);
        unset($payload['tender']);

        $this->send($store, $payload)->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($order, 'a NULL-branch room must resolve for a branch-scoped order');
        $this->assertSame('room_service', $order->type);
        $this->assertSame('Suite 101', $order->service_location);
    }

    public function test_a10_folio_post_failure_alerts_the_owner_and_reports_failure(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 30.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        // Check the guest out so a later folio post cannot land (chargeOrder refreshes
        // the stay and refuses a non-checked-in stay).
        HotelFolioService::checkOut($stay);

        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Suite 101',
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'unit_price' => 30.0]);
        $order->load('items.menu');

        $res = HotelFolioService::chargeOrderForTender($order, $stay);

        // The order stands, the guest is told to pay at reception, and the owner is alerted.
        $this->assertFalse($res['charged_to_room']);
        $this->assertStringContainsString('reception', strtolower($res['room_folio_message']));
        $this->assertTrue(\Juwri\Core\Models\Notification::where('type', 'hotel_folio')->exists(),
            'a folio-post failure must raise an owner notification');
        $this->assertEqualsWithDelta(0.0, $this->folioTotal($stay->id), 0.001, 'no charge lands on the closed folio');
    }

    // ------------------------------------------------------------------ A11

    public function test_a11_token_is_hidden_from_serialization(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        $this->assertNotNull($stay->token, 'the stay still HAS a token internally');
        $this->assertArrayNotHasKey('token', $stay->toArray(), 'token must never serialize into a payload');
        $this->assertStringNotContainsString($stay->token, $stay->toJson());
    }

    public function test_a11_updating_the_guest_name_re_enables_charge_to_room_match(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        // Front-desk typo at check-in.
        $stay = HotelFolioService::checkIn($store->id, $room, 'Smi', '555-1');
        $this->assertFalse(HotelFolioService::guestNameMatches($stay, 'Jane Smith'));

        $owner = User::find($store->user_id);
        $this->actingAs($owner)
            ->postJson(route('hotel.folio.stay.update', $stay->id), ['guest_name' => 'Jane Smith', 'guest_phone' => '555-9'])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertTrue(HotelFolioService::guestNameMatches($stay->fresh(), 'Jane Smith'),
            'the corrected name must now authorize charge-to-room');
    }

    public function test_a11_stay_cancel_is_guarded_by_a_zero_balance_and_frees_the_room(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);
        $item = $this->menuItem($store, 20.0);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $this->placeChargeOrder($store, $room, $stay, $item);
        $owner = User::find($store->user_id);

        // Balance outstanding → cancel refused.
        $this->actingAs($owner)
            ->postJson(route('hotel.folio.stay.cancel', $stay->id))
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame('checked_in', $stay->fresh()->status);

        // Settle, then cancel succeeds and frees the room.
        HotelFolioService::settle($stay, 20.0, 'cash');
        $this->actingAs($owner)
            ->postJson(route('hotel.folio.stay.cancel', $stay->id))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame('cancelled', $stay->fresh()->status);
        $this->assertNull(HotelFolioService::activeStayForRoom($store->id, $room->id),
            'a cancelled stay frees the room for a new check-in');
    }

    public function test_a11_prune_anonymizes_only_old_closed_stays_and_is_idempotent(): void
    {
        $store = $this->storeFixture();
        $room = $this->room($store);

        $old = HotelStay::create([
            'store_id' => $store->id, 'table_id' => $room->id, 'guest_name' => 'Old Guest',
            'guest_phone' => '555-OLD', 'token' => Str::random(48), 'status' => 'checked_out',
            'checked_in_at' => now()->subDays(210), 'checked_out_at' => now()->subDays(200),
        ]);
        $recent = HotelStay::create([
            'store_id' => $store->id, 'table_id' => $room->id, 'guest_name' => 'Recent Guest',
            'guest_phone' => '555-NEW', 'token' => Str::random(48), 'status' => 'checked_out',
            'checked_in_at' => now()->subDays(12), 'checked_out_at' => now()->subDays(10),
        ]);
        $active = HotelFolioService::checkIn($store->id, $room, 'Active Guest', '555-ACT');

        $this->artisan('hotel:prune-stays')->assertExitCode(0);

        // Old closed stay: anonymized.
        $old->refresh();
        $this->assertSame('Guest', $old->guest_name);
        $this->assertNull($old->guest_phone);
        $this->assertNull($old->token);

        // Recent + active stays: untouched.
        $this->assertSame('Recent Guest', $recent->fresh()->guest_name);
        $this->assertNotNull($recent->fresh()->token);
        $this->assertSame('Active Guest', $active->fresh()->guest_name);

        // Idempotent: a re-run anonymizes nothing new (still 'Guest', already null).
        $this->artisan('hotel:prune-stays')->assertExitCode(0);
        $this->assertSame('Guest', $old->fresh()->guest_name);
    }
}
