<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-17: Laravel Pulse for self-hosters — off in the suite, superadmin-only dashboard, only the cheap sampled
 * recorders, pulse_* are platform tables, nothing but a user id reaches the dashboard.
 */
class PulseTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 9])]);

        return User::factory()->create(array_merge(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()], $attrs));
    }

    public function test_pulse_is_off_in_the_suite_and_by_default(): void
    {
        $this->assertFalse((bool) config('pulse.enabled'));
        $this->assertStringContainsString("env('PULSE_ENABLED', false)", (string) file_get_contents(config_path('pulse.php')));
    }

    public function test_only_the_cheap_recorders_are_configured_and_the_heavy_ones_are_sampled(): void
    {
        $recorders = config('pulse.recorders');
        $this->assertEqualsCanonicalizing([
            Recorders\CacheInteractions::class, Recorders\Exceptions::class, Recorders\Queues::class,
            Recorders\SlowQueries::class, Recorders\SlowRequests::class,
        ], array_keys($recorders));
        $this->assertLessThan(1, $recorders[Recorders\CacheInteractions::class]['sample_rate']);
        $this->assertLessThan(1, $recorders[Recorders\Queues::class]['sample_rate']);
        $this->assertFalse($recorders[Recorders\SlowQueries::class]['location']);
    }

    public function test_cache_keys_are_reduced_to_their_prefix(): void
    {
        $groups = config('pulse.recorders.'.Recorders\CacheInteractions::class.'.groups');
        $group = function (string $key) use ($groups): string {
            foreach ($groups as $pattern => $replacement) {
                if (preg_match($pattern, $key)) {
                    return preg_replace($pattern, $replacement, $key);
                }
            }

            return $key;
        };

        $this->assertSame('login:*', $group('login:jane@example.com|203.0.113.9'));
        $this->assertSame('rate:*', $group("rate|+962791234567\nx"));
        $this->assertSame('job-exceptions:*', $group('job-exceptions:12'));
    }

    public function test_view_pulse_is_for_superadmins_only(): void
    {
        $this->assertTrue(Gate::forUser($this->user(['user_type' => 'admin']))->allows('viewPulse'));
        $this->assertFalse(Gate::forUser($this->user())->allows('viewPulse'));
        $this->assertFalse(Gate::forUser(null)->allows('viewPulse'));
    }

    public function test_dashboard_route_needs_the_admin_guard_and_the_gate(): void
    {
        $this->assertContains('admin', config('pulse.middleware'));
        $this->assertContains('auth', config('pulse.middleware'));

        $this->get('/pulse')->assertRedirect();
        $this->actingAs($this->user())->get('/pulse')->assertRedirect(route('login'));

        $html = (string) $this->actingAs($this->user(['user_type' => 'admin']))->get('/pulse')->assertOk()->getContent();
        $this->assertStringNotContainsString('pulse-servers', $html);
    }

    public function test_pulse_tables_exist_and_are_platform_tables_never_touched_by_store_data(): void
    {
        $tables = StoreDataRegistry::tables();
        foreach (['pulse_aggregates', 'pulse_entries', 'pulse_values'] as $name) {
            $this->assertTrue(Schema::hasTable($name), $name);
            $this->assertSame('platform', $tables[$name]->kind, $name);
            $this->assertFalse(Schema::hasColumn($name, 'store_id') || Schema::hasColumn($name, 'account_id'), $name);
        }
    }

    public function test_the_dashboard_only_ever_shows_a_user_id(): void
    {
        $user = $this->user(['name' => 'Jane Secret', 'email' => 'jane.secret@example.com']);

        $resolved = app(Pulse::class)->resolveUsers(collect([$user->id]))->find($user->id);

        $this->assertSame('#'.$user->id, $resolved->name);
        $this->assertSame('', $resolved->avatar);
        $this->assertStringNotContainsString('jane', json_encode($resolved));
    }

    public function test_route_list_leaves_the_middleware_groups_in_place_for_later_requests(): void
    {
        $before = app('router')->getMiddlewareGroups();
        $this->assertArrayHasKey('web', $before);
        $this->assertArrayHasKey('pulse', $before);

        $this->assertSame(0, Artisan::call('route:list', ['--path' => 'pulse', '--json' => true]));

        $this->assertSame($before, app('router')->getMiddlewareGroups());
    }
}
