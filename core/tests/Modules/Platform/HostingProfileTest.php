<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Support\HostingProfile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/** P6-123 (J40): HOSTING_PROFILE defaults, env override, docker = pre-profile behaviour. */
class HostingProfileTest extends TestCase
{
    private const KEYS = [
        'HOSTING_PROFILE', 'QUEUE_CONNECTION', 'CACHE_DRIVER', 'SESSION_DRIVER', 'SESSION_BLOCK_STORE',
        'BROADCAST_CONNECTION', 'BROADCAST_DRIVER', 'BACKUP_DUMP_ENGINE', 'QUEUE_HEAVY_MAX_TIME',
        'QUEUE_WORKER_MODE', 'SCHEDULER_MODE',
    ];

    /**
     * Evaluate the config files with exactly $env set (every other profile-related key unset).
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function configWith(array $env): array
    {
        $saved = [];
        foreach (self::KEYS as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        foreach ($env as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
        try {
            $queue = require config_path('queue.php');
            $cache = require config_path('cache.php');
            $session = require config_path('session.php');
            $broadcasting = require config_path('broadcasting.php');
            $backup = require config_path('backup.php');
            $hosting = require config_path('hosting.php');

            return [
                'queue' => $queue['default'],
                'cache' => $cache['default'],
                'session' => $session['driver'],
                'lock_store' => $session['block_store'],
                'broadcast' => $broadcasting['default'],
                'dump_engine' => $backup['dump_engine'],
                'heavy_max_time' => $hosting['heavy_max_time'],
                'worker_mode' => $hosting['worker_mode'],
                'scheduler_mode' => $hosting['scheduler_mode'],
                'profile' => $hosting['profile'],
            ];
        } finally {
            foreach ($saved as $key => [$e, $s, $g]) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                if ($e !== null) {
                    $_ENV[$key] = $e;
                }
                if ($s !== null) {
                    $_SERVER[$key] = $s;
                }
                if ($g !== false) {
                    putenv("{$key}={$g}");
                }
            }
        }
    }

    public function test_docker_and_unset_reproduce_the_config_fallbacks_before_profiles(): void
    {
        // The literal env() fallbacks in config/*.php before P6-123, and the Kernel heavy cap (1800).
        $before = [
            'queue' => 'sync', 'cache' => 'file', 'session' => 'file', 'lock_store' => null, 'broadcast' => 'null',
            'dump_engine' => 'auto', 'heavy_max_time' => 1800,
        ];
        foreach ([[], ['HOSTING_PROFILE' => 'docker'], ['HOSTING_PROFILE' => 'bogus']] as $env) {
            $config = $this->configWith($env);
            $this->assertSame('docker', $config['profile']);
            $this->assertSame($before, array_intersect_key($config, $before), json_encode($env) ?: '');
        }
    }

    public function test_profile_defaults_matrix(): void
    {
        $expected = [
            'shared' => ['queue' => 'database', 'cache' => 'file', 'session' => 'file', 'lock_store' => null, 'broadcast' => 'null', 'dump_engine' => 'php', 'heavy_max_time' => 600, 'worker_mode' => 'cron', 'scheduler_mode' => 'cron'],
            'cpanel-vps' => ['queue' => 'database', 'cache' => 'file', 'session' => 'file', 'lock_store' => null, 'broadcast' => 'null', 'dump_engine' => 'auto', 'heavy_max_time' => 1800, 'worker_mode' => 'supervisor', 'scheduler_mode' => 'cron'],
            'docker' => ['queue' => 'sync', 'cache' => 'file', 'session' => 'file', 'lock_store' => null, 'broadcast' => 'null', 'dump_engine' => 'auto', 'heavy_max_time' => 1800, 'worker_mode' => 'container', 'scheduler_mode' => 'container'],
        ];
        foreach ($expected as $profile => $values) {
            $config = $this->configWith(['HOSTING_PROFILE' => $profile]);
            $this->assertSame($profile, $config['profile']);
            $this->assertSame($values, array_intersect_key($config, $values), $profile);
        }
        $this->assertFalse(HostingProfile::default('redis', 'shared'));
        $this->assertSame('optional', HostingProfile::default('redis', 'cpanel-vps'));
    }

    public function test_explicit_env_values_win_over_profile_defaults(): void
    {
        $config = $this->configWith([
            'HOSTING_PROFILE' => 'shared',
            'QUEUE_CONNECTION' => 'redis', 'CACHE_DRIVER' => 'redis', 'SESSION_DRIVER' => 'database',
            'SESSION_BLOCK_STORE' => 'database', 'BROADCAST_CONNECTION' => 'reverb', 'BACKUP_DUMP_ENGINE' => 'native',
            'QUEUE_HEAVY_MAX_TIME' => '900', 'QUEUE_WORKER_MODE' => 'supervisor', 'SCHEDULER_MODE' => 'container',
        ]);
        $this->assertSame([
            'queue' => 'redis', 'cache' => 'redis', 'session' => 'database', 'lock_store' => 'database',
            'broadcast' => 'reverb', 'dump_engine' => 'native', 'heavy_max_time' => 900,
            'worker_mode' => 'supervisor', 'scheduler_mode' => 'container', 'profile' => 'shared',
        ], $config);
    }

    private function heavyDrainCommand(): string
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, '--queue=heavy --stop-when-empty'));
        $this->assertCount(1, $events);

        return (string) $events->first()->command;
    }

    public function test_kernel_heavy_drain_uses_the_profile_cap_below_retry_after(): void
    {
        $this->refreshSchedule();
        $this->assertStringContainsString('--max-time=1800', $this->heavyDrainCommand());

        config(['hosting.heavy_max_time' => 600]);
        $this->refreshSchedule();
        $this->assertStringContainsString('--max-time=600', $this->heavyDrainCommand());

        config(['hosting.heavy_max_time' => 99999]);
        $this->refreshSchedule();
        $this->assertStringContainsString('--max-time=1800', $this->heavyDrainCommand(), 'capped at retry_after - 60');
    }

    private function defaultDrainCommand(): string
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'queue:work --stop-when-empty --max-time='));
        $this->assertCount(1, $events);

        return (string) $events->first()->command;
    }

    /** P6-119c: every cron drain (default AND heavy) carries a per-profile max-time. */
    public function test_kernel_default_drain_uses_the_profile_cap_below_retry_after(): void
    {
        $this->refreshSchedule();
        $this->assertStringContainsString('--max-time=50', $this->defaultDrainCommand());

        foreach (['shared' => 45, 'cpanel-vps' => 50, 'docker' => 50] as $profile => $cap) {
            $this->assertSame($cap, HostingProfile::default('default_max_time', $profile), $profile);
        }

        config(['hosting.default_max_time' => 45]);
        $this->refreshSchedule();
        $this->assertStringContainsString('--max-time=45', $this->defaultDrainCommand());

        config(['hosting.default_max_time' => 99999, 'queue.default' => 'database', 'queue.connections.database.retry_after' => 150]);
        $this->refreshSchedule();
        $this->assertStringContainsString('--max-time=120', $this->defaultDrainCommand(), 'capped at retry_after - 30');

        config(['hosting.default_max_time' => 1]);
        $this->refreshSchedule();
        $this->assertStringContainsString('--max-time=10', $this->defaultDrainCommand(), 'floor of 10 s');
    }

    public function test_every_cron_queue_drain_has_a_max_time(): void
    {
        foreach (['shared', 'cpanel-vps', 'docker'] as $profile) {
            config(['hosting.default_max_time' => HostingProfile::default('default_max_time', $profile), 'hosting.heavy_max_time' => HostingProfile::default('heavy_max_time', $profile)]);
            $this->refreshSchedule();
            $drains = collect($this->app->make(Schedule::class)->events())
                ->filter(fn ($e) => str_contains((string) $e->command, 'queue:work'));
            $this->assertCount(2, $drains, 'default + heavy are the only queues jobs are dispatched to');
            foreach ($drains as $e) {
                $this->assertMatchesRegularExpression('/--stop-when-empty --max-time=\d+/', (string) $e->command, $profile.': '.$e->command);
            }
        }
    }

    private function refreshSchedule(): void
    {
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        $kernel = $this->app->make(Kernel::class);
        (fn () => $this->schedule($schedule))->call($kernel);
    }
}
