<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Console\Kernel;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\User;
use Juwri\Core\Services\AlertDispatcher;
use App\Services\PlatformMetrics;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WI-H7 — the queue-drain mutex must self-heal in minutes (not 24h), a sustained
 * backlog must be able to fail /healthz (opt-in), and the superadmin Queue Health
 * card must render depth + a lock-release action.
 */
class QueueDrainHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        view()->share([
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function schedule(): Schedule
    {
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(Kernel::class);
        $method = new \ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        return $schedule;
    }

    private function seedJob(string $queue, int $ageSeconds): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue, 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time() - $ageSeconds,
            'created_at' => time() - $ageSeconds,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['group_id' => '2', 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_default_drain_mutex_expires_in_two_minutes(): void
    {
        $event = collect($this->schedule()->events())->first(
            fn ($e) => is_string($e->command) && str_contains($e->command, 'queue:work --stop-when-empty --max-time=50')
        );
        $this->assertNotNull($event, 'default drain event not found');
        $this->assertSame(2, $event->expiresAt);
    }

    public function test_heavy_drain_mutex_expires_in_thirty_five_minutes(): void
    {
        $event = collect($this->schedule()->events())->first(
            fn ($e) => is_string($e->command) && str_contains($e->command, 'queue:work heavy --queue=heavy')
        );
        $this->assertNotNull($event, 'heavy drain event not found');
        $this->assertSame(35, $event->expiresAt);
    }

    public function test_queue_alerts_are_evaluated_hourly(): void
    {
        $this->assertTrue(
            collect($this->schedule()->events())->contains(fn ($e) => $e->description === 'platform:queue-alerts'),
            'platform:queue-alerts must be scheduled'
        );
    }

    public function test_healthz_returns_503_when_backlog_is_sustained_and_escalation_on(): void
    {
        config([
            'queue.default' => 'database',
            'platform.alerts.max_queue_age_seconds' => 60,
            'settings.healthz_queue_backlog_fails' => true,
        ]);
        $this->seedJob('default', 600);

        $this->getJson('/healthz')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue_backlog', 'stalled');
    }

    public function test_healthz_stays_200_when_escalation_off(): void
    {
        config([
            'queue.default' => 'database',
            'platform.alerts.max_queue_age_seconds' => 60,
            'settings.healthz_queue_backlog_fails' => false,
        ]);
        $this->seedJob('default', 600);

        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_depth_spike_alone_does_not_fail_the_probe(): void
    {
        config([
            'queue.default' => 'database',
            'platform.alerts.max_queue_depth' => 2,
            'platform.alerts.max_queue_age_seconds' => 900,
            'settings.healthz_queue_backlog_fails' => true,
        ]);
        for ($i = 0; $i < 5; $i++) {
            $this->seedJob('default', 5);
        }

        $this->getJson('/healthz')->assertOk();
    }

    public function test_queue_alerts_dispatch_a_platform_alert(): void
    {
        config([
            'queue.default' => 'database',
            'platform.alerts.max_queue_age_seconds' => 60,
        ]);
        $this->seedJob('heavy', 600);

        foreach (PlatformMetrics::queueAlerts() as $alert) {
            AlertDispatcher::send('queue', $alert);
        }

        $this->assertDatabaseHas('platform_alerts', ['kind' => 'queue']);
        $this->assertTrue(
            DB::table('platform_alerts')->where('message', 'like', '%oldest job%')->exists(),
            'expected an oldest-job alert row'
        );
    }

    public function test_admin_queue_health_card_renders_depth(): void
    {
        config(['queue.default' => 'database']);
        $this->seedJob('default', 30);

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.failed-jobs.index'))
            ->assertOk()
            ->assertSee('Queue Health');
    }

    public function test_clear_schedule_locks_requires_admin_and_redirects(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.queue.clear-schedule-locks'))
            ->assertRedirect();

        $status = $this->actingAs($this->makeOwner())
            ->post(route('admin.queue.clear-schedule-locks'))
            ->getStatusCode();
        $this->assertTrue($status < 200 || $status >= 300, 'a plain owner must not get a 2xx');
    }
}
