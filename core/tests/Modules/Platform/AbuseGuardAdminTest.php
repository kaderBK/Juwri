<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\AbuseEvent;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * Abuse Guard P1 (1.3/1.4/1.5): admin page shell + Rate Limits tab persistence
 * + per-guard mode select + 24h event badge.
 */
class AbuseGuardAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

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
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
            'admin_languages' => collect(), 'notifications' => collect(),
            'totalUnreadNotifications' => 0, 'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_resource_usage_shows_tenant_plan_identity_share_and_mtd(): void
    {
        $tenant = User::factory()->create(['name' => 'Garden Coffee QA', 'group_id' => 'free', 'status' => 1]);

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        TenantUsageDaily::create(['date' => $today, 'user_id' => $tenant->id, 'metric' => 'requests_public', 'value' => 8000]);
        // A second in-month day so MTD is provably > today (skipped on the 1st, when
        // month-start IS today and the row would collide on the unique key).
        $expectedMtd = 8000;
        if ($monthStart !== $today) {
            TenantUsageDaily::create(['date' => $monthStart, 'user_id' => $tenant->id, 'metric' => 'requests_public', 'value' => 40]);
            $expectedMtd = 8040;
        }

        $res = $this->actingAs($this->admin)->get(route('admin.abuse-guard.index', ['tab' => 'resource-usage']));
        $res->assertOk();
        $res->assertSee('Garden Coffee QA');                 // identity, not a bare id
        $res->assertSeeHtml('#'.$tenant->id);
        $res->assertSee('MTD');                              // column header
        $res->assertSee('Share');                            // % of platform column
        $res->assertSee('100%');                             // sole tenant → 100% share today
        $res->assertSee(number_format($expectedMtd));        // month-to-date total
    }

    public function test_page_renders_with_limiter_rows(): void
    {
        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index'))
            ->assertOk()
            ->assertSee(___('Abuse Guard'))
            ->assertSee('Rate Limits')
            ->assertSee('ai')
            ->assertSee('webhook_in');
    }

    public function test_rate_limits_persist(): void
    {
        $this->actingAs($this->admin)->post(route('admin.abuse-guard.rate-limits'), [
            'ai_mode' => 'enforce',
            'ai_per_min' => 25,
            'ai_per_hour' => 250,
        ])->assertRedirect();

        $this->assertSame('enforce', PlatformSettings::stored('abuse_ai_mode'));
        $this->assertSame('25', PlatformSettings::stored('abuse_rl_ai_per_min'));
        $this->assertSame('250', PlatformSettings::stored('abuse_rl_ai_per_hour'));
    }

    public function test_invalid_mode_falls_back_to_warn(): void
    {
        $this->actingAs($this->admin)->post(route('admin.abuse-guard.rate-limits'), [
            'ai_mode' => 'bogus',
        ])->assertRedirect();

        $this->assertSame('warn', PlatformSettings::stored('abuse_ai_mode'));
    }

    public function test_ai_spend_tab_renders_and_persists(): void
    {
        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index', ['tab' => 'ai-spend']))
            ->assertOk()->assertSee(___('Denial-of-wallet caps'));

        $this->actingAs($this->admin)->post(route('admin.abuse-guard.ai-spend'), [
            'ai_spend_mode' => 'enforce',
            'user_daily_cap' => 42,
            'anomaly_multiplier' => 8.5,
        ])->assertRedirect();

        $this->assertSame('enforce', PlatformSettings::stored('abuse_ai_spend_mode'));
        $this->assertSame('42', PlatformSettings::stored('abuse_ai_spend_user_daily_cap'));
    }

    public function test_signup_tab_renders_and_persists(): void
    {
        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index', ['tab' => 'signup']))
            ->assertOk()->assertSee('Signup guard');

        $this->actingAs($this->admin)->post(route('admin.abuse-guard.signup'), [
            'signup_mode' => 'enforce',
            'velocity_per_day' => 5,
            'turnstile_site_key' => 'siteX',
            'turnstile_secret_key' => 'secretX',
        ])->assertRedirect();

        $this->assertSame('enforce', PlatformSettings::stored('abuse_signup_mode'));
        $this->assertSame('siteX', PlatformSettings::stored('abuse_turnstile_site_key'));
    }

    public function test_queue_tab_renders_and_persists(): void
    {
        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index', ['tab' => 'queue']))->assertOk()->assertSeeHtml('Queue &amp; uploads');

        $this->actingAs($this->admin)->post(route('admin.abuse-guard.queue'), [
            'queue_mode' => 'enforce',
            'upload_mode' => 'warn',
            'storage_mode' => 'warn',
            'queue_tenant_pending_cap' => 7,
            'upload_max_mb' => 12,
        ])->assertRedirect();

        $this->assertSame('enforce', PlatformSettings::stored('abuse_queue_mode'));
        $this->assertSame('7', PlatformSettings::stored('abuse_queue_tenant_pending_cap'));
        $this->assertSame('12', PlatformSettings::stored('abuse_upload_max_mb'));
    }

    public function test_events_tab_lists_events(): void
    {
        AbuseEvent::create(['guard' => 'signup', 'action' => 'disposable_email', 'mode' => 'warn', 'ip' => '1.2.3.4', 'created_at' => now()]);

        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index', ['tab' => 'events']))
            ->assertOk()->assertSee(___('Abuse events'))->assertSee('disposable_email');
    }

    public function test_resource_usage_tab_renders(): void
    {
        TenantUsageDaily::bump(5, 'requests_public', 42);

        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index', ['tab' => 'resource-usage']))
            ->assertOk()->assertSee('Top tenants')->assertSee('requests_public');
    }

    public function test_branch_messaging_and_webhook_fields_persist(): void
    {
        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index'))
            ->assertOk()->assertSee('Branch ceilings');

        $this->actingAs($this->admin)->post(route('admin.abuse-guard.rate-limits'), [
            'branch_mode' => 'enforce',
            'branch_orders_per_min' => 150,
            'messaging_mode' => 'enforce',
            'messaging_tenant_daily_cap' => 2500,
            'webhook_in_max_kb' => 128,
        ])->assertRedirect();

        $this->assertSame('enforce', PlatformSettings::stored('abuse_branch_mode'));
        $this->assertSame('150', PlatformSettings::stored('abuse_branch_orders_per_min'));
        $this->assertSame('2500', PlatformSettings::stored('abuse_messaging_tenant_daily_cap'));
        $this->assertSame('128', PlatformSettings::stored('abuse_rl_webhook_in_max_kb'));
    }

    public function test_alert_thresholds_persist(): void
    {
        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index', ['tab' => 'events']))
            ->assertOk()->assertSee('Alert thresholds');

        $this->actingAs($this->admin)->post(route('admin.abuse-guard.alerts'), [
            'signups_per_hour' => 45,
            'heavy_tenant_pct' => 12.5,
            'retention_days' => 30,
        ])->assertRedirect();

        $this->assertSame('45', PlatformSettings::stored('abuse_alert_signups_per_hour'));
        $this->assertSame('12.5', PlatformSettings::stored('abuse_heavy_tenant_pct'));
        $this->assertSame('30', PlatformSettings::stored('abuse_events_retention_days'));
    }

    public function test_event_badge_counts_render(): void
    {
        AbuseEvent::create(['guard' => 'ai', 'action' => 'generate', 'mode' => 'warn', 'created_at' => now()]);

        $this->actingAs($this->admin)->get(route('admin.abuse-guard.index'))
            ->assertOk()
            ->assertSee(___('24h events'));
    }
}
