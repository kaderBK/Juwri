<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * C3 — the superadmin global kill-switch UI. The general-settings form persists
 * `external_redirect_global` through PlatformSettings::set (the cache-busting
 * write choke point). Resolver honouring of the flag is covered by A5's
 * ExternalRedirectKillSwitchTest (storefront renders the menu when off).
 */
class ExternalRedirectKillSwitchUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    public function test_settings_toggle_off_persists_the_global_option(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'general_setting' => '1',
            'site_title' => 'TestApp',
            'external_redirect_global' => '0',
        ])->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSame('0', (string) PlatformSettings::stored('external_redirect_global'));
    }

    public function test_settings_toggle_on_persists_the_global_option(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'general_setting' => '1',
            'site_title' => 'TestApp',
            'external_redirect_global' => '1',
        ])->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSame('1', (string) PlatformSettings::stored('external_redirect_global'));
    }
}
