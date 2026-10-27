<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Content\Services\Studio\StudioFitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3-1: fit plan — AI path sanitized, provider-down path deterministic.
 */
class StudioFitServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function draft(): array
    {
        return ['categories' => [
            ['name' => 'Mains', 'items' => [
                ['name' => 'Kofta', 'description' => str_repeat('slow-cooked lamb with saffron rice and toasted almonds ', 4), 'price' => 52],
                ['name' => 'Sea bass', 'description' => 'Grilled, herb butter', 'price' => 64],
            ]],
        ]];
    }

    protected function manifest(): array
    {
        return ['density' => ['desc_max_chars' => 60]];
    }

    protected function mockAi(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(AiService::class);
        $this->app->instance(AiService::class, $mock);

        return $mock;
    }

    public function test_ai_plan_is_sanitized(): void
    {
        $this->mockAi()->shouldReceive('json')->once()->andReturn([
            'trims' => [
                ['path' => '0.0', 'display' => 'Slow-cooked lamb, saffron rice, almonds'],
                ['path' => '9.9', 'display' => 'bogus path dropped'],
                ['path' => '0.1', 'display' => str_repeat('way too long ', 30)],
                ['path' => 'x.y', 'display' => 'bad shape dropped'],
            ],
            'featured' => ['0.1', '5.5', '0.0'],
            'accent' => '#8b2635',
        ]);

        $plan = app(StudioFitService::class)->fit($this->draft(), $this->manifest());

        $this->assertSame('ai', $plan['source']);
        $this->assertCount(1, $plan['trims']);
        $this->assertSame('0.0', $plan['trims'][0]['path']);
        $this->assertSame(['0.1', '0.0'], $plan['featured']); // bogus 5.5 dropped
        $this->assertSame('#8b2635', $plan['accent']);
    }

    public function test_invalid_accent_rejected(): void
    {
        $this->mockAi()->shouldReceive('json')->once()->andReturn([
            'trims' => [], 'featured' => [], 'accent' => 'javascript:alert(1)',
        ]);

        $this->assertNull(app(StudioFitService::class)->fit($this->draft(), $this->manifest())['accent']);
    }

    public function test_ai_skipping_trims_gets_deterministic_backfill(): void
    {
        // Observed live: model returns featured/accent but zero trims. The
        // fallback clamp must cover every over-long description it skipped.
        $this->mockAi()->shouldReceive('json')->once()->andReturn([
            'trims' => [], 'featured' => ['0.0'], 'accent' => '#C41E3A',
        ]);

        $plan = app(StudioFitService::class)->fit($this->draft(), $this->manifest());

        $this->assertSame('ai', $plan['source']);
        $this->assertCount(1, $plan['trims']);
        $this->assertSame('0.0', $plan['trims'][0]['path']);
        $this->assertStringEndsWith('…', $plan['trims'][0]['display']);
    }

    public function test_provider_down_falls_back_deterministically(): void
    {
        $this->mockAi()->shouldReceive('json')->once()
            ->andThrow(new AiProviderException('all providers down', 'mimo', false));

        $plan = app(StudioFitService::class)->fit($this->draft(), $this->manifest());

        $this->assertSame('fallback', $plan['source']);
        $this->assertCount(1, $plan['trims']); // only the over-long description
        $this->assertSame('0.0', $plan['trims'][0]['path']);
        $this->assertLessThanOrEqual(61, mb_strlen($plan['trims'][0]['display'])); // 60 + ellipsis
        $this->assertStringEndsWith('…', $plan['trims'][0]['display']);
        $this->assertSame([], $plan['featured']);
        $this->assertNull($plan['accent']);
    }

    public function test_short_descriptions_never_trimmed_in_fallback(): void
    {
        $plan = app(StudioFitService::class)->fallback(
            ['categories' => [['name' => 'A', 'items' => [['name' => 'X', 'description' => 'short', 'price' => 1]]]]],
            60
        );

        $this->assertSame([], $plan['trims']);
    }
}
