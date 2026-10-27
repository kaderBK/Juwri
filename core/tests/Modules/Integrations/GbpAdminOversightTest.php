<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane A / A7 — superadmin GBP oversight page (admin guard, list, kill).
 */
class GbpAdminOversightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        // Production-shaped layout + admin-chrome context (stdClass via ViewServiceProvider),
        // not a hand-rolled double — keeps ViewComposerContractTest's cap intact. The
        // admin variant supplies admin_languages/notifications/counters; extra settings
        // keys the admin chrome reads are passed as options.
        $this->shareLayoutGlobals([
            'currency_sign'       => '$',
            'currency_pos'        => 1,
            'site_admin_logo'     => 'logo.png',
            'site_logo_footer'    => 'logo.png',
            'blog_enable'         => 0,
            'enable_contact_page' => 0,
            'enable_faqs'         => 0,
        ], true);
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function store(string $title): Store
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::random(5), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    public function test_admin_sees_connected_stores(): void
    {
        $s = $this->store('CraveBurgers');
        Credential::create([
            'store_id' => $s->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'location_id' => 'accounts/1/locations/2', 'gbp_menu_sync_enabled' => true],
        ]);

        $this->actingAs($this->makeAdmin())->get(route('admin.google-business.index'))
            ->assertOk()
            ->assertSee('CraveBurgers')
            ->assertSee('accounts/1/locations/2')
            ->assertSee('linked');
    }

    public function test_non_admin_blocked(): void
    {
        $s = $this->store('X');
        // Owner (non-admin) is redirected by the admin middleware.
        $res = $this->actingAs(User::find($s->user_id))->get(route('admin.google-business.index'));
        $this->assertContains($res->getStatusCode(), [301, 302, 403]);
        $this->assertNotSame(200, $res->getStatusCode());
    }

    public function test_toggle_kills_connection(): void
    {
        $s = $this->store('Y');
        $cred = Credential::create([
            'store_id' => $s->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'location_id' => 'L1'],
        ]);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.google-business.toggle', $cred->id))
            ->assertRedirect();

        $this->assertFalse((bool) $cred->fresh()->active);
        // Killed → Credential::for no longer resolves it (active=true filter).
        $this->assertNull(Credential::for('gbp', $s->id));

        // Toggling again re-enables.
        $this->actingAs($this->makeAdmin())->post(route('admin.google-business.toggle', $cred->id))->assertRedirect();
        $this->assertTrue((bool) $cred->fresh()->active);
    }
}
