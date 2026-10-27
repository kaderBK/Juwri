<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\AbuseEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Abuse Guard P2 (2.6): the ratelimit middleware — enforce 429s with Retry-After,
 * warn logs an abuse_event but lets the request through, off is a no-op.
 */
class AbuseRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('abuse:public_write:60:ip127.0.0.1');
        RateLimiter::clear('abuse:public_write:3600:ip127.0.0.1');

        Route::post('/_t/pw', fn () => response('ok'))
            ->middleware(['ratelimit:public-write'])->name('_t.pw');
    }

    private function fire(): TestResponse
    {
        return $this->post('/_t/pw', [], ['REMOTE_ADDR' => '127.0.0.1']);
    }

    public function test_enforce_blocks_after_limit_with_retry_after(): void
    {
        config([
            'settings.abuse_public_write_mode' => 'enforce',
            'settings.abuse_rl_public_write_per_min' => 2,
            'settings.abuse_rl_public_write_per_hour' => 100,
        ]);

        $this->fire()->assertOk();
        $this->fire()->assertOk();
        $blocked = $this->fire();
        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertSame(1, AbuseEvent::where('guard', 'public_write')->count());
    }

    public function test_warn_logs_but_allows(): void
    {
        config([
            'settings.abuse_public_write_mode' => 'warn',
            'settings.abuse_rl_public_write_per_min' => 1,
            'settings.abuse_rl_public_write_per_hour' => 100,
        ]);

        $this->fire()->assertOk();
        $this->fire()->assertOk(); // over limit but warn → allowed
        $this->assertGreaterThanOrEqual(1, AbuseEvent::where('guard', 'public_write')->count());
    }

    public function test_dashboard_poll_guard_enforces_and_warns(): void
    {
        RateLimiter::clear('abuse:dashboard_poll:60:ip127.0.0.1');
        RateLimiter::clear('abuse:dashboard_poll:3600:ip127.0.0.1');
        Route::get('/_t/poll', fn () => response('ok'))->middleware('ratelimit:dashboard_poll')->name('_t.poll');

        // enforce: blocks past the per-minute cap (the owner's opt-in state)
        config([
            'settings.abuse_dashboard_poll_mode' => 'enforce',
            'settings.abuse_rl_dashboard_poll_per_min' => 2,
            'settings.abuse_rl_dashboard_poll_per_hour' => 100,
        ]);
        $this->get('/_t/poll')->assertOk();
        $this->get('/_t/poll')->assertOk();
        $this->get('/_t/poll')->assertStatus(429);

        // warn (the shipped default): over the cap but allowed, just logged
        RateLimiter::clear('abuse:dashboard_poll:60:ip127.0.0.1');
        config([
            'settings.abuse_dashboard_poll_mode' => 'warn',
            'settings.abuse_rl_dashboard_poll_per_min' => 1,
        ]);
        $this->get('/_t/poll')->assertOk();
        $this->get('/_t/poll')->assertOk(); // over limit, warn → allowed
        $this->assertGreaterThanOrEqual(1, AbuseEvent::where('guard', 'dashboard_poll')->count());
    }

    public function test_webhook_payload_cap_blocks_oversized_in_enforce(): void
    {
        config(['settings.abuse_webhook_in_mode' => 'enforce', 'settings.abuse_rl_webhook_in_max_kb' => 1]);
        RateLimiter::clear('abuse:webhook_in:60:whip127.0.0.1');

        Route::post('/_t/wh', fn () => 'ok')->middleware('ratelimit:webhook-in')->name('_t.wh');

        $this->call('POST', '/_t/wh', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], str_repeat('x', 2048))
            ->assertStatus(413);
    }

    public function test_webhook_small_payload_passes(): void
    {
        config(['settings.abuse_webhook_in_mode' => 'enforce', 'settings.abuse_rl_webhook_in_max_kb' => 64]);

        Route::post('/_t/wh2', fn () => 'ok')->middleware('ratelimit:webhook-in')->name('_t.wh2');

        $this->call('POST', '/_t/wh2', [], [], [], ['REMOTE_ADDR' => '10.0.0.9'], 'small')
            ->assertOk();
    }

    public function test_off_is_noop(): void
    {
        config([
            'settings.abuse_public_write_mode' => 'off',
            'settings.abuse_rl_public_write_per_min' => 1,
        ]);

        $this->fire()->assertOk();
        $this->fire()->assertOk();
        $this->assertSame(0, AbuseEvent::count());
    }
}
