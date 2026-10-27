<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SI11 (#75): studio funnel analytics from session status.
 */
class StudioAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $status): void
    {
        StudioSession::create(['guest_token' => Str::random(48), 'status' => $status, 'expires_at' => now()->addHours(48)]);
    }

    public function test_funnel_counts_and_conversion(): void
    {
        foreach (['exploring', 'exploring', 'exploring'] as $s) {
            $this->mk($s);
        }
        $this->mk('extracted');
        $this->mk('designed');
        $this->mk('published');

        $funnel = new StudioAnalyticsService()->funnel();

        $this->assertSame(6, $funnel['total_sessions']);
        $this->assertSame(3, $funnel['by_status']['exploring']);
        $this->assertSame(3, $funnel['reached_extract']);   // extracted + designed + published
        $this->assertSame(1, $funnel['converted']);         // published
        $this->assertEqualsWithDelta(16.7, $funnel['conversion_rate'], 0.1);
    }

    public function test_funnel_empty_is_zero(): void
    {
        $funnel = new StudioAnalyticsService()->funnel();
        $this->assertSame(0, $funnel['total_sessions']);
        $this->assertSame(0.0, $funnel['conversion_rate']);
    }

    public function test_health_reports_providers_and_fallback_rate(): void
    {
        config(['studio.gemini.api_key' => 'k', 'ai.default' => 'mimo']);
        \Juwri\Modules\Ai\Models\AiUsage::create(['provider' => 'mimo', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'fell_back' => true]);
        \Juwri\Modules\Ai\Models\AiUsage::create(['provider' => 'mimo', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'fell_back' => false]);
        \Juwri\Modules\Ai\Models\AiUsage::create(['provider' => 'mimo', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'fell_back' => false]);

        $health = new StudioAnalyticsService()->health();

        $this->assertSame('mimo', $health['extraction_default']);
        $this->assertTrue($health['image_provider_configured']);
        $this->assertSame(3, $health['ai_calls']);
        $this->assertSame(1, $health['fell_back']);
        $this->assertEqualsWithDelta(33.3, $health['fallback_rate'], 0.1);
    }
}
