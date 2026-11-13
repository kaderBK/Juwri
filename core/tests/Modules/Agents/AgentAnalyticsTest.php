<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Services\Agents\AgentAnalyticsService;
use Tests\TestCase;

/**
 * Agents AO1 — conversation → order conversion analytics: correct rates, per-channel
 * breakdown, scoped to the restaurant.
 */
class AgentAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function convo(int $storeId, string $channel, ?int $orderId): void
    {
        AgentConversation::create([
            'store_id' => $storeId, 'channel' => $channel, 'session_key' => 'sk'.uniqid(),
            'order_id' => $orderId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_conversion_summary_math_and_channels(): void
    {
        // restaurant 1: 3 web (1 converted), 1 whatsapp (converted)
        $this->convo(1, 'web', null);
        $this->convo(1, 'web', 10);
        $this->convo(1, 'web', null);
        $this->convo(1, 'whatsapp', 20);
        // restaurant 2 (must be excluded)
        $this->convo(2, 'web', 30);

        $s = AgentAnalyticsService::conversionSummary(1);

        $this->assertSame(4, $s['conversations']);
        $this->assertSame(2, $s['orders']);
        $this->assertSame(50.0, $s['conversion']);
        $this->assertSame(33.3, $s['by_channel']['web']['conversion']);
        $this->assertSame(100.0, $s['by_channel']['whatsapp']['conversion']);
    }

    public function test_empty_range_is_zero_not_divide_by_zero(): void
    {
        $s = AgentAnalyticsService::conversionSummary(999);
        $this->assertSame(0, $s['conversations']);
        $this->assertSame(0.0, $s['conversion']);
    }
}
