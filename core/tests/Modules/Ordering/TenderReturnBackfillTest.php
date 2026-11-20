<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\OrderCancellation;
use Juwri\Modules\Ordering\Services\Orders\TenderReturnBackfill;
use Juwri\Modules\Payments\Contracts\Ledger;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * ML-1120 review SHOULD 1: orders whose tenders were given back before `tenders_returned_minor` existed get the counter
 * filled from the reversal ledger refs / logs, once, so their returned tenders are never refundable again.
 */
class TenderReturnBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** a 100.00 order, 60% paid by store credit */
    private function storedValueOrder(): Order
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'b-'.rand(1000, 9999), 'color' => '#111', 'title' => 'B', 'sub_title' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'X', 'price' => 100, 'active' => '1']);
        $customer = Customer::create(['name' => 'C', 'store_id' => $store->id, 'phone' => UniquePhone::next('58-')]);
        $order = Order::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1, 'payment_method' => 'wallet']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);
        $order = $order->fresh();
        $order->forceFill(['wallet_applied' => round((float) $order->total * 0.6, 2)])->save();

        return $order->fresh();
    }

    public function test_orders_cancelled_before_the_counters_get_their_returned_tenders_once(): void
    {
        $order = $this->storedValueOrder();
        $tenderMinor = Money::minorFromMajor((float) $order->wallet_applied, $order->minorMoneyCurrency());
        OrderCancellation::reverseTenders($order);
        // as on an order cancelled before the migration: the tender is gone and nothing was recorded
        $order->fresh()->forceFill(['tenders_returned_minor' => 0, 'tenders_kept_minor' => 0])->save();

        // a dead gift card paid out by an earlier cancel of another order; a grocery tender-excess payout is not a return
        $other = $this->storedValueOrder();
        $other->forceFill(['wallet_applied' => 0])->save();
        ActivityLog::create(['action' => 'giftcard.refund_started', 'store_id' => $other->store_id, 'subject_type' => $other->getMorphClass(), 'subject_id' => $other->id,
            'meta' => ['order_id' => $other->id, 'gift_card_id' => 9, 'amount' => 12.5, 'card_status' => 'voided']]);
        ActivityLog::create(['action' => 'giftcard.refund_started', 'store_id' => $other->store_id, 'subject_type' => $other->getMorphClass(), 'subject_id' => $other->id,
            'meta' => ['order_id' => $other->id, 'gift_card_id' => 9, 'amount' => 3.0, 'reason' => 'tender_excess', 'card_status' => 'voided']]);

        $untouched = $this->storedValueOrder(); // never cancelled

        $this->assertSame(2, TenderReturnBackfill::run());
        $this->assertSame($tenderMinor, (int) $order->fresh()->getAttribute('tenders_returned_minor'));
        $this->assertSame(Money::minorFromMajor(12.5, $other->minorMoneyCurrency()), (int) $other->fresh()->getAttribute('tenders_returned_minor'));
        $this->assertSame(0, (int) $untouched->fresh()->getAttribute('tenders_returned_minor'));

        $this->assertSame(0, TenderReturnBackfill::run(), 'idempotent');
        $this->assertSame($tenderMinor, (int) $order->fresh()->getAttribute('tenders_returned_minor'));
    }

    /** ML-1120 delta NIT 1: a returned part in a currency other than the order's is skipped (logged), never converted */
    public function test_a_returned_part_in_another_currency_is_skipped(): void
    {
        $order = $this->storedValueOrder();
        $this->assertNotSame('EUR', $order->minorMoneyCurrency());
        OrderCancellation::reverseTenders($order);
        $order->fresh()->forceFill(['tenders_returned_minor' => 0, 'tenders_kept_minor' => 0])->save();
        $this->assertSame(1, DB::table('ledger_entries')->where('ref', 'cw:tender-reversal:order:'.$order->id.':cust')->update(['currency_code' => 'EUR']));
        Log::spy();

        $this->assertSame(0, TenderReturnBackfill::run());
        $this->assertSame(0, (int) $order->fresh()->getAttribute('tenders_returned_minor'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, "order {$order->id}") && str_contains((string) $message, 'EUR'))->once();
    }

    /**
     * ML-1120 delta NIT 4: the dead-card part comes from its `giftcard.refund_started` claim (written with the tender
     * zeroing). When that log row is gone (an account or privacy deletion) the payout's `…:out:from` ledger leg is used
     * instead; an order that still has its claim row is never counted from both.
     */
    public function test_a_dead_card_payout_without_its_log_row_is_read_from_the_ledger_leg(): void
    {
        $order = $this->storedValueOrder();
        $order->forceFill(['wallet_applied' => 0])->save();
        $currency = $order->minorMoneyCurrency();
        $ownerId = (int) Store::whereKey($order->store_id)->value('user_id');
        $ledger = app(Ledger::class);
        $post = fn (Order $o, float $major) => $ledger->post([
            ['account' => $ledger->accountFor('merchant', $ownerId, 'merchant', $currency), 'amount' => -$major, 'allow_negative' => true, 'ref' => 'gc:refund:order:'.$o->id.':card:7:out:from'],
            ['account' => $ledger->accountFor('platform', 0, 'platform', $currency), 'amount' => $major, 'allow_negative' => true, 'ref' => 'gc:refund:order:'.$o->id.':card:7:out:to'],
        ], null, 'gift_card.refund_out');
        $post($order, 8.0); // its refund_started row was deleted

        $logged = $this->storedValueOrder();
        $logged->forceFill(['wallet_applied' => 0])->save();
        ActivityLog::create(['action' => 'giftcard.refund_started', 'store_id' => $logged->store_id, 'subject_type' => $logged->getMorphClass(), 'subject_id' => $logged->id,
            'meta' => ['order_id' => $logged->id, 'gift_card_id' => 7, 'amount' => 10.0, 'card_status' => 'voided']]);
        $post($logged, 6.0); // the payout was capped lower; the claim (what left the order) wins

        $this->assertSame(2, TenderReturnBackfill::run());
        $this->assertSame(Money::minorFromMajor(8.0, $currency), (int) $order->fresh()->getAttribute('tenders_returned_minor'));
        $this->assertSame(Money::minorFromMajor(10.0, $currency), (int) $logged->fresh()->getAttribute('tenders_returned_minor'));
    }

    public function test_an_order_whose_counters_are_already_set_is_left_alone(): void
    {
        $order = $this->storedValueOrder();
        OrderCancellation::reverseTenders($order);
        $recorded = (int) $order->fresh()->getAttribute('tenders_returned_minor');
        $this->assertGreaterThan(0, $recorded);

        $this->assertSame(0, TenderReturnBackfill::run());
        $this->assertSame($recorded, (int) $order->fresh()->getAttribute('tenders_returned_minor'));
    }
}
