<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Modules\Ordering\Enums\OrderStatus;
use App\Models\Order;
use Tests\TestCase;

/**
 * R1a — the OrderStatus enum is the single source of the status vocabulary, and reads
 * are tolerant of legacy out-of-enum strings (no hard Eloquent cast).
 */
class OrderStatusEnumTest extends TestCase
{
    public function test_every_value_round_trips(): void
    {
        foreach (OrderStatus::values() as $v) {
            $this->assertSame($v, OrderStatus::from($v)->value);
        }
    }

    public function test_settable_excludes_the_system_only_pending_approval(): void
    {
        $this->assertNotContains(OrderStatus::PendingApproval->value, OrderStatus::settable());
        $this->assertContains(OrderStatus::CallBill->value, OrderStatus::settable());
        $this->assertCount(7, OrderStatus::settable());
    }

    public function test_labels_and_terminal(): void
    {
        $this->assertSame('Call bill', OrderStatus::CallBill->label());
        $this->assertArrayHasKey('completed', OrderStatus::labels());
        $this->assertTrue(OrderStatus::Completed->isTerminal());
        $this->assertFalse(OrderStatus::Preparing->isTerminal());
    }

    public function test_order_const_aliases_the_enum(): void
    {
        $this->assertSame(OrderStatus::PendingApproval->value, Order::STATUS_PENDING_APPROVAL);
    }

    public function test_status_enum_accessor_is_tolerant(): void
    {
        $order = new Order();
        $order->status = 'preparing';
        $this->assertSame(OrderStatus::Preparing, $order->statusEnum());

        // A legacy / out-of-enum string must not throw — it reads as null.
        $order->status = 'some_legacy_status';
        $this->assertNull($order->statusEnum());
    }
}
