<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Tests\TestCase;

/**
 * N3 — the orders-list poll must not bypass the shared sound engine.
 *
 * It used to `new Audio('assets/global/audio/message.mp3')` directly, dodging
 * NotifySounds' per-device mute / volume / quiet-hours. It now routes through
 * NotifySounds.play('order_new'); the page-local toggle stays as an opt-in gate.
 */
class OrdersListNotifySoundTest extends TestCase
{
    private function ordersIndex(): string
    {
        return file_get_contents(
            resource_path('views/owner/orders/index.blade.php')
        );
    }

    public function test_orders_list_no_longer_uses_raw_audio(): void
    {
        $blade = $this->ordersIndex();
        $this->assertStringNotContainsString('new Audio(', $blade);
        $this->assertStringNotContainsString('message.mp3', $blade);
    }

    public function test_orders_list_routes_the_new_order_chime_through_notify_sounds(): void
    {
        $blade = $this->ordersIndex();
        $this->assertStringContainsString("NotifySounds.play('order_new')", $blade);
        // Page-local toggle preserved as an explicit gate on top of the engine.
        $this->assertStringContainsString('prefs.sound', $blade);
    }
}
