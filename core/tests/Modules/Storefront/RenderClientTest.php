<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Rendering\RenderBudget;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Tests\TestCase;

/**
 * P1-2/P1-3: the render seam runs untrusted output under a budget and throws
 * (never returns garbage) on breach, so the caller can fall back.
 */
class RenderClientTest extends TestCase
{
    public function test_renders_a_simple_closure(): void
    {
        $out = new RenderClient()->render(fn () => 'hello', new RenderBudget(100, 5));
        $this->assertSame('hello', $out);
    }

    public function test_output_over_budget_throws(): void
    {
        $this->expectException(RenderFailedException::class);
        new RenderClient()->render(fn () => str_repeat('x', 1001), new RenderBudget(1000, 5));
    }

    public function test_thrown_error_is_wrapped_as_render_failure(): void
    {
        $this->expectException(RenderFailedException::class);
        new RenderClient()->render(function () {
            throw new \LogicException('boom');
        }, new RenderBudget(100, 5));
    }

    public function test_wall_clock_budget_is_enforced_when_pcntl_available(): void
    {
        if (! function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl not available');
        }
        $this->expectException(RenderFailedException::class);
        new RenderClient()->render(function () {
            sleep(3);

            return 'never';
        }, new RenderBudget(100, 1));
    }

    public function test_strategy_degrades_to_inprocess_when_subprocess_unavailable(): void
    {
        config(['platform.render_strategy' => 'subprocess']);
        $client = new RenderClient();

        // If proc_open is unavailable (shared hosting), strategy must fall back.
        if (! RenderClient::procOpenAvailable()) {
            $this->assertSame('inprocess', $client->strategy());
        } else {
            $this->assertSame('subprocess', $client->strategy());
        }
    }

    public function test_default_strategy_is_inprocess(): void
    {
        config(['platform.render_strategy' => 'inprocess']);
        $this->assertSame('inprocess', new RenderClient()->strategy());
    }
}
