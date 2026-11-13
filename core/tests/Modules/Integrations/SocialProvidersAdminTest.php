<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Services\Social\SocialCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FEATURES-5 Track A (A2.2 / A2.3) — superadmin platform social keys page +
 * effective-enable interplay with owner disable.
 */
class SocialProvidersAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1]) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
            'admin_languages' => collect(), 'notifications' => collect(),
            'totalUnreadNotifications' => 0, 'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
        $this->admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->admin)->get(route('admin.social-providers.index'))
            ->assertOk()
            ->assertSee(___('Platform Social Login'))
            ->assertSee('Google')
            ->assertSee('Apple');
    }

    public function test_saving_platform_google_keys_creates_active_row(): void
    {
        $this->actingAs($this->admin)->post(route('admin.social-providers.update'), [
            'provider' => 'google',
            'active' => '1',
            'secrets' => ['client_id' => 'plat-id', 'client_secret' => 'plat-secret'],
        ])->assertRedirect();

        $row = Credential::whereNull('store_id')->where('feature', 'social')->where('provider', 'google')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->active);
        $this->assertSame('plat-id', $row->secrets['client_id']);

        // Effective-enable is true for any restaurant with platform keys active.
        $this->assertTrue(app(SocialCredentialResolver::class)->isEnabled('google'));
    }

    public function test_unknown_provider_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.social-providers.update'), [
            'provider' => 'twitter',
            'secrets' => ['client_id' => 'x'],
        ])->assertNotFound();
    }

    public function test_apple_test_endpoint_validates_shape(): void
    {
        $res = $this->actingAs($this->admin)->postJson(route('admin.social-providers.test'), [
            'provider' => 'apple',
            'secrets' => ['client_id' => 'x'],
        ]);
        $res->assertOk()->assertJson(['success' => false]);
    }

    public function test_platform_inactive_disables_even_with_keys(): void
    {
        Credential::create([
            'store_id' => null, 'feature' => 'social', 'provider' => 'facebook',
            'secrets' => ['client_id' => 'a', 'client_secret' => 'b'], 'active' => false,
        ]);

        $this->assertFalse(app(SocialCredentialResolver::class)->isEnabled('facebook'));
    }
}
