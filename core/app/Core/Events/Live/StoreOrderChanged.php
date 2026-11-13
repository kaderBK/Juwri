<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Events\Live;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Juwri\Core\Broadcasting\LiveUpdates;

/**
 * Live push on the private channel store.{id}.orders and, when the order has a branch, store.{id}.branch.{bid}.orders.
 * Ids and status only, no customer data: the screen refetches the details through its normal endpoint. Holds scalars (never a model) so queueing it needs no tenant scope.
 */
final class StoreOrderChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $storeId, public int $orderId, public string $status, public bool $placed = false, public ?int $branchId = null) {}

    public function broadcastOn(): array
    {
        return LiveUpdates::channelsFor($this->storeId, $this->branchId, LiveUpdates::CHANNEL_ORDERS);
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    /** @return array<string,int|string|bool> */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status, 'placed' => $this->placed];
    }
}
