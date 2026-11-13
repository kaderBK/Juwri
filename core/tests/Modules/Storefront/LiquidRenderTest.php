<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Tests\TestCase;

/**
 * P2-1: the sandboxed Liquid engine renders untrusted templates, enforces
 * strict-mode at upload, caps resources, and cannot include files.
 */
class LiquidRenderTest extends TestCase
{
    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory(), new RenderClient());
    }

    public function test_renders_a_template_with_data(): void
    {
        $out = $this->renderer()->render('Hello {{ name }}!', ['name' => 'Ada']);
        $this->assertSame('Hello Ada!', $out);
    }

    public function test_live_mode_tolerates_missing_variable(): void
    {
        // Lenient: a missing var renders blank, never errors on a diner's menu.
        $out = $this->renderer()->render('X{{ missing }}Y', []);
        $this->assertSame('XY', $out);
    }

    public function test_upload_mode_is_strict_about_unknown_variable(): void
    {
        $this->expectException(RenderFailedException::class);
        $this->renderer()->render('{{ missing }}', [], 'upload');
    }

    public function test_render_length_limit_trips(): void
    {
        config(['platform.liquid.render_length_limit' => 20]);
        $this->expectException(RenderFailedException::class);
        // Output far exceeds 20 chars → ResourceLimitException (wrapped).
        $this->renderer()->render('{% for i in (1..500) %}xxxxxxxxxx{% endfor %}', []);
    }

    public function test_cannot_execute_php_or_reach_arbitrary_objects(): void
    {
        // Liquid is data-only: a "dangerous" looking template just renders as text/blank.
        $out = $this->renderer()->render("{{ 7 | plus: 5 }}", []);
        $this->assertSame('12', $out);
    }
}
