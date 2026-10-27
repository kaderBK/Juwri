<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * Security S2 — the maintenance endpoints were unauthenticated, unthrottled and
 * unsigned. GET /clear-cache (runs optimize:clear) and GET /cronjob (runs
 * schedule:run) let anyone drop caches or force scheduler runs. /clear-cache is
 * now superadmin-only; /cronjob requires CRON_TOKEN (P6-119f: header or POST
 * body; ?token= only when allowed), is throttled, locked, and 404s when unconfigured.
 */
class MaintenanceEndpointAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic throttle assertions: clear the rate-limiter cache.
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    public function test_clear_cache_redirects_guest_to_login(): void
    {
        $this->get('/clear-cache')->assertRedirect(route('login'));
    }

    public function test_clear_cache_allows_superadmin(): void
    {
        Artisan::shouldReceive('call')->andReturn(0);

        $this->actingAs($this->admin())
            ->get('/clear-cache')
            ->assertRedirect(route('home'));
    }

    public function test_cronjob_404s_when_unconfigured(): void
    {
        config(['cron.token' => '']);

        $this->get('/cronjob')->assertNotFound();
    }

    public function test_cronjob_404s_without_token(): void
    {
        config(['cron.token' => 's3cret-token']);
        Artisan::shouldReceive('call')->never();

        $this->get('/cronjob')->assertNotFound();
        $this->post('/cronjob')->assertNotFound();
    }

    public function test_cronjob_404s_on_wrong_header_token(): void
    {
        config(['cron.token' => 's3cret-token']);
        Artisan::shouldReceive('call')->never();

        $this->get('/cronjob', ['X-Juwri-Cron-Token' => 'wrong'])->assertNotFound();
    }

    public function test_cronjob_runs_with_header_token(): void
    {
        config(['cron.token' => 's3cret-token']);
        Artisan::shouldReceive('call')->with('schedule:run')->once()->andReturn(0);

        $this->get('/cronjob', ['X-Juwri-Cron-Token' => 's3cret-token'])
            ->assertOk()
            ->assertJson(['status' => 200])
            ->assertDontSee('s3cret-token');
    }

    public function test_cronjob_runs_with_post_body_token_without_csrf(): void
    {
        config(['cron.token' => 's3cret-token']);
        Artisan::shouldReceive('call')->with('schedule:run')->once()->andReturn(0);

        $this->post('/cronjob', ['token' => 's3cret-token'])->assertOk();
    }

    public function test_cronjob_rejects_query_token_by_default(): void
    {
        config(['cron.token' => 's3cret-token', 'cron.allow_query_token' => false]);
        Artisan::shouldReceive('call')->never();

        $this->get('/cronjob?token=s3cret-token')->assertNotFound();
        // A POST does not read the token from its query string either.
        $this->post('/cronjob?token=s3cret-token')->assertNotFound();
    }

    public function test_cronjob_query_token_works_only_when_allowed_and_logs_without_secret(): void
    {
        config(['cron.token' => 's3cret-token', 'cron.allow_query_token' => true]);
        Artisan::shouldReceive('call')->with('schedule:run')->once()->andReturn(0);
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $this->get('/cronjob?token=s3cret-token')->assertOk();

        $this->assertNotEmpty(array_filter($logged, fn ($l) => str_contains($l, 'cronjob.query_token_deprecated')));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('s3cret-token', $line);
        }
    }

    public function test_cronjob_failure_never_leaks_secret(): void
    {
        config(['cron.token' => 's3cret-token']);
        Artisan::shouldReceive('call')->andThrow(new \RuntimeException('boom s3cret-token'));
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $this->get('/cronjob', ['X-Juwri-Cron-Token' => 's3cret-token'])
            ->assertStatus(500)
            ->assertDontSee('s3cret-token');

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('s3cret-token', $line);
        }
    }

    public function test_cronjob_overlap_lock_returns_409(): void
    {
        config(['cron.token' => 's3cret-token']);
        Artisan::shouldReceive('call')->never();

        $held = Cache::lock('core:cronjob:web', 60);
        $this->assertTrue($held->get());

        try {
            $this->get('/cronjob', ['X-Juwri-Cron-Token' => 's3cret-token'])->assertStatus(409);
        } finally {
            $held->release();
        }
    }

    public function test_cronjob_releases_lock_after_run(): void
    {
        config(['cron.token' => 's3cret-token']);
        Artisan::shouldReceive('call')->with('schedule:run')->twice()->andReturn(0);

        $this->get('/cronjob', ['X-Juwri-Cron-Token' => 's3cret-token'])->assertOk();
        $this->get('/cronjob', ['X-Juwri-Cron-Token' => 's3cret-token'])->assertOk();
    }

    public function test_cronjob_404s_on_wrong_token(): void
    {
        config(['cron.token' => 's3cret-token']);

        $this->get('/cronjob?token=wrong')->assertNotFound();
    }

    public function test_cronjob_is_throttled_to_six_per_minute(): void
    {
        config(['cron.token' => 's3cret-token']);

        for ($i = 0; $i < 6; $i++) {
            $this->get('/cronjob', ['X-Juwri-Cron-Token' => 'wrong'])->assertNotFound();
        }

        $this->get('/cronjob', ['X-Juwri-Cron-Token' => 'wrong'])->assertStatus(429);
    }
}
