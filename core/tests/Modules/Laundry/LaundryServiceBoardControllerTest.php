<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Laundry;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Jobs\SendCustomerPushJob;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane D — D3 (service board) + D4 (weight-capture on
 * pickup), same shipped-together pattern Lane B used for B4+B5.
 *
 * D3: reuses RetailOrderBoardService's state machine (Lane B, unmodified)
 * through Lane D's OWN board page, relabeled received→processing→ready→
 * out_for_delivery→done (DECISIONS A9) via Juwri\Modules\Laundry\Services\Laundry\ServiceBoardVocab.
 * D4: the dashboard enters the actual weighed amount for a per-kg line,
 * which reprices it (order_items.unit_price = price_per_kg × weight), is
 * audited via log_activity(), and (best-effort) notifies the customer.
 */
class LaundryServiceBoardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function laundryOrder(string $type = 'pickup_slot'): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'laundry']);
        $order = Order::create(['store_id' => $store->id, 'type' => $type, 'status' => 'new', 'track_token' => 'tok-'.$store->id]);

        return [$owner, $store, $order];
    }

    public function test_board_shows_the_order_under_received_using_service_vocab(): void
    {
        [$owner, $store, $order] = $this->laundryOrder();

        $res = $this->actingAs($owner)->get(route('stores.service_board.index', ['store_id' => $store->id]));

        $res->assertOk();
        $res->assertSee('#'.$order->id);
        $res->assertSee('Received');
        $res->assertDontSeeHtml('>New<');
    }

    /**
     * P3-11 (F3): the board has a real "Out for delivery" column (`shipped`, orders brought back) and a real "Collected"
     * column (`collected`, orders the customer picks up) — each with its own word, so none is an always-empty twin.
     */
    public function test_board_has_an_out_for_delivery_column_and_a_collected_column(): void
    {
        [$owner, $store] = $this->laundryOrder();

        $html = $this->actingAs($owner)->get(route('stores.service_board.index', ['store_id' => $store->id]))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-state="collected"'));
        $this->assertSame(1, substr_count($html, 'data-state="shipped"'));
        $this->assertSame(1, substr_count($html, '>Out for delivery<'));
        $this->assertSame(1, substr_count($html, '>Collected<'));
    }

    /** F3: "shipped" was unreachable for a laundry order; a delivery order now reaches it, then done */
    public function test_a_laundry_delivery_order_reaches_shipped_then_done(): void
    {
        [$owner, $store, $order] = $this->laundryOrder('delivery');

        $advance = fn () => $this->actingAs($owner)->post(
            route('stores.service_board.advance', $order->id).'?store_id='.$store->id
        );

        foreach (['packing', 'ready', 'shipped', 'done'] as $expected) {
            $advance()->assertRedirect();
            $this->assertSame($expected, $order->fresh()->status);
        }
        $this->assertNotNull($order->fresh()->shipped_at, 'the shipped time is stamped');
        $this->assertNull($order->fresh()->collected_at);
    }

    /** a pickup order is collected by the customer: its terminal handoff stays `collected` (no `shipped_at`) */
    public function test_a_laundry_pickup_order_still_ends_collected(): void
    {
        [$owner, $store, $order] = $this->laundryOrder('pickup_slot');
        $order->update(['status' => 'ready']);

        $this->actingAs($owner)->post(route('stores.service_board.advance', $order->id).'?store_id='.$store->id)->assertRedirect();

        $this->assertSame('collected', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->collected_at);
        $this->assertNull($order->fresh()->shipped_at);
    }

    public function test_advancing_a_laundry_order_reads_received_processing_ready_out_for_delivery_done(): void
    {
        [$owner, $store, $order] = $this->laundryOrder('pickup_slot');

        $advance = fn () => $this->actingAs($owner)->post(
            route('stores.service_board.advance', $order->id).'?store_id='.$store->id
        );

        $advance()->assertRedirect();
        $order->refresh();
        $this->assertSame('packing', $order->status);

        // "customer sees status" (D3): the SAME public, vertical-agnostic
        // tracking endpoint every order type already uses reflects the new
        // status with zero Lane D code — RetailOrderBoardService::advance()
        // just writes orders.status, which this endpoint always reads live.
        $json = $this->getJson(route('store.orderTrack', $order->track_token));
        $json->assertOk()->assertJson(['status' => 'packing']);

        $advance();
        $order->refresh();
        $this->assertSame('ready', $order->status);

        $advance();
        $order->refresh();
        // a pickup order is collected by the customer (P3-11: a delivery order is shipped, see the test above).
        $this->assertSame('collected', $order->status);

        $advance();
        $order->refresh();
        $this->assertSame('done', $order->status);
    }

    public function test_a_laundry_owner_cannot_advance_another_stores_order(): void
    {
        [, , $order] = $this->laundryOrder();
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $intruderStore = Store::factory()->create(['user_id' => $intruder->id, 'store_type' => 'laundry']);

        $res = $this->actingAs($intruder)->post(
            route('stores.service_board.advance', $order->id).'?store_id='.$intruderStore->id
        );

        $res->assertNotFound();
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_capturing_actual_weight_reprices_the_line_and_the_order_total(): void
    {
        Queue::fake();
        [$owner, $store, $order] = $this->laundryOrder();
        $wash = Menu::create(['store_id' => $store->id, 'name' => 'Wash & fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);
        $item = OrderItem::create(['order_id' => $order->id, 'item_id' => $wash->id, 'quantity' => 1, 'weight_kg' => 2.0, 'unit_price' => 10.0]);
        $order->snapshotTotals();
        $this->assertEqualsWithDelta(10.0, (float) $order->fresh(['items'])->total, 0.001);

        $res = $this->actingAs($owner)->post(
            route('stores.service_board.weight', [$order->id, $item->id]).'?store_id='.$store->id,
            ['weight_kg' => 3.4]
        );

        $res->assertRedirect();

        $item->refresh();
        $this->assertEqualsWithDelta(3.4, (float) $item->weight_kg, 0.001);
        // 3.4kg × 5.00/kg = 17.00
        $this->assertEqualsWithDelta(17.0, (float) $item->unit_price, 0.001);
        $this->assertEqualsWithDelta(17.0, (float) $order->fresh(['items'])->total, 0.001);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.weight_captured',
            'store_id' => $store->id,
        ]);

        Queue::assertPushed(SendCustomerPushJob::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_capturing_weight_on_an_each_priced_line_is_rejected(): void
    {
        [$owner, $store, $order] = $this->laundryOrder();
        $shirt = Menu::create(['store_id' => $store->id, 'name' => 'Shirt — iron', 'price' => 3.5, 'pricing_unit' => 'each', 'active' => 1]);
        $item = OrderItem::create(['order_id' => $order->id, 'item_id' => $shirt->id, 'quantity' => 2]);

        $res = $this->actingAs($owner)->post(
            route('stores.service_board.weight', [$order->id, $item->id]).'?store_id='.$store->id,
            ['weight_kg' => 1.0]
        );

        $res->assertSessionHasErrors('weight_kg');
    }
}
