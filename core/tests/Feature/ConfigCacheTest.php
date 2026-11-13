<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * O9 — make the app safe under config:cache. The env() strays (THEME_NAME,
 * APP_VERSION, APP_INSTALLED) are now captured into config, so they survive
 * config:cache instead of returning null and blanking the storefront.
 */
class ConfigCacheTest extends TestCase
{
    public function test_config_cache_safe_keys_are_wired(): void
    {
        // These resolve from the compiled config (not a live env() read).
        $this->assertNotNull(config('app.theme'), 'app.theme must resolve');
        $this->assertArrayHasKey('version', config('app'));
        $this->assertIsBool((bool) config('app.installed'));
    }

    public function test_active_theme_reads_config_theme(): void
    {
        session()->forget('template');
        config(['app.theme' => 'crave']);

        $this->assertSame('templates.crave.', active_theme());
        $this->assertSame('assets/templates/crave/', active_theme(true));
        $this->assertSame('crave', active_theme_name());
    }

    public function test_session_template_still_overrides_config(): void
    {
        config(['app.theme' => 'crave']);
        session(['template' => 'glow']);

        $this->assertSame('templates.glow.', active_theme());
        $this->assertSame('glow', active_theme_name());
    }

    /**
     * P6: the remaining runtime env() strays are now config-backed, so they
     * survive config:cache. Overriding the config value (not $_ENV) proves the
     * read goes through config(), which is what the compiled cache serves.
     */
    public function test_admin_url_reads_config_not_env(): void
    {
        config(['app.admin' => 'controlroom']);
        $this->assertSame('controlroom', admin_url());
    }

    public function test_demo_mode_reads_config_not_env(): void
    {
        // No authed user (id 1 short-circuits to false), so the config flag decides.
        config(['app.demo_mode' => true]);
        $this->assertTrue(demo_mode());

        config(['app.demo_mode' => false]);
        $this->assertFalse(demo_mode());
    }

    public function test_p6_config_keys_are_wired(): void
    {
        foreach (['app.admin', 'app.demo_mode', 'app.default_language', 'sms.provider'] as $key) {
            $this->assertTrue(app('config')->has($key), "{$key} must be a compiled config key");
        }
    }

    public function test_console_boot_uses_configured_locales_without_querying_the_database(): void
    {
        $configuredLocales = config('laravellocalization.supportedLocales');

        config([
            'app.installed' => true,
            'settings.include_language_code' => true,
            'database.default' => 'unreachable',
        ]);

        new AppServiceProvider(app())->boot();

        $this->assertSame(
            $configuredLocales,
            config('laravellocalization.supportedLocales'),
        );
    }

    /**
     * Guard: the swept hot-path sources must not reintroduce a runtime env()
     * read (which returns null once config is cached).
     */
    public function test_swept_sources_have_no_runtime_env_reads(): void
    {
        $base = dirname(__DIR__, 2);
        $checks = [
            '/app/Modules/Messaging/Services/SmsService.php' => "env('",
            '/app/Http/Controllers/Admin/LanguageController.php' => "env('DEFAULT_LANGUAGE')",
            '/app/Modules/Content/Http/Controllers/Admin/FaqController.php' => "env('DEFAULT_LANGUAGE')",
            '/app/Modules/Content/Http/Controllers/Admin/PageController.php' => "env('DEFAULT_LANGUAGE')",
        ];
        foreach ($checks as $rel => $needle) {
            $src = file_get_contents($base.$rel);
            $this->assertStringNotContainsString($needle, $src, "{$rel} still reads {$needle}");
        }

        // Helper::admin_url / demo_mode must be config-backed.
        $helper = file_get_contents($base.'/app/Http/Helpers/Helper.php');
        $this->assertStringNotContainsString("env('APP_ADMIN')", $helper);
        $this->assertStringNotContainsString("env('DEMO_MODE')", $helper);
    }
}
