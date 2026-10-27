<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Marketing\Models\Subscriber;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\PlatformSetting;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * Platform E2E · C6 — the admin core contract: dashboard, subscribers list,
 * settings form, and settings POST (with diff-safety gate: no schema mutations).
 *
 * This test discriminates the HTTP contract for admin core routes:
 * - GET /admin/dashboard (200, no auth errors)
 * - GET /admin/subscriber (200, subscriber list renders)
 * - GET /admin/settings (200, settings form renders)
 * - POST /admin/settings with general_setting=1 (200, OPTIONS table updated,
 *   schema unchanged, only intended fields modified)
 *
 * Because these are screen-based journeys, we test them through the controller
 * via authenticated HTTP requests, not Blade-direct.
 */
class PlatformC6AdminCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        // Admin layout reads $admin_languages/$notifications from the admin view
        // composers, which ViewServiceProvider only registers on an admin URL at
        // boot — not guaranteed in the test app. Share them like the composers do.
        $this->shareLayoutGlobals([], true);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Admin dashboard GET returns 200 and renders content.
     * This is the first screen the admin sees after login.
     */
    public function test_admin_dashboard_get_returns_200(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin)
            ->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewIs('admin.dashboard.index');
    }

    /**
     * Admin subscribers list GET returns 200 and renders the subscriber list.
     */
    public function test_admin_subscribers_list_returns_200(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin)
            ->get('/admin/subscriber');

        $response->assertStatus(200);
        $response->assertViewIs('admin.subscriber.index');
        // Verify the view has access to the subscribers collection
        $response->assertViewHas('subscribers');
    }

    /**
     * Admin settings GET returns 200 and renders the settings form.
     */
    public function test_admin_settings_get_returns_200(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin)
            ->get('/admin/settings');

        $response->assertStatus(200);
        $response->assertViewIs('admin.settings.index');
    }

    /**
     * Admin settings POST with general_setting=1 updates the OPTIONS table.
     * The form submission is an AJAX request that receives JSON.
     */
    public function test_admin_settings_post_updates_options(): void
    {
        $admin = $this->superadmin();
        $newTitle = 'E2E Test Title '.time();

        // Capture state before POST
        $before = PlatformSettings::legacyMap(false);

        // POST the settings change
        $response = $this->actingAs($admin)
            ->postJson('/admin/settings', [
                'general_setting' => '1',
                'site_title' => $newTitle,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Verify the change was applied
        $updated = PlatformSettings::stored('site_title');
        $this->assertSame($newTitle, $updated);
    }

    /**
     * Settings POST with general_setting=1 does NOT mutate the schema.
     * This is the mandatory diff-safety gate: before/after row counts and
     * structure must match, with only intended option_value fields changed.
     */
    public function test_admin_settings_post_schema_safety(): void
    {
        $admin = $this->superadmin();
        $newTitle = 'E2E Test Title '.time();

        // Capture the full options table state before POST
        $beforeRows = PlatformSetting::query()
            ->orderBy('id')
            ->get(['name as option_name', 'value as option_value'])
            ->toArray();
        $beforeMap = collect($beforeRows)->keyBy('option_name')->toArray();
        $beforeCount = count($beforeRows);

        // POST the settings change
        $this->actingAs($admin)
            ->postJson('/admin/settings', [
                'general_setting' => '1',
                'site_title' => $newTitle,
            ]);

        // Capture the full options table state after POST
        $afterRows = PlatformSetting::query()
            ->orderBy('id')
            ->get(['name as option_name', 'value as option_value'])
            ->toArray();
        $afterMap = collect($afterRows)->keyBy('option_name')->toArray();
        $afterCount = count($afterRows);

        // Assert schema safety: same row count (no columns added/removed)
        $this->assertSame($beforeCount, $afterCount,
            "OPTIONS table row count changed: before=$beforeCount, after=$afterCount");

        // Assert only site_title was changed; all other options remain identical
        $changedOptions = [];
        foreach ($beforeMap as $name => $beforeRow) {
            $afterRow = $afterMap[$name] ?? null;
            $this->assertNotNull($afterRow,
                "Option '$name' missing after POST (schema mutation?)");

            if ($beforeRow['option_value'] !== $afterRow['option_value']) {
                $changedOptions[] = $name;
            }
        }

        $this->assertCount(1, $changedOptions,
            'Expected exactly 1 option changed, got: '.implode(', ', $changedOptions));
        $this->assertSame('site_title', $changedOptions[0],
            'Only site_title should have changed');
    }

    /**
     * Subscriber can be created via the admin form.
     * This tests the SubscriberController::store POST contract.
     */
    public function test_admin_can_create_subscriber(): void
    {
        $admin = $this->superadmin();
        $email = 'e2e-test-'.time().'@local.invalid';

        $response = $this->actingAs($admin)
            ->postJson('/admin/subscriber', [
                'email' => $email,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => $email,
        ]);
    }

    /**
     * Subscriber email must be unique.
     * Attempting to create a duplicate returns a validation error.
     */
    public function test_admin_subscriber_email_unique_validation(): void
    {
        $admin = $this->superadmin();
        $email = 'e2e-dup-'.time().'@local.invalid';

        // First creation succeeds
        Subscriber::create(['email' => $email, 'joined' => now()]);

        // Second attempt with same email fails
        $response = $this->actingAs($admin)
            ->postJson('/admin/subscriber', [
                'email' => $email,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => false]);
        // Verify no duplicate was created
        $this->assertSame(1, Subscriber::where('email', $email)->count());
    }

    /**
     * Subscribers can be listed (the admin.subscriber.index contract).
     */
    public function test_admin_subscribers_can_be_listed(): void
    {
        $admin = $this->superadmin();
        $sub1 = Subscriber::create(['email' => 'e2e-s1-'.time().'@local.invalid', 'joined' => now()]);
        $sub2 = Subscriber::create(['email' => 'e2e-s2-'.time().'@local.invalid', 'joined' => now()]);

        $response = $this->actingAs($admin)
            ->get('/admin/subscriber');

        $response->assertStatus(200);
        $subscribers = $response->viewData('subscribers');
        $this->assertNotNull($subscribers);
        $this->assertTrue($subscribers->contains('id', $sub1->id));
        $this->assertTrue($subscribers->contains('id', $sub2->id));
    }

    /**
     * Non-admin users cannot access admin routes.
     * Regular users should be redirected or receive a 403.
     */
    public function test_non_admin_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'user_type' => 'user',
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get('/admin/dashboard');

        // Should be forbidden or redirected
        $this->assertTrue($response->status() === 403 || $response->status() === 302);
    }

    /**
     * Unauthenticated requests to admin routes are redirected to login.
     */
    public function test_unauthenticated_redirected_to_login(): void
    {
        $response = $this->get('/admin/dashboard');

        $response->assertRedirect('/login');
    }
}
