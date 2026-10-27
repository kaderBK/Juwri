<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\GiftCard;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class GiftCardRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'gift_cards_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    /** Fund a live card with $value. */
    private function card(Store $r, float $value): GiftCard
    {
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($r->id, $value, 'stripe');
        GiftCardService::activate($card, $txn);

        return $card->fresh();
    }

    private function order(User $owner, Store $r, Menu $menu, int $qty, ?string $code = null): Order
    {
        return OrderApiService::create($owner->id, $r->id, [
            'type' => 'takeaway', 'source' => 'api',
            'gift_card_code' => $code,
            'items' => [['item_id' => $menu->id, 'quantity' => $qty]],
        ]);
    }

    public function test_partial_redemption_leaves_remaining_balance(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);

        $order = $this->order($o, $r, $m, 2, $card->code); // total 20
        $order->refresh();

        $this->assertEqualsWithDelta(20.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(0.0, $order->amount_due, 0.001);
        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001);
    }

    public function test_redemption_capped_at_balance(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 10);

        $order = $this->order($o, $r, $m, 10, $card->code); // total 100
        $order->refresh();

        $this->assertEqualsWithDelta(10.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(90.0, $order->amount_due, 0.001); // still owed
        $this->assertEqualsWithDelta(0.0, $card->fresh()->balance(), 0.001);
    }

    public function test_cross_restaurant_card_is_rejected(): void
    {
        ['owner' => $o1, 'r' => $r1, 'menu' => $m1] = $this->make();
        ['r' => $r2] = $this->make();
        $foreign = $this->card($r2, 50);

        // Foreign code resolves to null for r1 → applies nothing.
        $order = $this->order($o1, $r1, $m1, 2, $foreign->code);
        $order->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(50.0, $foreign->fresh()->balance(), 0.001);
    }

    public function test_apply_to_order_is_idempotent_no_double_spend(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);
        $order = $this->order($o, $r, $m, 2, null); // total 20, no card yet

        $first = GiftCardService::applyToOrder($card, $order->fresh());
        $second = GiftCardService::applyToOrder($card->fresh(), $order->fresh());

        $this->assertEqualsWithDelta(20.0, $first, 0.001);
        $this->assertEqualsWithDelta(0.0, $second, 0.001); // no double spend
        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001);
    }

    public function test_a_card_that_became_unredeemable_applies_nothing_instead_of_throwing(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);
        $order = $this->order($o, $r, $m, 2, null); // total 20, no card yet

        GiftCardService::void($card); // drained/voided after the (unlocked) findRedeemable pre-check

        // Must degrade like the wallet tender — return 0.0, NOT throw (a throw here rolls
        // back the whole order creation in OrderPricing).
        $applied = GiftCardService::applyToOrder($card->fresh(), $order->fresh());
        $this->assertEqualsWithDelta(0.0, $applied, 0.001);
    }

    public function test_cross_currency_card_is_not_applied(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50); // currency USD (store currency at issue)
        $order = $this->order($o, $r, $m, 2, null); // total 20
        $order->currency_code = 'EUR'; // order stamped in a different currency
        $order->save();

        // A USD card must not wipe an EUR order 1:1 — apply nothing.
        $applied = GiftCardService::applyToOrder($card->fresh(), $order->fresh());
        $this->assertEqualsWithDelta(0.0, $applied, 0.001);
        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001, 'card balance untouched');
    }

    public function test_reverse_redemption_recredits_the_card_and_clears_the_tender(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);
        $order = $this->order($o, $r, $m, 2, $card->code); // total 20, card pays it
        $order->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001);

        $back = GiftCardService::reverseRedemption($order->fresh());

        $this->assertEqualsWithDelta(20.0, $back, 0.001);
        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001, 'card fully re-credited');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->gift_card_applied, 0.001, 'tender cleared');
    }

    public function test_reverse_redemption_is_idempotent_no_double_credit(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);
        $order = $this->order($o, $r, $m, 2, $card->code);

        $first = GiftCardService::reverseRedemption($order->fresh());
        $second = GiftCardService::reverseRedemption($order->fresh());

        $this->assertEqualsWithDelta(20.0, $first, 0.001);
        $this->assertEqualsWithDelta(0.0, $second, 0.001, 'second reverse is a no-op');
        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001, 'balance not credited twice');
    }

    public function test_reverse_redemption_is_a_noop_without_a_gift_card_tender(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $order = $this->order($o, $r, $m, 2, null); // no card applied

        $this->assertEqualsWithDelta(0.0, GiftCardService::reverseRedemption($order->fresh()), 0.001);
    }

    public function test_pos_void_recredits_a_gift_card_paid_order(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 50);
        $order = $this->order($o, $r, $m, 2, $card->code); // card pays the $20 order
        $this->assertEqualsWithDelta(30.0, $card->fresh()->balance(), 0.001);

        PosService::void($order->fresh(), null, 'test void');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001, 'void restored the card value');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->gift_card_applied, 0.001);
    }

    public function test_full_cash_refund_restores_the_gift_card_share(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 12);                          // funds $12
        $order = $this->order($o, $r, $m, 2, $card->code);    // total 20 → $12 card + $8 cash
        $order->refresh();
        $this->assertEqualsWithDelta(12.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(8.0, $order->amount_due, 0.001, 'cash collected');
        $this->assertEqualsWithDelta(0.0, $card->fresh()->balance(), 0.001, 'card drained by the tender');
        $order->is_paid = 1;
        $order->save();

        // Refund the entire collected cash ($8) → the order is fully refunded, so the
        // $12 gift-card tender comes back too (previously stranded).
        PosService::refund($order->fresh(), null, 8.0, 'cash', 'full refund');

        $this->assertEqualsWithDelta(12.0, $card->fresh()->balance(), 0.001, 'gift-card share returned on full refund');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->gift_card_applied, 0.001, 'tender cleared');
    }

    public function test_partial_cash_refund_leaves_the_gift_card_untouched(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 12);
        $order = $this->order($o, $r, $m, 2, $card->code);    // $12 card + $8 cash
        $order->is_paid = 1;
        $order->save();

        // Only half the collected cash — a partial refund returns cash only.
        PosService::refund($order->fresh(), null, 4.0, 'cash', 'partial');

        $this->assertEqualsWithDelta(12.0, (float) $order->fresh()->gift_card_applied, 0.001, 'still tendered');
        $this->assertEqualsWithDelta(0.0, $card->fresh()->balance(), 0.001, 'card not restored on a partial refund');
    }

    public function test_item_refund_restores_the_gift_card_when_fully_refunded(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        $card = $this->card($r, 12);
        $order = $this->order($o, $r, $m, 2, $card->code);    // $12 card + $8 cash
        $order->is_paid = 1;
        $order->save();
        $itemId = (int) $order->fresh()->items->first()->id;

        // Item-mode full refund of the collected cash → same tender restoration path.
        PosService::refundItems(
            $order->fresh(),
            [['order_item_id' => $itemId, 'qty' => 2, 'amount' => 8.0]],
            'full item refund',
            null,
            'cash',
            'idem-1'
        );

        $this->assertEqualsWithDelta(12.0, $card->fresh()->balance(), 0.001, 'gift-card share returned on full item refund');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->gift_card_applied, 0.001);
    }

    public function test_invalid_code_rejected_at_web_checkout(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();

        $payload = [
            'items' => json_encode([['id' => $m->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod', 'gift_card_code' => 'GC-NOPE',
        ];
        $this->post(route('store.submitOrder', $r), $payload)
            ->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }
}
