<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Database;

use App\Support\HostingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Support\ScheduleSlots;
use Tests\TestCase;

/**
 * P6-156: expired database sessions, cache rows and cache locks are pruned on every hosting profile — by Laravel's
 * session lottery on requests, and by the hourly `juwri:prune-db-stores` floor (lottery 0, unread cache keys).
 */
class PruneDatabaseStoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_sessions_cache_rows_and_locks_are_pruned_and_live_ones_kept(): void
    {
        config(['session.lifetime' => 120]);
        $now = now()->getTimestamp();
        DB::table('sessions')->insert([
            ['id' => 'old', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => 'x', 'last_activity' => $now - 121 * 60],
            ['id' => 'live', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => 'x', 'last_activity' => $now - 60],
        ]);
        DB::table('cache')->insert([
            ['key' => 'gone', 'value' => 'v', 'expiration' => $now - 5],
            ['key' => 'kept', 'value' => 'v', 'expiration' => $now + 600],
        ]);
        Cache::store('database')->forever('forever', 'v');
        DB::table('cache_locks')->insert([
            ['key' => 'lock-old', 'owner' => 'o', 'expiration' => $now - 5],
            ['key' => 'lock-live', 'owner' => 'o', 'expiration' => $now + 60],
        ]);

        $this->artisan('juwri:prune-db-stores')->assertSuccessful()
            ->expectsOutputToContain('Pruned 1 expired sessions, 1 expired cache rows, 1 expired cache locks.');

        $this->assertSame(['live'], DB::table('sessions')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['kept', (string) config('cache.prefix').'forever'], DB::table('cache')->pluck('key')->all());
        $this->assertSame(['lock-live'], DB::table('cache_locks')->pluck('key')->all());
    }

    public function test_command_is_a_no_op_when_the_tables_are_empty(): void
    {
        $this->artisan('juwri:prune-db-stores')->assertSuccessful()
            ->expectsOutputToContain('Pruned 0 expired sessions, 0 expired cache rows, 0 expired cache locks.');
    }

    public function test_the_prune_is_scheduled_hourly_independent_of_the_lottery(): void
    {
        $this->assertContains('juwri:prune-db-stores', ScheduleSlots::names('after:queue:prune-failed'));
        $this->artisan('schedule:list')->assertSuccessful()->expectsOutputToContain('juwri:prune-db-stores');
    }

    public function test_the_session_lottery_is_on_for_every_hosting_profile(): void
    {
        foreach (['shared', 'cpanel-vps', 'docker'] as $profile) {
            $_ENV['HOSTING_PROFILE'] = $_SERVER['HOSTING_PROFILE'] = $profile;
            try {
                $session = require base_path('config/session.php');
            } finally {
                unset($_ENV['HOSTING_PROFILE'], $_SERVER['HOSTING_PROFILE']);
            }
            [$hits, $of] = $session['lottery'];
            $this->assertGreaterThan(0, $hits, $profile);
            $this->assertGreaterThan(0, $of, $profile);
            $this->assertNotNull(HostingProfile::default('session_driver', $profile));
        }
    }
}
