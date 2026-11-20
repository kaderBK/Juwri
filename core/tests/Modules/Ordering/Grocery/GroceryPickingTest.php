<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Grocery;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPicking;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPickingException;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Tests\Modules\Ordering\Grocery\Concerns\GroceryOrders;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-09 (basket §8–§10, API-22 / API-25 / API-30 / API-31, D7): the picker flow — per-line picker actions from the
 * shopper's out-of-stock choice, replacements never above the line, the shopper's answer on the tracking page (token
 * and line scoped), unanswered proposals following the order preference, call_customer with its no-answer fallback,
 * the up-front payment refunded per the D7 switch (removed lines always), N3 ready-to-collect, and the owner screens'
 * tenant isolation.
 */
class GroceryPickingTest extends TestCase
{
    use GroceryOrders;
    use RefreshDatabase;

    /** @var list<string> */
    private array $sms = [];

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->bootGrocery();
        StoreSettings::set((int) $this->store->id, 'customer_status_sms', '1');
        $this->mock(MessageQueue::class, function ($mock) {
            $mock->shouldReceive('sms')->andReturnUsing(function (string $to, string $message) {
                $this->sms[] = $message;
            });
            $mock->shouldIgnoreMissing();
        });
    }

    private function picking(): GroceryPicking
    {
        return app(GroceryPicking::class);
    }

    public function test_lines_carry_the_picker_action_of_their_choice_and_the_store_default_without_one(): void
    {
        $order = $this->placeGroceryOrder('pay_later', null, ['lines' => [
            ['line_id' => 'ln_1', 'item_id' => ApiFixtures::BANANAS, 'option_ids' => [], 'qty' => 1, 'substitution' => ApiFixtures::SUB_CALL],
            ['line_id' => 'ln_2', 'item_id' => ApiFixtures::TOMATOES, 'option_ids' => [ApiFixtures::TOMATO_STEPS[250]], 'qty' => 1],
        ]]);
        $this->assertSame(['call_customer', 'remove_item'], [$this->lineFor($order, ApiFixtures::BANANAS)->picker_action, $this->lineFor($order, ApiFixtures::BANANAS)->picker_fallback]);
        $this->assertSame('offer_replacement', $this->tomatoLine($order)->picker_action, 'no order preference: the store default (API-22)');
        $this->assertNotNull($order->substitution_option_id);
    }

    public function test_steps_need_picking_started_and_replacements_stay_under_the_line(): void
    {
        $order = $this->placeGroceryOrder('pay_later');
        $bananas = (int) $this->lineFor($order, ApiFixtures::BANANAS)->id;
        try {
            $this->picking()->pickAsOrdered($order, $bananas);
            $this->fail('not started');
        } catch (GroceryPickingException) {
        }
        $this->picking()->start($order);
        try {
            $this->picking()->propose($order, $bananas, 'Organic bananas', 1301);
            $this->fail('a replacement above the 13.00 line is refused');
        } catch (GroceryPickingException) {
        }
        try {
            $this->picking()->weigh($order, $bananas, 900);
            $this->fail('bananas are not weighed');
        } catch (GroceryPickingException) {
        }
        try {
            $this->picking()->finish($order);
            $this->fail('the tomatoes are not weighed yet');
        } catch (GroceryPickingException) {
        }
        $this->picking()->propose($order, $bananas, 'Organic bananas', 1250);
        $this->assertSame('proposed', $this->lineFor($order, ApiFixtures::BANANAS)->pick_status);
        $this->assertTrue(collect($this->sms)->contains(fn ($m) => str_contains($m, 'need your answer')), 'grocery.replacement_needed');
    }

    public function test_the_shopper_keeps_or_refuses_a_replacement_on_the_tracking_page_token_and_line_scoped(): void
    {
        $order = $this->placeGroceryOrder('pay_later');
        $other = $this->placeGroceryOrder('pay_later');
        $this->picking()->start($order);
        $bananas = (int) $this->lineFor($order, ApiFixtures::BANANAS)->id;
        $bread = (int) $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e')->id;
        $this->picking()->propose($order, $bananas, 'Organic bananas', 1200);
        $this->picking()->propose($order, $bread, 'Rye loaf', 1300);

        // another order's token cannot answer this order's line; an unknown token is a 404
        $this->postJson(route('store.orderReplacement', ['token' => $other->track_token]), ['item' => $bananas, 'answer' => 'kept'])->assertStatus(409);
        $this->postJson(route('store.orderReplacement', ['token' => 'trk_nope']), ['item' => $bananas, 'answer' => 'kept'])->assertNotFound();
        $this->assertSame('proposed', $this->lineFor($order, ApiFixtures::BANANAS)->pick_status);

        $this->postJson(route('store.orderReplacement', ['token' => $order->track_token]), ['item' => $bananas, 'answer' => 'kept'])->assertOk()->assertJsonPath('answer', 'kept');
        $this->postJson(route('store.orderReplacement', ['token' => $order->track_token]), ['item' => $bread, 'answer' => 'refused'])->assertOk();
        $this->postJson(route('store.orderReplacement', ['token' => $order->track_token]), ['item' => $bread, 'answer' => 'kept'])->assertStatus(409);
        $this->assertSame(['replaced', 1200], [$this->lineFor($order, ApiFixtures::BANANAS)->pick_status, (int) $this->lineFor($order, ApiFixtures::BANANAS)->final_minor]);
        $this->assertSame(['removed', 0], [$this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e')->pick_status, (int) $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e')->final_minor]);
    }

    public function test_unanswered_proposals_follow_the_order_preference_at_finish(): void
    {
        $keep = $this->placeGroceryOrder('pay_later', ApiFixtures::SUB_REPLACE);
        $this->picking()->start($keep);
        $this->picking()->propose($keep, (int) $this->lineFor($keep, ApiFixtures::BANANAS)->id, 'Organic bananas', 1200);
        $this->picking()->weigh($keep, (int) $this->tomatoLine($keep)->id, 500);
        $this->assertNull($this->picking()->finish($keep), 'pay later: collected on delivery');
        $this->assertSame('replaced', $this->lineFor($keep, ApiFixtures::BANANAS)->pick_status);
        $this->assertSame('picked', $keep->fresh()->picking_status);
        $this->assertSame(1200 + 590 + 1400 + 900, Money::minorFromMajor((float) $keep->fresh()->computeMoney()['grand'], 'SAR'));

        $remove = $this->placeGroceryOrder('pay_later', ApiFixtures::SUB_REMOVE);
        $this->picking()->start($remove);
        $this->picking()->propose($remove, (int) $this->lineFor($remove, ApiFixtures::BANANAS)->id, 'Organic bananas', 1200);
        $this->picking()->weigh($remove, (int) $this->tomatoLine($remove)->id, 500);
        $this->picking()->finish($remove);
        $this->assertSame('removed', $this->lineFor($remove, ApiFixtures::BANANAS)->pick_status);
    }

    public function test_call_customer_without_an_answer_uses_the_option_fallback_api_30(): void
    {
        $order = $this->placeGroceryOrder('pay_later', ApiFixtures::SUB_CALL);
        $this->picking()->start($order);
        $bananas = (int) $this->lineFor($order, ApiFixtures::BANANAS)->id;
        $this->assertSame('removed', $this->picking()->callOutcome($order, $bananas, 'no_answer'));
        $bread = (int) $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e')->id;
        $this->assertSame('replaced', $this->picking()->callOutcome($order, $bread, 'replace', 'Rye loaf', 1300));
        $this->assertSame(1300, (int) $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e')->final_minor);
    }

    /** D7 on: a basket paid up front (no card hold gateway) gets the weighed difference back after picking. */
    public function test_paid_up_front_refunds_the_weighed_difference_only_with_the_switch_on_and_removed_lines_always(): void
    {
        StoreSettings::set((int) $this->store->id, 'refunds_weighed_difference', true);
        $on = $this->placeGroceryOrder();
        $this->payUpFront($on);
        $this->picking()->start($on);
        $this->picking()->weigh($on, (int) $this->tomatoLine($on)->id, 400); // 4.72
        $result = $this->picking()->finish($on);
        $this->assertSame(['refunded', 4249 - (1300 + 472 + 1400 + 900)], [$result['outcome'], $result['refunded_minor']]);
        $this->assertTrue(collect($this->sms)->contains(fn ($m) => str_contains($m, 'We refunded SAR 1.77')));

        StoreSettings::set((int) $this->store->id, 'refunds_weighed_difference', false);
        $off = $this->placeGroceryOrder();
        $this->payUpFront($off);
        $this->picking()->start($off);
        $this->picking()->weigh($off, (int) $this->tomatoLine($off)->id, 400);
        $this->picking()->remove($off, (int) $this->lineFor($off, ApiFixtures::BANANAS)->id);
        $result = $this->picking()->finish($off);
        $this->assertSame(['refunded', 1300], [$result['outcome'], $result['refunded_minor']], 'switch off: only the removed line (API-31)');
        $this->assertSame(1, OrderAdjustment::query()->where('order_id', $off->id)->count());
    }

    /** grocery-snap: charged up front, the weighed difference refunded — the snapshot is the picked total, and the refund row is not subtracted twice. */
    public function test_paid_up_front_snapshot_follows_the_pick_and_the_refund_cap_counts_the_picking_refund_once(): void
    {
        StoreSettings::set((int) $this->store->id, 'refunds_weighed_difference', true);
        $order = $this->placeGroceryOrder();
        $this->payUpFront($order);
        $this->picking()->start($order);
        $this->picking()->weigh($order, (int) $this->tomatoLine($order)->id, 400);
        $result = $this->picking()->finish($order);
        $this->assertSame(['refunded', 177], [$result['outcome'], $result['refunded_minor']]);
        $this->assertSame(4072, (int) $order->fresh()->grand_total_minor);

        $owner = User::query()->findOrFail($this->store->user_id);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 40.73])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 40.72])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 0.01])->assertStatus(422);
    }

    /** grocery-snap: every line removed from a basket charged up front — the delivery fee left on the snapshot is not refundable. */
    public function test_paid_up_front_all_removed_leaves_nothing_to_refund(): void
    {
        $order = $this->placeGroceryOrder();
        $this->payUpFront($order);
        $this->picking()->start($order);
        foreach ($order->items()->get() as $line) {
            $this->picking()->remove($order, (int) $line->id);
        }
        $result = $this->picking()->finish($order);
        $this->assertSame(['refunded', 4249], [$result['outcome'], $result['refunded_minor']]);

        $owner = User::query()->findOrFail($this->store->user_id);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 0.01])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 0.01])->assertStatus(422);
    }

    /** grocery-snap2 MUST: switch off, a cheaper replacement kept — the store kept the difference, so the owner can still refund the full charge. */
    public function test_paid_up_front_with_the_difference_kept_the_full_charge_stays_refundable(): void
    {
        $order = $this->placeGroceryOrder();
        $this->payUpFront($order);
        $this->picking()->start($order);
        $bananas = (int) $this->lineFor($order, ApiFixtures::BANANAS)->id;
        $this->picking()->propose($order, $bananas, 'Organic bananas', 1200, null, false);
        $this->picking()->answer($order, $bananas, 'kept');
        $this->picking()->weigh($order, (int) $this->tomatoLine($order)->id, 500);
        $result = $this->picking()->finish($order);
        $this->assertSame(['none', 0], [$result['outcome'], $result['refunded_minor']], 'switch off: the difference is kept');
        $this->assertSame(1200 + 590 + 1400 + 900, (int) $order->fresh()->grand_total_minor);

        $owner = User::query()->findOrFail($this->store->user_id);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 42.50])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 42.49])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 0.01])->assertStatus(422);
    }

    /** grocery-snap2 MUST + grocery-nits 4: every line removed from a basket paid partly by wallet — the card is refunded, the wallet part goes back to store credit by itself. */
    public function test_paid_up_front_all_removed_with_a_wallet_tender_returns_the_wallet_part_to_credit(): void
    {
        $order = $this->placeGroceryOrder();
        $this->payUpFront($order);
        $customer = Customer::create(['name' => 'Noura', 'store_id' => $this->store->id, 'phone' => '+966551230077']);
        $order->refresh()->forceFill(['wallet_applied' => 10.00, 'customer_id' => $order->customer_id ?: $customer->id])->saveQuietly();
        $this->picking()->start($order);
        foreach ($order->items()->get() as $line) {
            $this->picking()->remove($order, (int) $line->id);
        }
        $result = $this->picking()->finish($order);
        $this->assertSame(['refunded', 4249], [$result['outcome'], $result['refunded_minor']]);

        $owner = User::query()->findOrFail($this->store->user_id);
        $this->assertSame(10.0, CustomerWalletService::balanceFor(Customer::query()->findOrFail($order->fresh()->customer_id)), 'the wallet part is back');
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 0.01])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 0.01])->assertStatus(422);
    }

    /** grocery-nits 5: a tip, discount or type change after every line was removed never brings the delivery fee back. */
    public function test_all_removed_total_stays_zero_after_a_later_money_edit(): void
    {
        $order = $this->placeGroceryOrder('pay_later');
        $this->picking()->start($order);
        foreach ($order->items()->get() as $line) {
            $this->picking()->remove($order, (int) $line->id);
        }
        $this->picking()->finish($order);
        $order->refresh()->forceFill(['tip_amount_minor' => 500])->save();
        $order->refresh();
        $this->assertSame([0, 0, 0], [(int) $order->grand_total_minor, (int) $order->subtotal_minor, (int) $order->tax_total_minor]);
    }

    /** grocery-snap2 SHOULD 1: nothing delivered and nothing charged — the stored total is 0, not the delivery fee. */
    public function test_all_removed_stores_a_zero_total(): void
    {
        $order = $this->placeGroceryOrder();
        $this->payUpFront($order);
        $this->picking()->start($order);
        foreach ($order->items()->get() as $line) {
            $this->picking()->remove($order, (int) $line->id);
        }
        $this->picking()->finish($order);
        $order->refresh();
        $this->assertSame([0, 0, 0], [(int) $order->grand_total_minor, (int) $order->subtotal_minor, (int) $order->tax_total_minor]);
    }

    /** grocery-snap2 SHOULD 3: a register refund of a picked up-front order counts the picking refund once. */
    public function test_register_refund_of_a_picked_up_front_order_counts_the_picking_refund_once(): void
    {
        StoreSettings::set((int) $this->store->id, 'refunds_weighed_difference', true);
        $order = $this->placeGroceryOrder();
        $this->payUpFront($order);
        $this->picking()->start($order);
        $this->picking()->weigh($order, (int) $this->tomatoLine($order)->id, 400);
        $this->assertSame(177, $this->picking()->finish($order)['refunded_minor']);

        try {
            PosService::refund($order->fresh(), null, 40.73, 'card', 'test');
            $this->fail('above what is left of the charge');
        } catch (ValidationException) {
        }
        $this->assertSame(40.72, (float) PosService::refund($order->fresh(), null, 40.72, 'card', 'test')->amount);
    }

    /** grocery-snap2 SHOULD 3: the item-mode register refund uses the same collected base. */
    public function test_register_item_refund_of_a_picked_up_front_order_counts_the_picking_refund_once(): void
    {
        StoreSettings::set((int) $this->store->id, 'refunds_weighed_difference', true);
        $order = $this->placeGroceryOrder();
        $this->payUpFront($order);
        $this->picking()->start($order);
        $this->picking()->weigh($order, (int) $this->tomatoLine($order)->id, 400);
        $this->picking()->finish($order);

        $line = ['order_item_id' => (int) $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e')->id, 'qty' => 1];
        try {
            PosService::refundItems($order->fresh(), [$line + ['amount' => 40.73]], 'test', null, 'card');
            $this->fail('above what is left of the charge');
        } catch (ValidationException) {
        }
        $this->assertSame(40.72, (float) PosService::refundItems($order->fresh(), [$line + ['amount' => 40.72]], 'test', null, 'card')->amount);
    }

    public function test_ready_to_collect_message_for_a_slotted_collection_order_n3(): void
    {
        $order = $this->placeGroceryOrder('pay_later');
        Order::query()->whereKey($order->id)->update(['type' => 'pickup_slot']);
        $order->refresh()->forceFill(['status' => 'ready'])->save();
        $this->assertTrue(collect($this->sms)->contains(fn ($m) => str_contains($m, 'is ready to collect') && str_contains($m, 'Held until 12:00')), implode(' | ', $this->sms));
    }

    public function test_owner_picking_screens_are_tenant_scoped(): void
    {
        $order = $this->placeGroceryOrder('pay_later');
        $owner = User::query()->findOrFail($this->store->user_id);
        $stranger = $this->fx->owner();
        $this->fx->store($stranger, 'other-mart', 'Other Mart', 'grocery');
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->assertContains($this->actingAs($stranger)->get(route('stores.picking.show', $order->id))->getStatusCode(), [403, 404]);
        $this->assertContains($this->actingAs($stranger)->post(route('stores.picking.step', $order->id), ['step' => 'start'])->getStatusCode(), [403, 404]);
        $this->assertNull($order->fresh()->picking_status);

        $this->actingAs($owner)->get(route('stores.picking.index').'?store_id='.$this->store->id)->assertOk()->assertSee('data-picking-order="'.$order->id.'"', false);
        $this->actingAs($owner)->post(route('stores.picking.step', $order->id), ['step' => 'start'])->assertRedirect();
        $this->assertSame('picking', $order->fresh()->picking_status);
        // a line of another order is refused, not touched
        $foreign = $this->placeGroceryOrder('pay_later');
        $this->actingAs($owner)->post(route('stores.picking.step', $order->id), ['step' => 'remove', 'item' => $this->lineFor($foreign, ApiFixtures::BANANAS)->id])->assertSessionHas('error');
        $this->assertNull($this->lineFor($foreign, ApiFixtures::BANANAS)->pick_status);
        $this->actingAs($owner)->get(route('stores.picking.show', $order->id))->assertOk()->assertSee('data-picking="'.$order->id.'"', false);
        $this->actingAs($owner)->get(route('stores.grocery.settings').'?store_id='.$this->store->id)->assertOk()->assertSee('data-grocery-slots', false);
    }

    /** The store has no card-hold gateway but refunds the difference (D7): the hold amount is charged up front. */
    private function payUpFront(Order $order): void
    {
        $transaction = app(HostedOrderPayments::class)->transactionFor($order);
        $this->assertNotNull($transaction);
        $transaction->forceFill(['details' => array_merge((array) $transaction->details, ['capture' => null])])->save();
        Settlement::settle($transaction, 'stub', 'pay_'.$order->id, claim: true);
        $this->assertSame(1, (int) $order->fresh()->is_paid);
    }
}
