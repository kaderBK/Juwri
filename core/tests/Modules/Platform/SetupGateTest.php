<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Controllers\InstallController;
use App\Http\Middleware\Installed;
use App\Http\Middleware\NotInstalled;
use App\Support\PlatformSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WI-02: there is no web installer. Setup is `.env` + `php artisan migrate --seed`;
 * the `installed` gate only checks that the app has an application key and a
 * migrated database (or APP_INSTALLED=true), and otherwise sends visitors to a
 * plain setup notice.
 */
class SetupGateTest extends TestCase
{
    use RefreshDatabase;

    /** Drop the per-boot probe result so the next call re-checks. */
    private function reprobe(): void
    {
        $this->app->forgetInstance('setup.complete');
    }

    public function test_the_config_flag_marks_the_app_as_set_up(): void
    {
        config(['app.installed' => true]);

        $this->assertTrue(app_is_installed());
    }

    public function test_a_migrated_database_with_an_app_key_counts_as_set_up_without_the_flag(): void
    {
        config(['app.installed' => false]);
        $this->reprobe();

        $this->assertTrue(app_is_installed());
    }

    public function test_a_missing_app_key_is_not_set_up(): void
    {
        config(['app.installed' => false, 'app.key' => '']);
        $this->reprobe();

        $this->assertFalse(app_is_installed());
    }

    public function test_an_unmigrated_database_is_not_set_up(): void
    {
        config(['app.installed' => false]);
        Schema::drop('platform_settings');
        $this->reprobe();

        $this->assertFalse(app_is_installed());
    }

    public function test_the_probe_result_is_kept_for_the_application_instance(): void
    {
        config(['app.installed' => false]);
        $this->app->instance('setup.complete', false);

        // Tables exist, but the boot-time answer stands until the next boot.
        $this->assertFalse(app_is_installed());
    }

    public function test_the_gate_sends_requests_to_the_setup_notice_until_set_up(): void
    {
        config(['app.installed' => false]);
        $this->app->instance('setup.complete', false);

        $response = (new Installed)->handle(Request::create('/dashboard', 'GET'), fn () => response('reached-app'));

        $this->assertTrue($response->isRedirect(route('setup.pending')));
    }

    public function test_the_gate_passes_through_once_set_up(): void
    {
        config(['app.installed' => true]);

        $response = (new Installed)->handle(Request::create('/dashboard', 'GET'), fn () => response('reached-app'));

        $this->assertSame('reached-app', $response->getContent());
    }

    public function test_the_setup_notice_explains_the_cli_setup(): void
    {
        config(['app.installed' => false]);
        $this->app->instance('setup.complete', false);

        $this->get('/setup')
            ->assertStatus(503)
            ->assertSee('php artisan migrate --seed')
            ->assertSee('php artisan storage:link')
            ->assertSee('copy core/storage/app/public to public/storage');
    }

    public function test_the_setup_notice_forwards_home_once_set_up(): void
    {
        config(['app.installed' => true]);

        $this->get('/setup')->assertRedirect(route('home'));
    }

    public function test_the_web_installer_is_gone(): void
    {
        foreach (['install', 'install.requirements', 'install.permissions', 'install.database', 'install.update_database', 'install.admin', 'install.complete'] as $name) {
            $this->assertFalse(Route::has($name), "route {$name} must not exist");
        }
        $this->assertFalse(class_exists(InstallController::class));
        $this->assertFalse(class_exists(NotInstalled::class));
        $this->assertFileDoesNotExist(resource_path('views/install'));
        $this->assertFileDoesNotExist(dirname(base_path()).'/assets/installer');
    }

    public function test_no_purchase_code_setting_remains(): void
    {
        $this->assertFalse(PlatformSecrets::isSecret('purchase_key'));
        $this->assertFalse(PlatformSecrets::isSecret('quickorder_purchase_key'));
        $this->assertFileDoesNotExist(resource_path('views/admin/settings/purchase-code.blade.php'));
        $this->assertStringNotContainsString('purchase', file_get_contents(resource_path('views/admin/settings/index.blade.php')));
    }
}
