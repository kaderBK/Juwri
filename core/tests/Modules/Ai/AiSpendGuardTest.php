<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use Juwri\Core\Models\AbuseEvent;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiLimitExceededException;
use Juwri\Modules\Ai\Services\AiSpendGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abuse Guard P3 (3.6): denial-of-wallet caps — per-user/platform daily, anomaly,
 * input length, BYOK bypass, warn-vs-enforce.
 */
class AiSpendGuardTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsage(int $userId, int $n, $when = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            AiUsage::create([
                'user_id' => $userId, 'provider' => 'openai', 'model' => 'gpt', 'feature' => 'general',
                'prompt_tokens' => 1, 'completion_tokens' => 1, 'fell_back' => false,
                'created_at' => $when ?? now(), 'updated_at' => $when ?? now(),
            ]);
        }
    }

    public function test_user_daily_cap_denies_in_enforce(): void
    {
        config(['settings.abuse_ai_spend_mode' => 'enforce', 'settings.abuse_ai_spend_user_daily_cap' => 3]);
        $user = User::factory()->create();
        $this->seedUsage($user->id, 3);

        $this->expectException(AiLimitExceededException::class);
        AiSpendGuard::assert($user->id, null, false);
    }

    public function test_user_daily_cap_warn_logs_and_allows(): void
    {
        config(['settings.abuse_ai_spend_mode' => 'warn', 'settings.abuse_ai_spend_user_daily_cap' => 3]);
        $user = User::factory()->create();
        $this->seedUsage($user->id, 3);

        AiSpendGuard::assert($user->id, null, false); // no throw
        $this->assertSame(1, AbuseEvent::where('guard', 'ai_spend')->where('action', 'user_daily_cap')->count());
    }

    public function test_byok_bypasses_caps(): void
    {
        config(['settings.abuse_ai_spend_mode' => 'enforce', 'settings.abuse_ai_spend_user_daily_cap' => 1]);
        $user = User::factory()->create();
        $this->seedUsage($user->id, 5);

        AiSpendGuard::assert($user->id, 999, true); // BYOK → no throw, no event
        $this->assertSame(0, AbuseEvent::count());
    }

    public function test_platform_kill_switch(): void
    {
        config(['settings.abuse_ai_spend_mode' => 'enforce', 'settings.abuse_ai_spend_platform_daily_cap' => 2, 'settings.abuse_ai_spend_user_daily_cap' => 0]);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->seedUsage($a->id, 1);
        $this->seedUsage($b->id, 1);

        $this->expectException(AiLimitExceededException::class);
        AiSpendGuard::assert($a->id, null, false);
    }

    public function test_anomaly_cutoff(): void
    {
        config([
            'settings.abuse_ai_spend_mode' => 'enforce',
            'settings.abuse_ai_spend_user_daily_cap' => 0,
            'settings.abuse_ai_spend_platform_daily_cap' => 0,
            'settings.abuse_ai_spend_anomaly_multiplier' => 10,
        ]);
        $user = User::factory()->create();
        // trailing 7 days: 7 calls yesterday → avg = 1/day; today 20 > 1*10
        $this->seedUsage($user->id, 7, now()->subDay());
        $this->seedUsage($user->id, 20);

        $this->expectException(AiLimitExceededException::class);
        AiSpendGuard::assert($user->id, null, false);
    }

    public function test_input_length_rejected(): void
    {
        config(['settings.abuse_ai_spend_mode' => 'enforce', 'settings.abuse_ai_spend_max_input_chars' => 10]);

        $this->expectException(AiLimitExceededException::class);
        AiSpendGuard::assertInputLength([['role' => 'user', 'content' => str_repeat('x', 50)]], null);
    }

    public function test_max_tokens_default(): void
    {
        $this->assertSame(2048, AiSpendGuard::maxTokens());
        config(['settings.abuse_ai_spend_max_tokens' => 512]);
        $this->assertSame(512, AiSpendGuard::maxTokens());
    }
}
