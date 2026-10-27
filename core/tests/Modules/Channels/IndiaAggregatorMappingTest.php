<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use App\Models\Order;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyPayloadMapper;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyProvider;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoPayloadMapper;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARITY-RUN T7 — the pure mapping layer, with no database.
 *
 * Every field name these mappers read is ASSUMED until partner certification, so
 * what is pinned here is the mapper's TOLERANCE (a payload may be nested or flat,
 * a name may have two spellings) and the status matrices — the two things that
 * must not regress when certification rewrites the field names.
 */
class IndiaAggregatorMappingTest extends TestCase
{
    /* --------------------------------------------------------------- Zomato */

    public function test_zomato_reads_the_outlet_id_under_any_of_its_spellings(): void
    {
        $this->assertSame('A', ZomatoPayloadMapper::storeId(['res_id' => 'A']));
        $this->assertSame('B', ZomatoPayloadMapper::storeId(['restaurant_id' => 'B']));
        $this->assertSame('C', ZomatoPayloadMapper::storeId(['outlet_id' => 'C']));
        $this->assertSame('D', ZomatoPayloadMapper::storeId(['restaurant' => ['res_id' => 'D']]));
        $this->assertNull(ZomatoPayloadMapper::storeId([]));
        // Numeric ids must survive as strings — external_store_id is a varchar.
        $this->assertSame('90210', ZomatoPayloadMapper::storeId(['res_id' => 90210]));
    }

    public function test_zomato_reads_the_order_id_nested_or_flat(): void
    {
        $this->assertSame('O1', ZomatoPayloadMapper::orderId(['order' => ['order_id' => 'O1']]));
        $this->assertSame('O2', ZomatoPayloadMapper::orderId(['order_id' => 'O2']));
        $this->assertSame('O3', ZomatoPayloadMapper::orderId(['id' => 'O3']));
        $this->assertNull(ZomatoPayloadMapper::orderId([]));
        // An empty string is NOT an id — it would break idempotency.
        $this->assertNull(ZomatoPayloadMapper::orderId(['order_id' => '']));
    }

    public function test_zomato_detects_cancellation_events(): void
    {
        $this->assertFalse(ZomatoPayloadMapper::isCancellation(['event' => 'order.placed']));
        $this->assertTrue(ZomatoPayloadMapper::isCancellation(['event' => 'order.cancelled']));
        $this->assertTrue(ZomatoPayloadMapper::isCancellation(['event' => 'ORDER_CANCELED']));
        // No event at all defaults to a placement, never to a destructive action.
        $this->assertFalse(ZomatoPayloadMapper::isCancellation([]));
    }

    public function test_zomato_flattens_a_structured_address(): void
    {
        $address = ZomatoPayloadMapper::address([
            'order' => ['customer' => ['address' => ['line1' => '12 MG Road', 'city' => 'Bengaluru']]],
        ]);

        $this->assertSame('12 MG Road, Bengaluru', $address);
        $this->assertNull(ZomatoPayloadMapper::address([]));
    }

    public function test_zomato_line_accessors_are_defensive(): void
    {
        $this->assertSame('zom_9', ZomatoPayloadMapper::lineCode(['dish_id' => 'zom_9']));
        $this->assertSame('', ZomatoPayloadMapper::lineCode([]));
        // Quantity never drops below 1 — a 0-qty line would silently vanish.
        $this->assertSame(1, ZomatoPayloadMapper::lineQty(['quantity' => 0]));
        $this->assertSame(4, ZomatoPayloadMapper::lineQty(['qty' => 4]));
        // With no name, the code is the fallback label so an unmapped line is
        // still identifiable in the order note.
        $this->assertSame('zom_9', ZomatoPayloadMapper::lineName(['dish_id' => 'zom_9']));
    }

    public function test_zomato_status_matrix(): void
    {
        $provider = new ZomatoProvider();

        $this->assertSame('FOOD_PREPARATION', $provider->statusCodeFor($this->orderWith('preparing')));
        $this->assertSame('READY_FOR_PICKUP', $provider->statusCodeFor($this->orderWith('ready')));
        $this->assertSame('DELIVERED', $provider->statusCodeFor($this->orderWith('completed')));
        $this->assertSame('CANCELLED', $provider->statusCodeFor($this->orderWith('cancelled')));
        // An unknown local status is NOT pushed as something plausible.
        $this->assertNull($provider->statusCodeFor($this->orderWith('held')));
        $this->assertNull($provider->statusCodeFor($this->orderWith('')));
    }

    /* --------------------------------------------------------------- Swiggy */

    public function test_swiggy_reads_the_outlet_and_order_ids(): void
    {
        $this->assertSame('S1', SwiggyPayloadMapper::storeId(['restaurant_id' => 'S1']));
        $this->assertSame('S2', SwiggyPayloadMapper::storeId(['outlet_id' => 'S2']));
        $this->assertSame('N1', SwiggyPayloadMapper::orderId(['order' => ['order_id' => 'N1']]));
        $this->assertNull(SwiggyPayloadMapper::storeId([]));
    }

    public function test_swiggy_reads_the_commission_from_the_charges_block(): void
    {
        $this->assertEqualsWithDelta(
            12.5,
            SwiggyPayloadMapper::channelFee(['order' => ['charges' => ['commission' => 12.5]]]),
            0.001
        );
        // …and tolerates the flat spelling.
        $this->assertEqualsWithDelta(7.0, SwiggyPayloadMapper::channelFee(['commission' => 7]), 0.001);
        $this->assertEqualsWithDelta(0.0, SwiggyPayloadMapper::channelFee([]), 0.001);
    }

    public function test_swiggy_detects_cancellation_events(): void
    {
        $this->assertFalse(SwiggyPayloadMapper::isCancellation(['event_type' => 'NEW_ORDER']));
        $this->assertTrue(SwiggyPayloadMapper::isCancellation(['event_type' => 'ORDER_CANCELLED']));
        $this->assertFalse(SwiggyPayloadMapper::isCancellation([]));
    }

    public function test_swiggy_status_matrix(): void
    {
        $provider = new SwiggyProvider();

        $this->assertSame('ORDER_CONFIRMED', $provider->statusCodeFor($this->orderWith('new')));
        $this->assertSame('FOOD_PREPARED', $provider->statusCodeFor($this->orderWith('preparing')));
        $this->assertSame('ORDER_PICKED_UP', $provider->statusCodeFor($this->orderWith('out_for_delivery')));
        $this->assertSame('ORDER_DELIVERED', $provider->statusCodeFor($this->orderWith('delivered')));
        $this->assertNull($provider->statusCodeFor($this->orderWith('nonsense')));
    }

    /**
     * The two matrices must not accidentally converge: they are different
     * partners with different vocabularies, and copying one into the other is
     * the likeliest maintenance mistake.
     */
    public function test_the_two_status_vocabularies_are_distinct(): void
    {
        $this->assertNotSame(ZomatoProvider::STATUS_MATRIX, SwiggyProvider::STATUS_MATRIX);
        $this->assertSame(
            array_keys(ZomatoProvider::STATUS_MATRIX),
            array_keys(SwiggyProvider::STATUS_MATRIX),
            'Both drivers must cover the SAME set of local statuses.'
        );
    }

    /* ---------------------------------------------------------------- codes */

    public function test_item_codes_are_namespaced_per_provider(): void
    {
        $this->assertSame('zom_7', ZomatoProvider::itemCode(7));
        $this->assertSame('swg_7', SwiggyProvider::itemCode(7));
        $this->assertSame('zom_7', IndiaAggregatorSync::itemCode('zomato', 7));
        $this->assertSame('swg_7', IndiaAggregatorSync::itemCode('swiggy', 7));
    }

    public function test_provider_registry_helpers(): void
    {
        $this->assertTrue(IndiaAggregatorSync::isIndiaProvider('zomato'));
        $this->assertTrue(IndiaAggregatorSync::isIndiaProvider('swiggy'));
        $this->assertFalse(IndiaAggregatorSync::isIndiaProvider('keeta'));
        $this->assertFalse(IndiaAggregatorSync::isIndiaProvider(null));
        $this->assertSame('aggregator_zomato', IndiaAggregatorSync::featureKey('zomato'));
        $this->assertSame('aggregator_swiggy', IndiaAggregatorSync::featureKey('swiggy'));
        $this->assertNull(IndiaAggregatorSync::featureKey('keeta'));
    }

    public function test_prep_minutes_is_clamped_to_a_sane_window(): void
    {
        $zomato = new ZomatoProvider();

        $this->assertSame(20, $zomato->prepMinutes(new AggregatorConnection()));
        $this->assertSame(45, $zomato->prepMinutes(new AggregatorConnection(['settings' => ['prep_minutes' => 45]])));
        $this->assertSame(120, $zomato->prepMinutes(new AggregatorConnection(['settings' => ['prep_minutes' => 9999]])));
        $this->assertSame(1, $zomato->prepMinutes(new AggregatorConnection(['settings' => ['prep_minutes' => -5]])));
    }

    public function test_a_freshly_connected_outlet_reads_as_open(): void
    {
        // An ABSENT settings key must not read as "closed" and hide a live store.
        $this->assertTrue(IndiaAggregatorSync::isOutletOnline(new AggregatorConnection()));
        $this->assertFalse(IndiaAggregatorSync::isOutletOnline(
            new AggregatorConnection(['settings' => ['outlet_online' => false]])
        ));
    }

    private function orderWith(string $status): Order
    {
        $order = new Order();
        $order->status = $status;

        return $order;
    }
}
