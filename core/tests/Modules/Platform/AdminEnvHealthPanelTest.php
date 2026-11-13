<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\User;
use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WI-H8 — the superadmin System Info panel surfaces .env health (KEY NAMES + a
 * verdict, never a value), a non-superadmin cannot reach it, and the
 * admin_env_health_alert toggle actually gates the platform alert (C1 dead-toggle
 * fence: the Option leg and the config() read leg must agree).
 */
class AdminEnvHealthPanelTest extends TestCase
{
    use RefreshDatabase;

    private string $envFile;

    private ?string $originalPath = null;

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

        $this->originalPath = $this->app->environmentPath();
    }

    protected function tearDown(): void
    {
        if (isset($this->envFile)) {
            @unlink($this->envFile);
        }
        if ($this->originalPath) {
            $this->app->useEnvironmentPath($this->originalPath);
        }
        parent::tearDown();
    }

    private function useEnv(string $contents): void
    {
        $this->envFile = sys_get_temp_dir().'/envpanel-'.uniqid().'.env';
        file_put_contents($this->envFile, $contents);
        $this->app->useEnvironmentPath(dirname($this->envFile));
        $this->app->loadEnvironmentFrom(basename($this->envFile));
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['group_id' => '2', 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_system_info_reports_no_issues_on_a_clean_env(): void
    {
        $this->useEnv("APP_KEY=\"base64:CLEAN=\"\nPUSHER_APP_KEY=\n");

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee(___('No issues found'));
    }

    public function test_it_flags_app_key_leaked_into_pusher_app_key(): void
    {
        $leak = 'base64:LEAKEDKEY0123456789=';
        $this->useEnv("APP_KEY=\"{$leak}\"\nPUSHER_APP_KEY=\"{$leak}\"\n");

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('PUSHER_APP_KEY')
            ->assertSee(___('Holds a copy of APP_KEY'));
    }

    public function test_the_panel_never_prints_an_env_value(): void
    {
        $leak = 'base64:SECRETLEAKVALUE987=';
        $this->useEnv("APP_KEY=\"{$leak}\"\nPUSHER_APP_KEY=\"{$leak}\"\nDB_PASSWORD=\"topsecretpw\"\n");

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertDontSee($leak)
            ->assertDontSee('topsecretpw');
    }

    public function test_a_non_superadmin_cannot_reach_the_panel(): void
    {
        $status = $this->actingAs($this->makeOwner())
            ->get(route('admin.settings.index'))
            ->getStatusCode();

        $this->assertTrue($status < 200 || $status >= 300, 'a store owner must not get a 2xx on the admin settings page');
    }

    public function test_the_alert_toggle_gates_the_env_alert(): void
    {
        $leak = 'base64:LEAKEDKEY0123456789=';
        $this->useEnv("APP_KEY=\"{$leak}\"\nPUSHER_APP_KEY=\"{$leak}\"\n");

        config(['settings.admin_env_health_alert' => false]);
        $off = PlatformMetrics::evaluateAlerts();
        $this->assertEmpty(array_filter($off, fn ($a) => str_contains($a, 'env file:')), 'alert must be suppressed when the toggle is off');

        config(['settings.admin_env_health_alert' => true]);
        $on = PlatformMetrics::evaluateAlerts();
        $this->assertNotEmpty(array_filter($on, fn ($a) => str_contains($a, 'env file:')), 'alert must fire when the toggle is on');
    }
}
