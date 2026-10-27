<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Ordering module's store- and account-owned tables for the store-data registry — orders and carts follow the store; never copied.
 * P3-08: shipping zones / methods are copied with a duplicate; return requests follow the store.
 * P3-09: delivery slot rules, slot holds and slot seats follow the store (a rule may name a branch, so a duplicate
 * starts without slots until the owner sets them).
 */
final class OrderingStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('cart_lines'),
            StoreTable::data('carts'),
            StoreTable::data('catering_requests'),
            StoreTable::data('daily_tokens'),
            StoreTable::data('delivery_slot_rules'),
            StoreTable::data('meal_plans'),
            StoreTable::data('order_adjustments'),
            StoreTable::data('order_item_extras'),
            StoreTable::data('order_items'),
            StoreTable::data('order_voice_notes')->media(['filename' => ['local:voice-notes/{store_id}/']]),
            StoreTable::data('orders')->files(['pod_photo' => 'public']),
            // P3-08: returns follow the store; shipping zones and methods are store setup, copied by a duplicate (a pickup
            // branch is not copied: the copy's pickup methods name none until the owner picks one).
            StoreTable::data('return_requests'),
            StoreTable::data('slot_holds'),
            StoreTable::data('slot_seats'),
            StoreTable::catalog('shipping_methods')->remap(['shipping_zone_id' => '?shipping_zones'])->clear(['pickup_branch_id'])
                ->fresh(['public_id' => fn (): string => (string) \Illuminate\Support\Str::uuid7()]),
            StoreTable::catalog('shipping_zones')->fresh(['public_id' => fn (): string => (string) \Illuminate\Support\Str::uuid7()]),
        ];
    }
}
